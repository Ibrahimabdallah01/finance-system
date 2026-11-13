import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import PurchaseForm from '../components/PurchaseForm';
import SaleForm from '../components/SaleForm';
import ScheduleModal from '../components/ScheduleModal';
import { addMonths } from '../lib/dates';
import { fromCents, mulMoney, splitMoney, subMoney, sumMoney } from '../lib/money';
import { mockApi, renderApp, validationError } from '../test/utils';

vi.mock('../api/client');

const PRODUCTS = [
  { id: 1, name: 'iPhone 13', tracking: 'serialized', default_selling_price: '750000.00', stock: { quantity: 2 } },
  { id: 2, name: 'USB-C charger', tracking: 'quantity', default_selling_price: '5000.00', stock: { quantity: 10 } },
  { id: 3, name: 'Screen protector', tracking: 'direct', default_selling_price: null, stock: { quantity: 0 } },
];
const UNITS = [
  { id: 11, imei: '356938035640001', serial_number: null, storage: '128GB', color: 'Black', cost: '605000.00' },
  { id: 12, imei: '356938035640002', serial_number: null, storage: '256GB', color: 'Blue', cost: '700000.00' },
];
const CUSTOMERS = [{ id: 1, name: 'Asha', phone: '0712000000' }];
const saleRoutes = {
  'GET /api/products': () => ({ data: PRODUCTS }),
  'GET /api/customers': () => ({ data: CUSTOMERS }),
  'GET /api/inventory/units': () => ({ data: UNITS }),
};

const totals = () => within(screen.getByLabelText('Sale totals'));

describe('money helpers for form previews', () => {
  it('adds, subtracts and multiplies exactly', () => {
    expect(sumMoney(['0.10', '0.20', '0.30'])).toBe('0.60');
    expect(subMoney('750000', '200000.50')).toBe('549999.50');
    expect(mulMoney('2000', '3')).toBe('6000.00');
    expect(mulMoney('2000', 'abc')).toBe('0.00');
    expect(fromCents(-5n)).toBe('-0.05');
  });

  it('splits a debt into installments that add up exactly', () => {
    expect(splitMoney('300000', 3)).toEqual(['100000.00', '100000.00', '100000.00']);
    const parts = splitMoney('100000', 3);
    expect(parts).toEqual(['33333.33', '33333.33', '33333.34']);
    expect(sumMoney(parts)).toBe('100000.00');
    expect(splitMoney('x', 3)).toEqual([]);
  });

  it('adds months without running past the end of a short month', () => {
    expect(addMonths('2026-01-31', 1)).toBe('2026-02-28');
    expect(addMonths('2026-10-14', 3)).toBe('2027-01-14');
    expect(addMonths('2026-12-15', 1)).toBe('2027-01-15');
  });
});

describe('SaleForm', () => {
  const open = async (user) => {
    const api = mockApi({ ...saleRoutes, 'POST /api/sales': () => ({ data: { id: 1, sale_number: 'S-00001' } }) });
    renderApp(<SaleForm onClose={() => {}} onDone={() => {}} />);
    await screen.findByRole('dialog', { name: 'New sale' });
    await waitFor(() => expect(within(screen.getByLabelText(/Product/)).getAllByRole('option').length).toBeGreaterThan(1));
    return api;
  };

  const pickPhone = async (user) => {
    await user.selectOptions(screen.getByLabelText(/Product/), '1');
    await waitFor(() => expect(within(screen.getByLabelText(/Phone \(in stock\)/)).getAllByRole('option').length).toBe(3));
    await user.selectOptions(screen.getByLabelText(/Phone \(in stock\)/), '11');
  };

  it('fills in the usual price, lists the phones in stock and totals the sale as you go', async () => {
    const user = userEvent.setup();
    await open(user);
    await pickPhone(user);

    expect(screen.getByLabelText(/Selling price/)).toHaveValue('750000.00');
    const phones = within(screen.getByLabelText(/Phone \(in stock\)/)).getAllByRole('option').map((o) => o.textContent);
    expect(phones[1]).toContain('356938035640001');
    expect(phones[1]).toContain('cost TSh 605,000');

    expect(totals().getByText('Total after discount').nextSibling).toHaveTextContent('TSh 750,000');
    expect(totals().getByText('Customer will owe').nextSibling).toHaveTextContent('TSh 750,000');

    await userEvent.type(screen.getByLabelText(/Discount/), '50000');
    expect(totals().getByText('Total after discount').nextSibling).toHaveTextContent('TSh 700,000');
  });

  it('shows what is still owed when the customer pays part now', async () => {
    const user = userEvent.setup();
    await open(user);
    await pickPhone(user);

    await user.click(screen.getByRole('button', { name: /Add payment/ }));
    await user.selectOptions(screen.getByLabelText(/Received into/), '1');
    await user.type(screen.getByLabelText(/^Amount/), '200000');

    expect(totals().getByText('Received now').nextSibling).toHaveTextContent('TSh 200,000');
    expect(totals().getByText('Customer will owe').nextSibling).toHaveTextContent('TSh 550,000');
  });

  it('will not save an unpaid sale without a customer, and says why', async () => {
    const user = userEvent.setup();
    const api = await open(user);
    await pickPhone(user);

    await user.click(screen.getByRole('button', { name: 'Save sale' }));
    expect(screen.getByText('Choose a customer when the sale is not fully paid.')).toBeInTheDocument();
    expect(api.called('POST', '/api/sales')).toHaveLength(0);
  });

  it('sends a complete sale: phone, customer and deposit', async () => {
    const user = userEvent.setup();
    const api = await open(user);
    await pickPhone(user);

    await user.selectOptions(screen.getByLabelText(/Customer/), '1');
    await user.click(screen.getByRole('button', { name: /Add payment/ }));
    await user.selectOptions(screen.getByLabelText(/Received into/), '1');
    await user.type(screen.getByLabelText(/^Amount/), '200000');
    await user.click(screen.getByRole('button', { name: 'Save sale' }));

    await waitFor(() => expect(api.called('POST', '/api/sales')).toHaveLength(1));
    const body = api.last('POST', '/api/sales').body;
    expect(body).toMatchObject({ customer_id: '1', cost_account_id: null });
    expect(body.items).toEqual([{ product_id: '1', inventory_unit_id: '11', quantity: 1, unit_price: '750000.00', unit_cost: null }]);
    expect(body.payments).toEqual([{ account_id: '1', amount: '200000', payment_method: 'cash' }]);
  });

  it('lets a walk-in customer pay in full with one click', async () => {
    const user = userEvent.setup();
    const api = await open(user);
    await pickPhone(user);

    await user.click(screen.getByRole('button', { name: 'Customer paid in full' }));
    expect(totals().getByText('Customer will owe').nextSibling).toHaveTextContent('TSh 0');
    await user.click(screen.getByRole('button', { name: 'Save sale' }));

    await waitFor(() => expect(api.called('POST', '/api/sales')).toHaveLength(1));
    expect(api.last('POST', '/api/sales').body.payments[0].amount).toBe('750000.00');
  });

  it('refuses payments larger than the price', async () => {
    const user = userEvent.setup();
    const api = await open(user);
    await pickPhone(user);

    await user.click(screen.getByRole('button', { name: /Add payment/ }));
    await user.selectOptions(screen.getByLabelText(/Received into/), '1');
    await user.type(screen.getByLabelText(/^Amount/), '800000');
    await user.click(screen.getByRole('button', { name: 'Save sale' }));

    expect(screen.getByText(/are more than the total/)).toBeInTheDocument();
    expect(api.called('POST', '/api/sales')).toHaveLength(0);
  });

  it('does not offer a phone that is already on another line', async () => {
    const user = userEvent.setup();
    await open(user);
    await pickPhone(user);

    await user.click(screen.getByRole('button', { name: /Add another item/ }));
    const products = screen.getAllByLabelText(/Product/);
    await user.selectOptions(products[1], '1');
    await waitFor(() => expect(screen.getAllByLabelText(/Phone \(in stock\)/)).toHaveLength(2));

    const second = within(screen.getAllByLabelText(/Phone \(in stock\)/)[1]).getAllByRole('option').map((o) => o.textContent);
    expect(second.join(' ')).not.toContain('356938035640001');
    expect(second.join(' ')).toContain('356938035640002');
  });

  it('asks what a directly sold item cost and which account paid for it', async () => {
    const user = userEvent.setup();
    const api = await open(user);

    await user.selectOptions(screen.getByLabelText(/Product/), '3');
    await user.type(screen.getByLabelText(/Price each/), '6000');
    await user.type(screen.getByLabelText(/What it cost you/), '4000');
    await user.click(screen.getByRole('button', { name: 'Customer paid in full' }));
    await user.click(screen.getByRole('button', { name: 'Save sale' }));

    expect(screen.getByText('Choose the account that paid for the directly sold items.')).toBeInTheDocument();
    await user.selectOptions(screen.getByLabelText(/Account that paid for the directly sold items/), '1');
    await user.click(screen.getByRole('button', { name: 'Save sale' }));

    await waitFor(() => expect(api.called('POST', '/api/sales')).toHaveLength(1));
    expect(api.last('POST', '/api/sales').body).toMatchObject({ cost_account_id: '1' });
    expect(api.last('POST', '/api/sales').body.items[0]).toMatchObject({ unit_cost: '4000', quantity: 1 });
  });

  it('checks counted items against whole numbers and shows the server’s message for the right line', async () => {
    const user = userEvent.setup();
    mockApi({ ...saleRoutes, 'POST /api/sales': () => { throw validationError({ 'items.0.quantity': ['Only 10 in stock.'] }); } });
    renderApp(<SaleForm onClose={() => {}} onDone={() => {}} />);
    await waitFor(() => expect(within(screen.getByLabelText(/Product/)).getAllByRole('option').length).toBeGreaterThan(1));

    await user.selectOptions(screen.getByLabelText(/Product/), '2');
    await user.clear(screen.getByLabelText(/Quantity/));
    await user.type(screen.getByLabelText(/Quantity/), '2.5');
    await user.click(screen.getByRole('button', { name: 'Customer paid in full' }));
    await user.click(screen.getByRole('button', { name: 'Save sale' }));
    expect(screen.getByText('Enter a whole number of 1 or more.')).toBeInTheDocument();

    await user.clear(screen.getByLabelText(/Quantity/));
    await user.type(screen.getByLabelText(/Quantity/), '20');
    await user.selectOptions(screen.getByLabelText(/Customer/), '1'); // the rest will be owed, so a customer is needed
    await user.click(screen.getByRole('button', { name: 'Save sale' }));
    expect(await screen.findByText('Only 10 in stock.')).toBeInTheDocument();
  });
});

describe('PurchaseForm', () => {
  const routes = {
    'GET /api/products': () => ({ data: PRODUCTS }),
    'GET /api/suppliers': () => ({ data: [{ id: 1, name: 'Dubai Traders' }] }),
    'POST /api/inventory/purchases': () => ({ data: { quantity_added: 1, total_cost: '605000.00' }, meta: { warnings: [] } }),
  };

  const open = async () => {
    const api = mockApi(routes);
    renderApp(<PurchaseForm onClose={() => {}} onDone={() => {}} />);
    await screen.findByRole('dialog', { name: 'Buy stock' });
    await waitFor(() => expect(within(screen.getByLabelText(/^Product/)).getAllByRole('option').length).toBeGreaterThan(1));
    return api;
  };

  it('does not offer products that are sold directly', async () => {
    await open();
    const options = within(screen.getByLabelText(/^Product/)).getAllByRole('option').map((o) => o.textContent);
    expect(options.join(' ')).toContain('iPhone 13');
    expect(options.join(' ')).not.toContain('Screen protector');
  });

  it('shows that buying stock moves cash but is not an expense', async () => {
    const user = userEvent.setup();
    await open();

    await user.selectOptions(screen.getByLabelText(/^Product/), '1');
    await user.type(screen.getByLabelText(/^Purchase price/), '600000');
    await user.type(screen.getByLabelText(/Extra costs \(transport/), '5000');

    const summary = within(screen.getByLabelText('Purchase totals'));
    expect(summary.getByText('Stock value added').nextSibling).toHaveTextContent('TSh 605,000');
    expect(summary.getByText('Cash out now').nextSibling).toHaveTextContent('TSh 605,000');
    expect(screen.getByText(/This is not an expense/)).toBeInTheDocument();
  });

  it('only the extra costs are paid now on a credit purchase, and a supplier is required', async () => {
    const user = userEvent.setup();
    const api = await open();

    await user.selectOptions(screen.getByLabelText(/^Product/), '1');
    await user.type(screen.getByLabelText(/^Purchase price/), '600000');
    await user.type(screen.getByLabelText(/Extra costs \(transport/), '5000');
    await user.click(screen.getByLabelText(/On credit/));

    expect(within(screen.getByLabelText('Purchase totals')).getByText('Cash out now').nextSibling).toHaveTextContent('TSh 5,000');

    await user.click(screen.getByRole('button', { name: 'Save stock' }));
    expect(screen.getByText('Choose the supplier you owe.')).toBeInTheDocument();

    await user.selectOptions(screen.getByLabelText(/^Supplier/), '1');
    await user.selectOptions(screen.getByLabelText(/Paid from/), '1');
    await user.click(screen.getByRole('button', { name: 'Save stock' }));

    await waitFor(() => expect(api.called('POST', '/api/inventory/purchases')).toHaveLength(1));
    expect(api.last('POST', '/api/inventory/purchases').body).toMatchObject({ on_credit: true, opening_stock: false, supplier_id: '1', account_id: '1' });
  });

  it('records opening stock with no payment at all', async () => {
    const user = userEvent.setup();
    const api = await open();

    await user.selectOptions(screen.getByLabelText(/^Product/), '1');
    await user.type(screen.getByLabelText(/^Purchase price/), '600000');
    await user.click(screen.getByLabelText(/Opening stock/));
    expect(screen.queryByLabelText(/Paid from/)).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Save stock' }));

    await waitFor(() => expect(api.called('POST', '/api/inventory/purchases')).toHaveLength(1));
    expect(api.last('POST', '/api/inventory/purchases').body).toMatchObject({ opening_stock: true, on_credit: false, account_id: null });
  });

  it('checks every IMEI before anything is sent', async () => {
    const user = userEvent.setup();
    const api = await open();

    await user.selectOptions(screen.getByLabelText(/^Product/), '1');
    await user.type(screen.getByLabelText('IMEI'), '12345');
    await user.type(screen.getByLabelText(/^Purchase price/), '600000');
    await user.selectOptions(screen.getByLabelText(/Paid from/), '1');
    await user.click(screen.getByRole('button', { name: 'Save stock' }));

    expect(screen.getByText('An IMEI is 14 to 16 digits.')).toBeInTheDocument();
    expect(api.called('POST', '/api/inventory/purchases')).toHaveLength(0);
  });

  it('sends counted items by quantity and cost', async () => {
    const user = userEvent.setup();
    const api = await open();

    await user.selectOptions(screen.getByLabelText(/^Product/), '2');
    await user.type(screen.getByLabelText(/^Quantity/), '10');
    await user.type(screen.getByLabelText(/Cost of one/), '1000');
    await user.selectOptions(screen.getByLabelText(/Paid from/), '1');
    expect(within(screen.getByLabelText('Purchase totals')).getByText('Stock value added').nextSibling).toHaveTextContent('TSh 10,000');
    await user.click(screen.getByRole('button', { name: 'Save stock' }));

    await waitFor(() => expect(api.called('POST', '/api/inventory/purchases')).toHaveLength(1));
    expect(api.last('POST', '/api/inventory/purchases').body).toMatchObject({ product_id: '2', quantity: 10, unit_cost: '1000', account_id: '1' });
    expect(api.last('POST', '/api/inventory/purchases').body.units).toBeUndefined();
  });

  it('shows the server’s complaint about a repeated IMEI on that phone', async () => {
    const user = userEvent.setup();
    mockApi({ ...routes, 'POST /api/inventory/purchases': () => { throw validationError({ 'units.0.imei': ['This imei is already recorded (status: sold).'] }); } });
    renderApp(<PurchaseForm onClose={() => {}} onDone={() => {}} />);
    await waitFor(() => expect(within(screen.getByLabelText(/^Product/)).getAllByRole('option').length).toBeGreaterThan(1));

    await user.selectOptions(screen.getByLabelText(/^Product/), '1');
    await user.type(screen.getByLabelText('IMEI'), '356938035640001');
    await user.type(screen.getByLabelText(/^Purchase price/), '600000');
    await user.selectOptions(screen.getByLabelText(/Paid from/), '1');
    await user.click(screen.getByRole('button', { name: 'Save stock' }));

    expect(await screen.findByText('This imei is already recorded (status: sold).')).toBeInTheDocument();
  });
});

describe('ScheduleModal', () => {
  const debt = {
    id: 1, party_name: 'Mama', issued_on: '2026-09-01',
    figures: { original_amount: '100000.00', outstanding_balance: '100000.00', schedule: [] },
  };

  it('fills in equal installments that add up exactly', async () => {
    const user = userEvent.setup();
    mockApi();
    renderApp(<ScheduleModal debt={debt} onClose={() => {}} onSaved={() => {}} />);

    await user.clear(screen.getByLabelText('Number of installments'));
    await user.type(screen.getByLabelText('Number of installments'), '3');
    await user.click(screen.getByRole('button', { name: 'Fill in for me' }));

    expect(screen.getAllByLabelText(/Amount/).map((i) => i.value)).toEqual(['33333.33', '33333.33', '33333.34']);
    expect(screen.getByText(/Total planned:/)).toHaveTextContent('TSh 100,000 of TSh 100,000');
  });

  it('will not save installments that add up to more than the debt', async () => {
    const user = userEvent.setup();
    const api = mockApi({ 'PUT /api/debts/1/schedule': () => ({ data: {} }) });
    renderApp(<ScheduleModal debt={debt} onClose={() => {}} onSaved={() => {}} />);

    await user.type(screen.getAllByLabelText(/Due date/)[0], '2026-11-01');
    await user.type(screen.getAllByLabelText(/Amount/)[0], '100001');
    await user.click(screen.getByRole('button', { name: 'Save schedule' }));

    expect(screen.getByText(/add up to more than the debt/)).toBeInTheDocument();
    expect(api.called('PUT', '/api/debts/1/schedule')).toHaveLength(0);
  });

  it('saves the plan', async () => {
    const user = userEvent.setup();
    const api = mockApi({ 'PUT /api/debts/1/schedule': () => ({ data: {} }) });
    renderApp(<ScheduleModal debt={debt} onClose={() => {}} onSaved={() => {}} />);

    await user.type(screen.getAllByLabelText(/Due date/)[0], '2026-11-01');
    await user.type(screen.getAllByLabelText(/Amount/)[0], '50000');
    await user.click(screen.getByRole('button', { name: 'Save schedule' }));

    await waitFor(() => expect(api.called('PUT', '/api/debts/1/schedule')).toHaveLength(1));
    expect(api.last('PUT', '/api/debts/1/schedule').body).toEqual({ installments: [{ due_date: '2026-11-01', amount_due: '50000', note: null }] });
  });
});

import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import SaleDetail from '../components/SaleDetail';
import TransactionDetail from '../components/TransactionDetail';
import { TransferFormModal } from '../components/TransactionForms';
import InventoryPage from '../pages/InventoryPage';
import SettingsPage from '../pages/SettingsPage';
import { mockApi, renderApp } from '../test/utils';

vi.mock('../api/client');

const PAGE = (rows) => ({ data: rows, links: {}, meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length } });

describe('InventoryPage', () => {
  const summary = { totals: { stock_value_at_cost: '605000.00', phones_in_stock: 1, counted_items_in_stock: 3 }, low_stock: [{ name: 'USB-C charger', quantity: 3 }], products_sold: [{ product_id: 1, name: 'iPhone 13', quantity_sold: 2, sales_before_discount: '1500000.00' }] };
  const products = [
    { id: 1, product_type: 'phone', tracking: 'serialized', name: 'iPhone 13', brand: 'Apple', model: '13', sku: null, default_selling_price: '750000.00', reorder_level: null, is_active: true, average_cost: null, stock: { quantity: 1, value_at_cost: '605000.00', low_stock: false } },
    { id: 2, product_type: 'accessory', tracking: 'quantity', name: 'USB-C charger', brand: null, model: null, sku: null, default_selling_price: '5000.00', reorder_level: 5, is_active: true, average_cost: '1000.00', stock: { quantity: 3, value_at_cost: '3000.00', low_stock: true } },
  ];
  const units = [{ id: 11, product_id: 1, product: { id: 1, name: 'iPhone 13' }, supplier: { id: 1, name: 'Dubai Traders' }, imei: '356938035640001', serial_number: null, storage: '128GB', color: 'Black', item_condition: 'used', acquisition_source: 'purchase', status: 'sold', purchase_date: '2026-10-01', purchase_price: '600000.00', additional_costs: '5000.00', cost: '605000.00', sale: { sale_number: 'S-00001', gross_profit: '145000.00' } }];
  const routes = { 'GET /api/inventory/summary': () => ({ data: summary }), 'GET /api/products': () => PAGE(products), 'GET /api/inventory/units': () => PAGE(units) };

  it('shows stock value, a low-stock warning and the products with their stock', async () => {
    mockApi(routes);
    renderApp(<InventoryPage />);

    expect(await screen.findByText('TSh 605,000', { selector: 'p' })).toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent('USB-C charger (3 left)');
    const charger = (await screen.findByText('USB-C charger', { selector: 'div' })).closest('tr');
    expect(within(charger).getByText('Low')).toBeInTheDocument();
    expect(within(charger).getByText('TSh 3,000')).toBeInTheDocument();
  });

  it('lists phones with their cost and the profit once sold', async () => {
    mockApi(routes);
    const user = userEvent.setup();
    renderApp(<InventoryPage />);

    await user.click(await screen.findByRole('tab', { name: /Phones/ }));
    const row = (await screen.findByText('356938035640001')).closest('tr');
    expect(within(row).getByText('TSh 605,000')).toBeInTheDocument();
    expect(within(row).getByText('Sold')).toBeInTheDocument();
    expect(within(row).getByText(/profit TSh 145,000/)).toBeInTheDocument();
  });

  it('shows products sold in their own tab', async () => {
    mockApi(routes);
    const user = userEvent.setup();
    renderApp(<InventoryPage />);

    await user.click(await screen.findByRole('tab', { name: 'Products sold' }));
    expect(await screen.findByText('TSh 1,500,000')).toBeInTheDocument();
  });

  it('adds a product, locking nothing that the API accepts and sending the tracking choice', async () => {
    const api = mockApi({ ...routes, 'POST /api/products': () => ({ data: { id: 9 } }) });
    const user = userEvent.setup();
    renderApp(<InventoryPage />);

    await user.click(await screen.findByRole('button', { name: /Add product/ }));
    const dialog = screen.getByRole('dialog', { name: 'Add product' });
    await user.selectOptions(within(dialog).getByLabelText(/How you keep stock/), 'quantity');
    expect(within(dialog).getByLabelText(/Warn me when stock/)).toBeInTheDocument();
    await user.type(within(dialog).getByLabelText(/^Name/), 'Earphones');
    await user.click(within(dialog).getByRole('button', { name: 'Add product' }));

    await waitFor(() => expect(api.called('POST', '/api/products')).toHaveLength(1));
    expect(api.last('POST', '/api/products').body).toMatchObject({ name: 'Earphones', tracking: 'quantity', product_type: 'phone' });
  });
});

describe('SettingsPage', () => {
  it('shows the profile, the categories and the contacts', async () => {
    mockApi({
      'GET /api/suppliers': () => PAGE([{ id: 1, name: 'Dubai Traders', phone: '0700', notes: null }]),
      'GET /api/customers': () => PAGE([{ id: 1, name: 'Asha', phone: '0712', email: null, notes: null }]),
    });
    renderApp(<SettingsPage />);

    expect(await screen.findByText('Test Phones')).toBeInTheDocument();
    expect(await screen.findByText('Food')).toBeInTheDocument();
    expect(screen.getByText('Groceries')).toBeInTheDocument();
    expect(await screen.findByText('Dubai Traders')).toBeInTheDocument();
    expect(await screen.findByText('Asha')).toBeInTheDocument();
  });

  it('adds a subcategory under the right parent', async () => {
    const api = mockApi({
      'GET /api/suppliers': () => PAGE([]), 'GET /api/customers': () => PAGE([]),
      'POST /api/categories': () => ({ data: { id: 20 } }),
    });
    const user = userEvent.setup();
    renderApp(<SettingsPage />);

    const food = (await screen.findByText('Food')).closest('li');
    await user.click(within(food).getAllByRole('button', { name: /Subcategory/ })[0]);
    const dialog = screen.getByRole('dialog', { name: 'Add subcategory to Food' });
    await user.type(within(dialog).getByLabelText(/Name/), 'Eating out');
    await user.click(within(dialog).getByRole('button', { name: 'Add subcategory' }));

    await waitFor(() => expect(api.called('POST', '/api/categories')).toHaveLength(1));
    expect(api.last('POST', '/api/categories').body).toEqual({ name: 'Eating out', scope: 'personal', kind: 'expense', parent_id: 10 });
  });

  it('asks before deleting a supplier and shows why it failed', async () => {
    mockApi({
      'GET /api/suppliers': () => PAGE([{ id: 1, name: 'Dubai Traders', phone: null, notes: null }]), 'GET /api/customers': () => PAGE([]),
      'DELETE /api/suppliers/1': () => { throw Object.assign(new Error('x'), { response: { status: 422, data: { errors: { supplier: ['This supplier has stock or debts recorded and cannot be deleted.'] } } } }); },
    });
    const user = userEvent.setup();
    renderApp(<SettingsPage />);

    const row = (await screen.findByText('Dubai Traders')).closest('tr');
    await user.click(within(row).getByRole('button', { name: 'Delete' }));
    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Delete' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('cannot be deleted');
  });
});

describe('SaleDetail', () => {
  const SALE = (over = {}) => ({
    id: 1, sale_number: 'S-00001', status: 'completed', sale_date: '2026-10-09', due_date: null, notes: null, cancel_reason: null, customer: { id: 1, name: 'Asha', phone: '0712' },
    figures: { subtotal: '750000.00', discount: '0.00', total: '750000.00', returned_revenue: '0.00', net_revenue: '750000.00', payments_received: '200000.00', refunds_paid: '0.00', amount_paid: '200000.00', outstanding_balance: '550000.00', refund_due: '0.00', payment_status: 'partial', cogs: '605000.00', gross_profit: '145000.00', direct_costs: '0.00', net_profit: '145000.00' },
    items: [{ id: 5, product_id: 1, product: { name: 'iPhone 13', tracking: 'serialized' }, unit: { id: 11, imei: '356938035640001', serial_number: null, storage: '128GB', color: 'Black' }, quantity: 1, returned_quantity: 0, unit_price: '750000.00', unit_cost_snapshot: '605000.00', line_total: '750000.00' }],
    returns: [], payments: [{ id: 30, type: 'customer_payment', amount: '200000.00', transaction_date: '2026-10-09', status: 'posted', description: 'Payment for S-00001' }], costs: [], ...over,
  });

  it('shows what is owed and the profit, and records a payment', async () => {
    const api = mockApi({ 'GET /api/sales/1': () => ({ data: SALE() }), 'POST /api/sales/1/payments': () => ({ data: SALE() }) });
    const user = userEvent.setup();
    renderApp(<SaleDetail id={1} onClose={() => {}} />);

    expect(await screen.findByText('Sale S-00001', { selector: 'h2' })).toBeInTheDocument();
    expect(screen.getByText('Part paid')).toBeInTheDocument();
    expect(screen.getByText('Customer still owes').nextSibling).toHaveTextContent('TSh 550,000');
    expect(screen.getByText('Gross profit').nextSibling).toHaveTextContent('TSh 145,000');
    expect(screen.getByText(/IMEI 356938035640001/)).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Record payment' }));
    const dialog = screen.getByRole('dialog', { name: 'Record customer payment' });
    expect(within(dialog).getByLabelText(/Amount/)).toHaveValue('550000.00');
    await user.selectOptions(within(dialog).getByLabelText(/Received into/), '1');
    await user.click(within(dialog).getByRole('button', { name: 'Record payment' }));

    await waitFor(() => expect(api.called('POST', '/api/sales/1/payments')).toHaveLength(1));
    expect(api.last('POST', '/api/sales/1/payments').body).toMatchObject({ account_id: '1', amount: '550000.00' });
  });

  it('offers no payment button once everything is paid', async () => {
    mockApi({ 'GET /api/sales/1': () => ({ data: SALE({ figures: { ...SALE().figures, outstanding_balance: '0.00', amount_paid: '750000.00', payment_status: 'paid' } }) }) });
    renderApp(<SaleDetail id={1} onClose={() => {}} />);

    expect(await screen.findByText('Paid')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Record payment' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Refund customer' })).toBeInTheDocument();
  });

  it('says a cancelled sale no longer counts and offers no actions', async () => {
    mockApi({ 'GET /api/sales/1': () => ({ data: SALE({ status: 'cancelled', cancel_reason: 'Changed mind', figures: { ...SALE().figures, payment_status: 'cancelled' } }) }) });
    renderApp(<SaleDetail id={1} onClose={() => {}} />);

    expect(await screen.findByText(/This sale was cancelled: Changed mind/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Cancel sale' })).not.toBeInTheDocument();
  });

  it('needs a reason to cancel a sale', async () => {
    const api = mockApi({ 'GET /api/sales/1': () => ({ data: SALE() }), 'POST /api/sales/1/cancel': () => ({ data: {} }) });
    const user = userEvent.setup();
    renderApp(<SaleDetail id={1} onClose={() => {}} />);

    await user.click(await screen.findByRole('button', { name: 'Cancel sale' }));
    const dialog = screen.getByRole('dialog', { name: 'Cancel this sale' });
    expect(within(dialog).getByRole('button', { name: 'Cancel sale' })).toBeDisabled();
    await user.type(within(dialog).getByLabelText('Reason'), 'Customer changed mind');
    await user.click(within(dialog).getByRole('button', { name: 'Cancel sale' }));

    await waitFor(() => expect(api.called('POST', '/api/sales/1/cancel')).toHaveLength(1));
    expect(api.last('POST', '/api/sales/1/cancel').body).toEqual({ reason: 'Customer changed mind' });
  });
});

describe('TransactionDetail', () => {
  const routes = {
    'GET /api/transactions/5': () => ({ data: { id: 5, type: 'expense', scope: 'business', status: 'posted', transaction_date: '2026-10-10', amount: '20000.00', category: { id: 13, name: 'Rent' }, description: 'Shop rent', recipient: 'Landlord', reference: null, notes: null, void_reason: null, entries: [{ id: 1, account: { name: 'Shop cash' }, amount: '-20000.00' }] } }),
    'GET /api/transactions/5/history': () => ({ data: [{ id: 1, event: 'created', created_at: '2026-10-10T08:00:00+03:00' }, { id: 2, event: 'receipt_added', created_at: '2026-10-11T08:00:00+03:00' }] }),
    'GET /api/transactions/5/receipts': () => ({ data: [{ id: 9, original_name: 'rent-receipt.pdf', transaction_id: 5 }] }),
  };

  it('shows the record, the accounts it touched, its history and receipts', async () => {
    mockApi(routes);
    renderApp(<TransactionDetail id={5} onClose={() => {}} />);

    expect(await screen.findByText('Shop rent')).toBeInTheDocument();
    expect(screen.getByText('Landlord')).toBeInTheDocument();
    expect(screen.getByText('Shop cash')).toBeInTheDocument();
    expect(await screen.findByText('Recorded')).toBeInTheDocument();
    expect(screen.getByText('Receipt added')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'rent-receipt.pdf' })).toHaveAttribute('href', '/api/receipts/9/download');
  });

  it('attaches a receipt file', async () => {
    const api = mockApi({ ...routes, 'POST /api/transactions/5/receipts': () => ({ data: { id: 10 } }) });
    const user = userEvent.setup();
    renderApp(<TransactionDetail id={5} onClose={() => {}} />);

    await screen.findByText('rent-receipt.pdf');
    const file = new File(['%PDF-1.4'], 'new.pdf', { type: 'application/pdf' });
    await user.upload(screen.getByLabelText('Choose a receipt file'), file);

    await waitFor(() => expect(api.called('POST', '/api/transactions/5/receipts')).toHaveLength(1));
    expect(api.last('POST', '/api/transactions/5/receipts').body).toBeInstanceOf(FormData);
    expect(api.last('POST', '/api/transactions/5/receipts').body.get('file').name).toBe('new.pdf');
  });
});

describe('TransferFormModal', () => {
  it('tells you when a transfer was recorded as an owner withdrawal', async () => {
    const api = mockApi({ 'POST /api/transfers': () => ({ data: { type: 'owner_withdrawal' }, meta: { warnings: [] } }) });
    const user = userEvent.setup();
    renderApp(<TransferFormModal onClose={() => {}} />);

    await screen.findByRole('dialog', { name: /Transfer between accounts/ });
    await waitFor(() => expect(within(screen.getByLabelText(/From account/)).getAllByRole('option').length).toBeGreaterThan(1));
    await user.selectOptions(screen.getByLabelText(/From account/), '1');
    await user.selectOptions(screen.getByLabelText(/To account/), '2');
    await user.type(screen.getByLabelText(/Amount/), '50000');
    await user.click(screen.getByRole('button', { name: 'Record transfer' }));

    await waitFor(() => expect(api.called('POST', '/api/transfers')).toHaveLength(1));
    expect(api.last('POST', '/api/transfers').body).toMatchObject({ from_account_id: '1', to_account_id: '2', amount: '50000' });
    expect(await screen.findByText('Recorded as an owner withdrawal (business to personal).')).toBeInTheDocument();
  });
});

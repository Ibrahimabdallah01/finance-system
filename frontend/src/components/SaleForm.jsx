import { useEffect, useMemo, useState } from 'react';
import client from '../api/client';
import { useLookups } from '../context/LookupContext';
import { useToast } from '../context/ToastContext';
import { announceDataChanged } from '../hooks/useDataChanged';
import { useFetch } from '../hooks/useFetch';
import { today } from '../lib/dates';
import { getErrorMessage, getFieldErrors } from '../lib/errors';
import { compareMoney, formatMoney, isValidAmount, mulMoney, subMoney, sumMoney } from '../lib/money';
import Field from './Field';
import { PAYMENT_METHODS, accountOptions } from './TransactionForms';
import { Button, ErrorBanner, Modal } from './ui';

const blankItem = () => ({ product_id: '', inventory_unit_id: '', quantity: '1', unit_price: '', unit_cost: '' });
const blankPayment = (accountId = '') => ({ account_id: accountId, amount: '', payment_method: 'cash' });

/** Create a sale: items, optional discount, and any money received now. Totals are shown before saving. */
export default function SaleForm({ onClose, onDone }) {
  const toast = useToast();
  const { accounts, reload: reloadLookups } = useLookups();
  const products = useFetch(() => client.get('/api/products', { params: { active: 1, per_page: 100 } }).then((r) => r.data.data), []);
  const customers = useFetch(() => client.get('/api/customers', { params: { per_page: 100 } }).then((r) => r.data.data), []);

  const business = accountOptions(accounts, 'business');
  const [head, setHead] = useState({ customer_id: '', sale_date: today(), due_date: '', discount_amount: '', notes: '', cost_account_id: '' });
  const [items, setItems] = useState([blankItem()]);
  const [payments, setPayments] = useState([]);
  const [units, setUnits] = useState({}); // product id -> phones in stock
  const [errors, setErrors] = useState({});
  const [formError, setFormError] = useState('');
  const [busy, setBusy] = useState(false);

  const productById = useMemo(() => Object.fromEntries((products.data || []).map((p) => [String(p.id), p])), [products.data]);

  // Phones in stock for each serialized product that is picked.
  useEffect(() => {
    items.forEach((item) => {
      const product = productById[String(item.product_id)];
      if (product?.tracking === 'serialized' && !units[product.id]) {
        client.get('/api/inventory/units', { params: { status: 'in_stock', product_id: product.id, per_page: 100 } })
          .then((r) => setUnits((u) => ({ ...u, [product.id]: r.data.data })))
          .catch(() => setUnits((u) => ({ ...u, [product.id]: [] })));
      }
    });
  }, [items, productById, units]);

  const setItem = (index, name, value) => {
    setItems((list) => list.map((item, i) => {
      if (i !== index) return item;
      const next = { ...item, [name]: value };

      if (name === 'product_id') {
        const product = productById[String(value)];
        next.inventory_unit_id = '';
        next.quantity = '1';
        next.unit_price = product?.default_selling_price && Number(product.default_selling_price) > 0 ? product.default_selling_price : '';
        next.unit_cost = '';
      }

      return next;
    }));
    setErrors((e) => ({ ...e, [`items.${index}.${name}`]: undefined }));
  };

  const setPayment = (index, name, value) => {
    setPayments((list) => list.map((p, i) => (i === index ? { ...p, [name]: value } : p)));
    setErrors((e) => ({ ...e, [`payments.${index}.${name}`]: undefined }));
  };

  const lines = items.map((item) => {
    const product = productById[String(item.product_id)];
    const quantity = product?.tracking === 'serialized' ? '1' : item.quantity;

    return { product, item, quantity, total: mulMoney(item.unit_price, quantity), cost: product?.tracking === 'direct' ? mulMoney(item.unit_cost || '0', quantity) : '0.00' };
  });
  const subtotal = sumMoney(lines.map((l) => l.total));
  const discount = isValidAmount(head.discount_amount, { allowZero: true }) ? head.discount_amount : '0';
  const total = subMoney(subtotal, discount);
  const paid = sumMoney(payments.map((p) => (isValidAmount(p.amount) ? p.amount : '0')));
  const balance = subMoney(total, paid);
  const directCost = sumMoney(lines.map((l) => l.cost));
  const hasDirect = lines.some((l) => l.product?.tracking === 'direct');
  const owes = (compareMoney(balance, '0') ?? 0) > 0;

  const validate = () => {
    const found = {};

    items.forEach((item, i) => {
      const product = productById[String(item.product_id)];
      if (!product) found[`items.${i}.product_id`] = 'Choose a product.';
      if (product?.tracking === 'serialized' && !item.inventory_unit_id) found[`items.${i}.inventory_unit_id`] = 'Choose the phone being sold.';
      if (product && product.tracking !== 'serialized' && !/^[1-9]\d*$/.test(String(item.quantity))) found[`items.${i}.quantity`] = 'Enter a whole number of 1 or more.';
      if (!isValidAmount(item.unit_price, { allowZero: true })) found[`items.${i}.unit_price`] = 'Enter the selling price, for example 750000.';
      if (product?.tracking === 'direct' && !isValidAmount(item.unit_cost || '0', { allowZero: true })) found[`items.${i}.unit_cost`] = 'Enter what one item cost you.';
    });

    payments.forEach((p, i) => {
      if (!p.account_id) found[`payments.${i}.account_id`] = 'Choose the account.';
      if (!isValidAmount(p.amount)) found[`payments.${i}.amount`] = 'Enter an amount greater than zero.';
    });

    if (head.discount_amount && !isValidAmount(head.discount_amount, { allowZero: true })) found.discount_amount = 'Enter an amount, for example 50000.';
    if ((compareMoney(discount, subtotal) ?? 0) > 0) found.discount_amount = 'The discount cannot be more than the subtotal.';
    if ((compareMoney(paid, total) ?? 0) > 0) found.payments = `The payments (${formatMoney(paid)}) are more than the total (${formatMoney(total)}).`;
    if (owes && !head.customer_id) found.customer_id = 'Choose a customer when the sale is not fully paid.';
    if (hasDirect && (compareMoney(directCost, '0') ?? 0) > 0 && !head.cost_account_id) found.cost_account_id = 'Choose the account that paid for the directly sold items.';

    return found;
  };

  const submit = async (event) => {
    event.preventDefault();
    setFormError('');

    const found = validate();
    setErrors(found);
    if (Object.keys(found).length) return;

    setBusy(true);

    try {
      const body = {
        ...head,
        items: items.map((item) => {
          const product = productById[String(item.product_id)];
          return {
            product_id: item.product_id,
            inventory_unit_id: product.tracking === 'serialized' ? item.inventory_unit_id : null,
            quantity: product.tracking === 'serialized' ? 1 : Number(item.quantity),
            unit_price: item.unit_price,
            unit_cost: product.tracking === 'direct' ? item.unit_cost || '0' : null,
          };
        }),
        payments,
        cost_account_id: hasDirect ? head.cost_account_id : null,
      };
      const response = await client.post('/api/sales', body);
      toast.success(`Sale ${response.data.data.sale_number} recorded.`);
      reloadLookups();
      announceDataChanged();
      onDone?.(response.data.data);
      onClose();
    } catch (error) {
      const fieldErrors = getFieldErrors(error);
      setErrors(fieldErrors);
      if (Object.keys(fieldErrors).length === 0) setFormError(getErrorMessage(error));
    } finally {
      setBusy(false);
    }
  };

  const changeHead = (name, value) => { setHead((h) => ({ ...h, [name]: value })); setErrors((e) => ({ ...e, [name]: undefined })); };
  const productOptions = (products.data || []).map((p) => ({ value: p.id, label: `${p.name}${p.tracking === 'quantity' ? ` (${p.stock?.quantity ?? 0} in stock)` : ''}` }));
  const customerOptions = (customers.data || []).map((c) => ({ value: c.id, label: c.phone ? `${c.name} · ${c.phone}` : c.name }));

  return (
    <Modal title="New sale" onClose={onClose} wide>
      <form onSubmit={submit} noValidate className="space-y-4">
        {formError && <ErrorBanner message={formError} />}
        {products.error && <ErrorBanner message="Products could not be loaded." onRetry={products.reload} />}

        <div className="grid gap-3 sm:grid-cols-3">
          <Field label="Customer" name="customer_id" type="select" value={head.customer_id} onChange={changeHead} error={errors.customer_id} options={customerOptions} placeholder="Walk-in customer" hint="Needed when the customer will pay later." />
          <Field label="Sale date" name="sale_date" type="date" value={head.sale_date} onChange={changeHead} error={errors.sale_date} max={today()} required />
          <Field label="Balance due by (optional)" name="due_date" type="date" value={head.due_date} onChange={changeHead} error={errors.due_date} min={head.sale_date} />
        </div>

        <section className="space-y-3">
          <h3 className="font-medium">Items</h3>
          {items.map((item, index) => {
            const product = productById[String(item.product_id)];
            const phones = product ? units[product.id] : null;

            return (
              <div key={index} className="grid gap-2 rounded-lg border border-slate-200 p-3 dark:border-slate-700 sm:grid-cols-6">
                <div className="sm:col-span-2">
                  <Field label="Product" name="product_id" type="select" value={item.product_id} onChange={(n, v) => setItem(index, n, v)} error={errors[`items.${index}.product_id`]} options={productOptions} required />
                </div>
                {product?.tracking === 'serialized' ? (
                  <div className="sm:col-span-2">
                    <Field label="Phone (in stock)" name="inventory_unit_id" type="select" value={item.inventory_unit_id} onChange={(n, v) => setItem(index, n, v)} error={errors[`items.${index}.inventory_unit_id`]} required
                      options={(phones || []).filter((u) => !items.some((other, j) => j !== index && String(other.inventory_unit_id) === String(u.id))).map((u) => ({ value: u.id, label: `${u.imei || u.serial_number || `#${u.id}`} · ${[u.storage, u.color].filter(Boolean).join(' ')} · cost ${formatMoney(u.cost)}` }))}
                      hint={phones && phones.length === 0 ? 'No phones of this product in stock. Buy stock first.' : undefined} />
                  </div>
                ) : (
                  <Field label="Quantity" name="quantity" value={item.quantity} onChange={(n, v) => setItem(index, n, v)} error={errors[`items.${index}.quantity`]} disabled={!product} />
                )}
                <Field label={product?.tracking === 'serialized' ? 'Selling price' : 'Price each'} name="unit_price" type="money" value={item.unit_price} onChange={(n, v) => setItem(index, n, v)} error={errors[`items.${index}.unit_price`]} required allowZero />
                {product?.tracking === 'direct' && (
                  <Field label="What it cost you (each)" name="unit_cost" type="money" value={item.unit_cost} onChange={(n, v) => setItem(index, n, v)} error={errors[`items.${index}.unit_cost`]} hint="Items you buy and sell at once, without keeping stock." />
                )}
                <div className="flex items-end justify-between gap-2 sm:col-span-6">
                  <span className="text-sm text-slate-600 dark:text-slate-300">Line total: <strong>{formatMoney(lines[index].total)}</strong></span>
                  {items.length > 1 && <Button size="sm" variant="ghost" onClick={() => setItems((list) => list.filter((_, i) => i !== index))}>Remove item</Button>}
                </div>
              </div>
            );
          })}
          <Button size="sm" onClick={() => setItems((list) => [...list, blankItem()])}>＋ Add another item</Button>
        </section>

        <div className="grid gap-3 sm:grid-cols-3">
          <Field label="Discount (TSh)" name="discount_amount" type="money" value={head.discount_amount} onChange={changeHead} error={errors.discount_amount} placeholder="0" />
          <div className="sm:col-span-2"><Field label="Notes (optional)" name="notes" value={head.notes} onChange={changeHead} /></div>
        </div>

        {hasDirect && (
          <Field label="Account that paid for the directly sold items" name="cost_account_id" type="select" value={head.cost_account_id} onChange={changeHead} error={errors.cost_account_id} options={business} required={(compareMoney(directCost, '0') ?? 0) > 0}
            hint={`Their cost (${formatMoney(directCost)}) leaves this account now and counts as cost of goods sold.`} />
        )}

        <section className="space-y-3">
          <h3 className="font-medium">Money received now</h3>
          {errors.payments && <p role="alert" className="text-xs text-red-600">{errors.payments}</p>}
          {payments.length === 0 && <p className="text-sm text-slate-500">Nothing received yet. The customer will owe the full price.</p>}
          {payments.map((payment, index) => (
            <div key={index} className="grid gap-2 sm:grid-cols-4">
              <Field label="Received into" name="account_id" type="select" value={payment.account_id} onChange={(n, v) => setPayment(index, n, v)} error={errors[`payments.${index}.account_id`]} options={business} required />
              <Field label="Amount (TSh)" name="amount" type="money" value={payment.amount} onChange={(n, v) => setPayment(index, n, v)} error={errors[`payments.${index}.amount`]} required />
              <Field label="Method" name="payment_method" type="select" value={payment.payment_method} onChange={(n, v) => setPayment(index, n, v)} options={PAYMENT_METHODS} />
              <div className="flex items-end"><Button size="sm" variant="ghost" onClick={() => setPayments((list) => list.filter((_, i) => i !== index))}>Remove</Button></div>
            </div>
          ))}
          <div className="flex flex-wrap gap-2">
            <Button size="sm" onClick={() => setPayments((list) => [...list, blankPayment(business[0]?.value ?? '')])}>＋ Add payment</Button>
            {owes && (compareMoney(total, '0') ?? 0) > 0 && payments.length === 0 && <Button size="sm" onClick={() => setPayments([{ ...blankPayment(business[0]?.value ?? ''), amount: total }])}>Customer paid in full</Button>}
          </div>
        </section>

        <dl className="grid grid-cols-2 gap-y-1 rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-900/40 sm:grid-cols-4 sm:gap-x-4" aria-label="Sale totals">
          <div><dt className="text-slate-500">Subtotal</dt><dd className="font-medium">{formatMoney(subtotal)}</dd></div>
          <div><dt className="text-slate-500">Total after discount</dt><dd className="font-semibold">{formatMoney(total)}</dd></div>
          <div><dt className="text-slate-500">Received now</dt><dd className="font-medium">{formatMoney(paid)}</dd></div>
          <div><dt className="text-slate-500">Customer will owe</dt><dd className="font-semibold">{formatMoney(owes ? balance : '0')}</dd></div>
        </dl>

        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={busy}>Cancel</Button>
          <Button variant="primary" type="submit" loading={busy}>Save sale</Button>
        </div>
      </form>
    </Modal>
  );
}

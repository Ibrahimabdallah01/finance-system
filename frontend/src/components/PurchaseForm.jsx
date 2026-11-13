import { useMemo, useState } from 'react';
import client from '../api/client';
import { useLookups } from '../context/LookupContext';
import { useToast } from '../context/ToastContext';
import { announceDataChanged } from '../hooks/useDataChanged';
import { useFetch } from '../hooks/useFetch';
import { today } from '../lib/dates';
import { getErrorMessage, getFieldErrors } from '../lib/errors';
import { compareMoney, formatMoney, isValidAmount, mulMoney, sumMoney } from '../lib/money';
import Field from './Field';
import { accountOptions } from './TransactionForms';
import { Button, ErrorBanner, Modal } from './ui';

const CONDITIONS = [
  { value: 'new', label: 'New' }, { value: 'like_new', label: 'Like new' }, { value: 'used', label: 'Used' },
  { value: 'refurbished', label: 'Refurbished' }, { value: 'for_parts', label: 'For parts' },
];
const blankUnit = () => ({ imei: '', serial_number: '', storage: '', color: '', item_condition: 'used', purchase_price: '', additional_costs: '' });

/**
 * Stock coming in. Paying for it moves cash out but creates an asset: it is not an expense
 * until the item is sold. Phones are entered one by one (IMEI); counted items by quantity.
 */
export default function PurchaseForm({ onClose, onDone }) {
  const toast = useToast();
  const { accounts, reload: reloadLookups } = useLookups();
  const products = useFetch(() => client.get('/api/products', { params: { active: 1, per_page: 100 } }).then((r) => r.data.data.filter((p) => p.tracking !== 'direct')), []);
  const suppliers = useFetch(() => client.get('/api/suppliers', { params: { per_page: 100 } }).then((r) => r.data.data), []);

  const [head, setHead] = useState({ product_id: '', supplier_id: '', purchase_date: today(), mode: 'paid', account_id: '', due_date: '', reference: '' });
  const [units, setUnits] = useState([blankUnit()]);
  const [counted, setCounted] = useState({ quantity: '', unit_cost: '', additional_costs: '' });
  const [errors, setErrors] = useState({});
  const [formError, setFormError] = useState('');
  const [busy, setBusy] = useState(false);

  const product = (products.data || []).find((p) => String(p.id) === String(head.product_id));
  const serialized = product?.tracking === 'serialized';
  const business = accountOptions(accounts, 'business');

  const totals = useMemo(() => {
    if (serialized) {
      const price = sumMoney(units.map((u) => (isValidAmount(u.purchase_price, { allowZero: true }) ? u.purchase_price : '0')));
      const extra = sumMoney(units.map((u) => (isValidAmount(u.additional_costs, { allowZero: true }) ? u.additional_costs : '0')));
      return { price, extra };
    }
    const price = isValidAmount(counted.unit_cost, { allowZero: true }) ? mulMoney(counted.unit_cost, counted.quantity) : '0.00';
    const extra = isValidAmount(counted.additional_costs, { allowZero: true }) ? counted.additional_costs : '0.00';
    return { price, extra };
  }, [serialized, units, counted]);

  const total = sumMoney([totals.price, totals.extra]);
  const paidNow = head.mode === 'paid' ? total : head.mode === 'credit' ? totals.extra : '0.00';
  const needsAccount = (compareMoney(paidNow, '0') ?? 0) > 0;

  const changeHead = (name, value) => { setHead((h) => ({ ...h, [name]: value })); setErrors((e) => ({ ...e, [name]: undefined })); };
  const changeUnit = (index, name, value) => { setUnits((list) => list.map((u, i) => (i === index ? { ...u, [name]: value } : u))); setErrors((e) => ({ ...e, [`units.${index}.${name}`]: undefined })); };
  const changeCounted = (name, value) => { setCounted((c) => ({ ...c, [name]: value })); setErrors((e) => ({ ...e, [name]: undefined })); };

  const validate = () => {
    const found = {};
    if (!product) found.product_id = 'Choose a product.';
    if (head.mode === 'credit' && !head.supplier_id) found.supplier_id = 'Choose the supplier you owe.';
    if (needsAccount && !head.account_id) found.account_id = 'Choose the account that pays.';

    if (serialized) {
      units.forEach((u, i) => {
        if (u.imei && !/^\d{14,16}$/.test(u.imei.trim())) found[`units.${i}.imei`] = 'An IMEI is 14 to 16 digits.';
        if (!isValidAmount(u.purchase_price, { allowZero: true })) found[`units.${i}.purchase_price`] = 'Enter the purchase price.';
        if (u.additional_costs && !isValidAmount(u.additional_costs, { allowZero: true })) found[`units.${i}.additional_costs`] = 'Enter an amount.';
      });
      const seen = new Set();
      units.forEach((u, i) => { const key = u.imei.trim(); if (key) { if (seen.has(key)) found[`units.${i}.imei`] = 'This IMEI is already in the list.'; seen.add(key); } });
    } else if (product) {
      if (!/^[1-9]\d*$/.test(counted.quantity)) found.quantity = 'Enter a whole number of 1 or more.';
      if (!isValidAmount(counted.unit_cost, { allowZero: true })) found.unit_cost = 'Enter the cost of one item.';
      if (counted.additional_costs && !isValidAmount(counted.additional_costs, { allowZero: true })) found.additional_costs = 'Enter an amount.';
    }

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
        product_id: head.product_id, supplier_id: head.supplier_id || null, purchase_date: head.purchase_date, reference: head.reference || null,
        on_credit: head.mode === 'credit', opening_stock: head.mode === 'opening',
        account_id: needsAccount ? head.account_id : null, due_date: head.mode === 'credit' ? head.due_date || null : null,
        ...(serialized
          ? { units: units.map((u) => ({ ...u, imei: u.imei.trim() || null, serial_number: u.serial_number.trim() || null, additional_costs: u.additional_costs || '0' })) }
          : { quantity: Number(counted.quantity), unit_cost: counted.unit_cost, additional_costs: counted.additional_costs || '0' }),
      };
      const response = await client.post('/api/inventory/purchases', body);
      (response.data.meta?.warnings || []).forEach((w) => toast.warning(w));
      toast.success(`Stock recorded: ${response.data.data.quantity_added} item(s), cost ${formatMoney(response.data.data.total_cost)}.`);
      reloadLookups();
      announceDataChanged();
      onDone?.();
      onClose();
    } catch (error) {
      const fieldErrors = getFieldErrors(error);
      setErrors(fieldErrors);
      if (Object.keys(fieldErrors).length === 0) setFormError(getErrorMessage(error));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal title="Buy stock" onClose={onClose} wide>
      <form onSubmit={submit} noValidate className="space-y-4">
        {formError && <ErrorBanner message={formError} />}
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label="Product" name="product_id" type="select" value={head.product_id} onChange={changeHead} error={errors.product_id} required
            options={(products.data || []).map((p) => ({ value: p.id, label: `${p.name} (${p.tracking === 'serialized' ? 'phones by IMEI' : 'counted'})` }))}
            hint="Add products under Inventory → Products first." />
          <Field label="Supplier" name="supplier_id" type="select" value={head.supplier_id} onChange={changeHead} error={errors.supplier_id} placeholder="None"
            options={(suppliers.data || []).map((s) => ({ value: s.id, label: s.name }))} required={head.mode === 'credit'} />
          <Field label="Purchase date" name="purchase_date" type="date" value={head.purchase_date} onChange={changeHead} error={errors.purchase_date} max={today()} required />
        </div>

        {serialized && (
          <section className="space-y-3">
            <h3 className="font-medium">Phones</h3>
            {units.map((unit, index) => (
              <div key={index} className="grid gap-2 rounded-lg border border-slate-200 p-3 dark:border-slate-700 sm:grid-cols-4">
                <Field label="IMEI" name="imei" value={unit.imei} onChange={(n, v) => changeUnit(index, n, v)} error={errors[`units.${index}.imei`]} placeholder="15 digits" />
                <Field label="Serial number" name="serial_number" value={unit.serial_number} onChange={(n, v) => changeUnit(index, n, v)} error={errors[`units.${index}.serial_number`]} />
                <Field label="Storage" name="storage" value={unit.storage} onChange={(n, v) => changeUnit(index, n, v)} placeholder="128GB" />
                <Field label="Colour" name="color" value={unit.color} onChange={(n, v) => changeUnit(index, n, v)} />
                <Field label="Condition" name="item_condition" type="select" value={unit.item_condition} onChange={(n, v) => changeUnit(index, n, v)} options={CONDITIONS} />
                <Field label="Purchase price" name="purchase_price" type="money" value={unit.purchase_price} onChange={(n, v) => changeUnit(index, n, v)} error={errors[`units.${index}.purchase_price`]} required allowZero />
                <Field label="Extra costs (transport…)" name="additional_costs" type="money" value={unit.additional_costs} onChange={(n, v) => changeUnit(index, n, v)} error={errors[`units.${index}.additional_costs`]} placeholder="0" />
                <div className="flex items-end">{units.length > 1 && <Button size="sm" variant="ghost" onClick={() => setUnits((list) => list.filter((_, i) => i !== index))}>Remove phone</Button>}</div>
              </div>
            ))}
            <Button size="sm" onClick={() => setUnits((list) => [...list, blankUnit()])}>＋ Add another phone</Button>
          </section>
        )}

        {product && !serialized && (
          <div className="grid gap-3 sm:grid-cols-3">
            <Field label="Quantity" name="quantity" value={counted.quantity} onChange={changeCounted} error={errors.quantity} required />
            <Field label="Cost of one (TSh)" name="unit_cost" type="money" value={counted.unit_cost} onChange={changeCounted} error={errors.unit_cost} required allowZero />
            <Field label="Extra costs, all together" name="additional_costs" type="money" value={counted.additional_costs} onChange={changeCounted} error={errors.additional_costs} placeholder="0" />
          </div>
        )}

        <fieldset className="space-y-2">
          <legend className="font-medium">How is it paid?</legend>
          {[['paid', 'Paid now'], ['credit', 'On credit (I owe the supplier)'], ['opening', 'Opening stock I already own (no payment)']].map(([value, label]) => (
            <label key={value} className="flex items-center gap-2 text-sm">
              <input type="radio" name="mode" value={value} checked={head.mode === value} onChange={() => changeHead('mode', value)} /> {label}
            </label>
          ))}
          {head.mode === 'credit' && <Field label="Pay the supplier by (optional)" name="due_date" type="date" value={head.due_date} onChange={changeHead} error={errors.due_date} min={head.purchase_date} />}
          {needsAccount && <Field label="Paid from" name="account_id" type="select" value={head.account_id} onChange={changeHead} error={errors.account_id} options={business} required hint={head.mode === 'credit' ? 'Only the extra costs are paid now; the price becomes a debt to the supplier.' : undefined} />}
        </fieldset>

        <dl className="grid grid-cols-2 gap-y-1 rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-900/40 sm:grid-cols-4" aria-label="Purchase totals">
          <div><dt className="text-slate-500">Price</dt><dd className="font-medium">{formatMoney(totals.price)}</dd></div>
          <div><dt className="text-slate-500">Extra costs</dt><dd className="font-medium">{formatMoney(totals.extra)}</dd></div>
          <div><dt className="text-slate-500">Stock value added</dt><dd className="font-semibold">{formatMoney(total)}</dd></div>
          <div><dt className="text-slate-500">Cash out now</dt><dd className="font-semibold">{formatMoney(paidNow)}</dd></div>
        </dl>
        <p className="text-xs text-slate-500">This is not an expense. Its cost reaches your profit only when the stock is sold (or written off if lost).</p>

        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={busy}>Cancel</Button>
          <Button variant="primary" type="submit" loading={busy}>Save stock</Button>
        </div>
      </form>
    </Modal>
  );
}

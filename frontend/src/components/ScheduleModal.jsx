import { useState } from 'react';
import client from '../api/client';
import { useToast } from '../context/ToastContext';
import { addMonths, today } from '../lib/dates';
import { getErrorMessage, getFieldErrors } from '../lib/errors';
import { compareMoney, formatMoney, isValidAmount, splitMoney, sumMoney } from '../lib/money';
import Field from './Field';
import { Button, ErrorBanner, Modal } from './ui';

/** Plan how a debt will be repaid. Repayments you record later are applied to the installments in date order. */
export default function ScheduleModal({ debt, onClose, onSaved }) {
  const toast = useToast();
  const existing = debt.figures.schedule.map((row) => ({ due_date: row.due_date, amount_due: row.amount_due, note: row.note || '' }));
  const [rows, setRows] = useState(existing.length ? existing : [{ due_date: '', amount_due: '', note: '' }]);
  const [gen, setGen] = useState({ count: '3', first: addMonths(today(), 1) });
  const [errors, setErrors] = useState({});
  const [formError, setFormError] = useState('');
  const [busy, setBusy] = useState(false);

  const total = sumMoney(rows.map((r) => (isValidAmount(r.amount_due) ? r.amount_due : '0')));
  const over = (compareMoney(total, debt.figures.original_amount) ?? 0) > 0;

  const edit = (index, name, value) => { setRows((list) => list.map((r, i) => (i === index ? { ...r, [name]: value } : r))); setErrors((e) => ({ ...e, [`installments.${index}.${name === 'due_date' ? 'due_date' : 'amount_due'}`]: undefined })); };

  const generate = () => {
    const count = Number.parseInt(gen.count, 10);
    if (!count || count < 1 || count > 60 || !gen.first) return;
    const amounts = splitMoney(debt.figures.outstanding_balance, count);
    setRows(amounts.map((amount, i) => ({ due_date: addMonths(gen.first, i), amount_due: amount, note: '' })));
  };

  const submit = async (event) => {
    event.preventDefault();
    setFormError('');
    const found = {};

    rows.forEach((r, i) => {
      if (!r.due_date) found[`installments.${i}.due_date`] = 'Choose a date.';
      if (!isValidAmount(r.amount_due)) found[`installments.${i}.amount_due`] = 'Enter an amount greater than zero.';
    });
    if (over) found.installments = `The installments (${formatMoney(total)}) add up to more than the debt (${formatMoney(debt.figures.original_amount)}).`;

    setErrors(found);
    if (Object.keys(found).length) return;

    setBusy(true);

    try {
      await client.put(`/api/debts/${debt.id}/schedule`, { installments: rows.map((r) => ({ ...r, note: r.note || null })) });
      toast.success('Repayment schedule saved.');
      onSaved?.();
      onClose();
    } catch (error) {
      const fieldErrors = getFieldErrors(error);
      setErrors(fieldErrors);
      if (Object.keys(fieldErrors).length === 0 || fieldErrors.installments) setFormError(getErrorMessage(error));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal title={`Repayment schedule: ${debt.party_name}`} onClose={onClose} wide>
      <form onSubmit={submit} noValidate className="space-y-3">
        {formError && <ErrorBanner message={formError} />}
        <div className="grid items-end gap-2 rounded-lg bg-slate-50 p-3 dark:bg-slate-900/40 sm:grid-cols-4">
          <Field label="Number of installments" name="count" value={gen.count} onChange={(n, v) => setGen((g) => ({ ...g, count: v }))} />
          <Field label="First due date" name="first" type="date" value={gen.first} onChange={(n, v) => setGen((g) => ({ ...g, first: v }))} />
          <p className="text-xs text-slate-500 sm:col-span-1">Splits the {formatMoney(debt.figures.outstanding_balance)} still owed into equal monthly parts.</p>
          <Button onClick={generate}>Fill in for me</Button>
        </div>

        {rows.map((row, index) => (
          <div key={index} className="grid gap-2 sm:grid-cols-4">
            <Field label="Due date" name="due_date" type="date" value={row.due_date} onChange={(n, v) => edit(index, n, v)} error={errors[`installments.${index}.due_date`]} required />
            <Field label="Amount (TSh)" name="amount_due" type="money" value={row.amount_due} onChange={(n, v) => edit(index, n, v)} error={errors[`installments.${index}.amount_due`]} required />
            <Field label="Note (optional)" name="note" value={row.note} onChange={(n, v) => edit(index, n, v)} />
            <div className="flex items-end">{rows.length > 1 && <Button size="sm" variant="ghost" onClick={() => setRows((list) => list.filter((_, i) => i !== index))}>Remove</Button>}</div>
          </div>
        ))}
        {errors.installments && <p role="alert" className="text-xs text-red-600">{errors.installments}</p>}
        <div className="flex flex-wrap items-center justify-between gap-2">
          <Button size="sm" onClick={() => setRows((list) => [...list, { due_date: '', amount_due: '', note: '' }])}>＋ Add installment</Button>
          <span className={`text-sm ${over ? 'text-red-600' : ''}`}>Total planned: <strong>{formatMoney(total)}</strong> of {formatMoney(debt.figures.original_amount)}</span>
        </div>
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={busy}>Cancel</Button>
          <Button variant="primary" type="submit" loading={busy}>Save schedule</Button>
        </div>
      </form>
    </Modal>
  );
}

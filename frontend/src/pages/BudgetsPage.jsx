import { useState } from 'react';
import client from '../api/client';
import { BudgetHistoryChart } from '../components/charts';
import ConfirmDialog from '../components/ConfirmDialog';
import FormModal from '../components/FormModal';
import { Badge, Button, EmptyState, ErrorBanner, Modal, PageHeader, ProgressBar, Skeleton, statusTone } from '../components/ui';
import { useLookups } from '../context/LookupContext';
import { useToast } from '../context/ToastContext';
import { announceDataChanged, useDataChanged } from '../hooks/useDataChanged';
import { useFetch } from '../hooks/useFetch';
import { formatDate, today } from '../lib/dates';
import { getErrorMessage } from '../lib/errors';
import { formatMoney } from '../lib/money';

const PERIODS = [{ value: 'daily', label: 'Daily' }, { value: 'weekly', label: 'Weekly (Mon–Sun)' }, { value: 'monthly', label: 'Monthly' }, { value: 'yearly', label: 'Yearly' }];
const STATUS_TEXT = { ok: 'On track', warning: 'Near the limit', over: 'Over budget' };

function History({ budget, onClose }) {
  const { data, loading, error, reload } = useFetch(() => client.get(`/api/budgets/${budget.id}/history`, { params: { periods: 6 } }).then((r) => r.data.data.periods), [budget.id]);

  return (
    <Modal title={`History: ${budget.name}`} onClose={onClose} wide>
      {loading && <Skeleton className="h-48 w-full" />}
      {error && <ErrorBanner message={getErrorMessage(error)} onRetry={reload} />}
      {data && (
        <>
          <BudgetHistoryChart periods={data} />
          <ul className="mt-3 divide-y divide-slate-100 text-sm dark:divide-slate-700">
            {[...data].reverse().map((p) => (
              <li key={p.period_start} className="flex items-center justify-between gap-2 py-1.5">
                <span>{formatDate(p.period_start)} – {formatDate(p.period_end)}</span>
                <span className="flex items-center gap-2"><span className="tabular-nums">{formatMoney(p.spent)} of {formatMoney(p.budget)}</span><Badge tone={statusTone(p.status)}>{p.percent_used}%</Badge></span>
              </li>
            ))}
          </ul>
        </>
      )}
    </Modal>
  );
}

export default function BudgetsPage() {
  const toast = useToast();
  const { categories } = useLookups();
  const [date, setDate] = useState(today());
  const { data, loading, error, reload } = useFetch(() => client.get('/api/budgets', { params: { date } }).then((r) => r.data.data), [date]);
  useDataChanged(reload);

  const [form, setForm] = useState(null);
  const [history, setHistory] = useState(null);
  const [remove, setRemove] = useState(null);
  const refresh = () => { reload(); announceDataChanged(); };

  const fields = (editing) => [
    { name: 'scope', label: 'For', type: 'select', required: true, disabled: editing, options: [{ value: 'personal', label: 'Personal spending' }, { value: 'business', label: 'Business operating expenses' }] },
    { name: 'category_id', label: 'Category', type: 'select', disabled: editing, placeholder: 'All expenses', hint: 'Leave on “All expenses” for a total budget. A parent category includes its subcategories.',
      options: (v) => categories.filter((c) => c.kind === 'expense' && (!v.scope || c.scope === v.scope)).map((c) => ({ value: c.id, label: c.label })) },
    { name: 'name', label: 'Name', required: true, placeholder: 'e.g. Food, Advertising' },
    { name: 'period', label: 'Period', type: 'select', required: true, disabled: editing, options: PERIODS },
    { name: 'amount', label: 'Budget amount (TSh)', type: 'money', required: true, hint: 'The most you plan to spend in each period.' },
    { name: 'start_date', label: 'Starts on', type: 'date', required: true, disabled: editing, defaultValue: today() },
    { name: 'end_date', label: 'Ends on (optional)', type: 'date' },
    ...(editing ? [{ name: 'is_active', label: 'Budget is active', type: 'checkbox' }] : []),
    { name: 'notes', label: 'Notes (optional)', type: 'textarea' },
  ];

  const budgets = data || [];

  return (
    <div>
      <PageHeader
        title="Budgets"
        subtitle="Plan spending per period and see how much is used. Only real expenses count: transfers, stock purchases, loan repayments and savings do not."
        actions={<Button variant="primary" onClick={() => setForm({})}>＋ New budget</Button>}
      />

      <div className="mb-4 flex flex-wrap items-end gap-3">
        <label className="text-sm">View the period that contains
          <input type="date" className="input mt-1" value={date} max={today()} onChange={(e) => e.target.value && setDate(e.target.value)} />
        </label>
        {date !== today() && <Button size="sm" onClick={() => setDate(today())}>Back to today</Button>}
      </div>

      {error && !data && <ErrorBanner message={getErrorMessage(error)} onRetry={reload} />}
      {loading && !data && <div className="grid gap-3 md:grid-cols-2"><Skeleton className="h-32" /><Skeleton className="h-32" /></div>}
      {data && budgets.length === 0 && <div className="card"><EmptyState title="No budgets yet" hint="Create a monthly budget for food, transport or advertising and watch it fill up as you record expenses." action={<Button variant="primary" onClick={() => setForm({})}>＋ Create a budget</Button>} /></div>}

      <div className="grid gap-3 md:grid-cols-2" aria-busy={loading}>
        {budgets.map((b) => {
          const p = b.performance;

          return (
            <article key={b.id} className={`card ${b.is_active ? '' : 'opacity-60'}`}>
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <h2 className="font-semibold">{b.name}</h2>
                  <p className="text-xs text-slate-500">{b.scope} · {b.period}{b.category ? ` · ${b.category.name}` : ' · all expenses'}</p>
                </div>
                <div className="flex gap-1">
                  {!b.is_active && <Badge>Inactive</Badge>}
                  <Badge tone={statusTone(p.status)}>{STATUS_TEXT[p.status]}</Badge>
                </div>
              </div>
              <div className="mt-3"><ProgressBar percent={p.percent_used} tone={statusTone(p.status)} label={`${b.name} used`} /></div>
              <div className="mt-2 flex flex-wrap justify-between gap-2 text-sm">
                <span>Spent <strong>{formatMoney(p.spent)}</strong> of {formatMoney(p.budget)} ({p.percent_used}%)</span>
                <span className={p.status === 'over' ? 'font-semibold text-red-600' : ''}>{p.status === 'over' ? `Over by ${formatMoney(p.remaining.replace('-', ''))}` : `Left ${formatMoney(p.remaining)}`}</span>
              </div>
              <p className="mt-1 text-xs text-slate-500">{formatDate(p.period_start)} – {formatDate(p.period_end)}{!p.applies && ' · not active in this period'}</p>
              <div className="mt-3 flex flex-wrap gap-1">
                <Button size="sm" onClick={() => setHistory(b)}>History</Button>
                <Button size="sm" onClick={() => setForm({ budget: b })}>Edit</Button>
                <Button size="sm" variant="ghost" onClick={() => setRemove(b)}>Delete</Button>
              </div>
            </article>
          );
        })}
      </div>

      {form && (
        <FormModal title={form.budget ? 'Edit budget' : 'New budget'} fields={fields(!!form.budget)}
          initial={form.budget ? { ...form.budget, category_id: form.budget.category_id ?? '', end_date: form.budget.end_date ?? '' } : { scope: 'personal', period: 'monthly' }}
          submitLabel={form.budget ? 'Save changes' : 'Create budget'} onClose={() => setForm(null)}
          onSubmit={async (values) => {
            if (form.budget) await client.put(`/api/budgets/${form.budget.id}`, { name: values.name, amount: values.amount, end_date: values.end_date || null, is_active: values.is_active, notes: values.notes });
            else await client.post('/api/budgets', { ...values, category_id: values.category_id || null, end_date: values.end_date || null });
            toast.success(form.budget ? 'Budget updated.' : 'Budget created.');
            refresh();
          }} />
      )}
      {history && <History budget={history} onClose={() => setHistory(null)} />}
      {remove && <ConfirmDialog title="Delete budget" message={`Delete the budget "${remove.name}"? Your expenses are not affected.`} confirmLabel="Delete budget" danger onCancel={() => setRemove(null)}
        onConfirm={async () => { await client.delete(`/api/budgets/${remove.id}`); toast.success('Budget deleted.'); refresh(); }} />}
    </div>
  );
}

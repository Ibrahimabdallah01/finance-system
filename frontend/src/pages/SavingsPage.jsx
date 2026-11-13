import { useState } from 'react';
import client from '../api/client';
import ConfirmDialog from '../components/ConfirmDialog';
import FormModal from '../components/FormModal';
import { accountOptions } from '../components/TransactionForms';
import { Badge, Button, EmptyState, ErrorBanner, Modal, PageHeader, ProgressBar, Skeleton, Spinner } from '../components/ui';
import { useLookups } from '../context/LookupContext';
import { useToast } from '../context/ToastContext';
import { announceDataChanged, useDataChanged } from '../hooks/useDataChanged';
import { useFetch } from '../hooks/useFetch';
import { formatDate, today } from '../lib/dates';
import { getErrorMessage } from '../lib/errors';
import { formatMoney } from '../lib/money';

const GOAL_TYPES = [
  { value: 'business_capital', label: 'Business capital' }, { value: 'stock_purchase', label: 'Buying more phone stock' }, { value: 'emergency', label: 'Emergency savings' },
  { value: 'equipment', label: 'Laptop or equipment' }, { value: 'rent', label: 'Rent' }, { value: 'education', label: 'Education' }, { value: 'other', label: 'Other' },
];
const typeLabel = (value) => GOAL_TYPES.find((t) => t.value === value)?.label || value;
const STATUS = { active: ['info', 'Saving'], achieved: ['good', 'Achieved 🎉'], cancelled: ['neutral', 'Cancelled'] };

function Contributions({ goal, onClose }) {
  const { data, loading, error, reload } = useFetch(() => client.get(`/api/savings-goals/${goal.id}`).then((r) => r.data.data), [goal.id]);

  return (
    <Modal title={`Savings history: ${goal.name}`} onClose={onClose}>
      {loading && !data && <Spinner className="h-5 w-5" />}
      {error && <ErrorBanner message={getErrorMessage(error)} onRetry={reload} />}
      {data && data.contributions.length === 0 && <p className="text-sm text-slate-500">Nothing saved yet.</p>}
      <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-700">
        {(data?.contributions || []).map((c) => (
          <li key={c.id} className={`flex justify-between gap-2 py-1.5 ${c.transaction_status === 'voided' ? 'opacity-50 line-through' : ''}`}>
            <span>{formatDate(c.contribution_date)} · {Number(c.amount) < 0 ? 'Withdrawn' : 'Saved'}{c.transaction_id === null ? ' (earmarked)' : ''}{c.note ? ` · ${c.note}` : ''}</span>
            <span className="tabular-nums">{formatMoney(c.amount)}</span>
          </li>
        ))}
      </ul>
    </Modal>
  );
}

export default function SavingsPage() {
  const toast = useToast();
  const { accounts, reload: reloadLookups } = useLookups();
  const { data, loading, error, reload } = useFetch(() => client.get('/api/savings-goals').then((r) => r.data.data), []);
  useDataChanged(reload);

  const [form, setForm] = useState(null);
  const [money, setMoney] = useState(null);  // { goal, kind: 'save' | 'withdraw' }
  const [history, setHistory] = useState(null);
  const [remove, setRemove] = useState(null);
  const refresh = () => { reload(); reloadLookups(); announceDataChanged(); };
  const goals = data || [];

  const setStatus = async (goal, status) => {
    try { await client.put(`/api/savings-goals/${goal.id}`, { status }); toast.success(status === 'cancelled' ? 'Goal cancelled.' : 'Goal reopened.'); refresh(); }
    catch (err) { toast.error(getErrorMessage(err)); }
  };

  const goalFields = (editing) => [
    { name: 'name', label: 'Goal', required: true, placeholder: 'e.g. New laptop' },
    { name: 'goal_type', label: 'What is it for?', type: 'select', required: true, options: GOAL_TYPES },
    { name: 'target_amount', label: 'Target amount (TSh)', type: 'money', required: true },
    { name: 'target_date', label: 'Target date (optional)', type: 'date', min: editing ? undefined : today(), hint: 'We show how much to save each month to get there.' },
    { name: 'account_id', label: 'Savings account (optional)', type: 'select', options: accountOptions(accounts), hint: 'Where the saved money is kept. Needed to move money into the goal.' },
    { name: 'notes', label: 'Notes (optional)', type: 'textarea' },
  ];

  return (
    <div>
      <PageHeader
        title="Savings goals"
        subtitle="Money moved into savings is a transfer: it is never counted as spending."
        actions={<Button variant="primary" onClick={() => setForm({})}>＋ New goal</Button>}
      />

      {error && !data && <ErrorBanner message={getErrorMessage(error)} onRetry={reload} />}
      {loading && !data && <div className="grid gap-3 md:grid-cols-2"><Skeleton className="h-40" /><Skeleton className="h-40" /></div>}
      {data && goals.length === 0 && <div className="card"><EmptyState title="No savings goals yet" hint="Set a target for a laptop, more stock or an emergency fund, then add money towards it." action={<Button variant="primary" onClick={() => setForm({})}>＋ Create a goal</Button>} /></div>}

      <div className="grid gap-3 md:grid-cols-2">
        {goals.map((goal) => {
          const f = goal.figures;
          const [tone, label] = STATUS[goal.status];

          return (
            <article key={goal.id} className={`card ${goal.status === 'cancelled' ? 'opacity-60' : ''}`}>
              <div className="flex items-start justify-between gap-2">
                <div>
                  <h2 className="font-semibold">{goal.name}</h2>
                  <p className="text-xs text-slate-500">{typeLabel(goal.goal_type)}{goal.account ? ` · kept in ${goal.account.name}` : ''}{goal.target_date ? ` · by ${formatDate(goal.target_date)}` : ''}</p>
                </div>
                <Badge tone={tone}>{label}</Badge>
              </div>
              <div className="mt-3"><ProgressBar percent={f.percent_complete} tone={f.is_complete ? 'good' : 'info'} label={`${goal.name} progress`} /></div>
              <div className="mt-2 flex flex-wrap justify-between gap-2 text-sm">
                <span>Saved <strong>{formatMoney(f.saved)}</strong> of {formatMoney(f.target)} ({f.percent_complete}%)</span>
                <span>{f.is_complete ? 'Target reached' : `${formatMoney(f.remaining)} to go`}</span>
              </div>
              {f.required_per_month && <p className="mt-1 text-xs text-slate-500">Save about {formatMoney(f.required_per_month)} a month for {f.months_left} month(s) to reach it on time.</p>}
              {f.target_date_passed && <p className="mt-1 text-xs text-amber-600">The target date has passed.</p>}
              <div className="mt-3 flex flex-wrap gap-1">
                {goal.status !== 'cancelled' && <Button size="sm" variant="primary" onClick={() => setMoney({ goal, kind: 'save' })}>Add money</Button>}
                {goal.status !== 'cancelled' && Number(f.saved) > 0 && <Button size="sm" onClick={() => setMoney({ goal, kind: 'withdraw' })}>Withdraw</Button>}
                <Button size="sm" onClick={() => setHistory(goal)}>History</Button>
                <Button size="sm" onClick={() => setForm({ goal })}>Edit</Button>
                {goal.status === 'cancelled' ? <Button size="sm" variant="ghost" onClick={() => setStatus(goal, 'active')}>Reopen</Button> : <Button size="sm" variant="ghost" onClick={() => setStatus(goal, 'cancelled')}>Cancel goal</Button>}
                <Button size="sm" variant="ghost" onClick={() => setRemove(goal)}>Delete</Button>
              </div>
            </article>
          );
        })}
      </div>

      {form && (
        <FormModal title={form.goal ? 'Edit goal' : 'New savings goal'} fields={goalFields(!!form.goal)}
          initial={form.goal ? { ...form.goal, account_id: form.goal.account_id ?? '', target_date: form.goal.target_date ?? '' } : { goal_type: 'other' }}
          submitLabel={form.goal ? 'Save changes' : 'Create goal'} onClose={() => setForm(null)}
          onSubmit={async (values) => {
            const body = { ...values, target_date: values.target_date || null, account_id: values.account_id || null };
            if (form.goal) await client.put(`/api/savings-goals/${form.goal.id}`, body); else await client.post('/api/savings-goals', body);
            toast.success(form.goal ? 'Goal updated.' : 'Goal created.');
            refresh();
          }} />
      )}

      {money && (
        <FormModal
          title={money.kind === 'save' ? `Add money to ${money.goal.name}` : `Withdraw from ${money.goal.name}`}
          description={money.kind === 'save'
            ? 'Choose an account to move the money from, or leave it empty to only set aside money that is already in the savings account.'
            : `You have ${formatMoney(money.goal.figures.saved)} saved. Choose where the money goes, or leave it empty to just release it from the goal.`}
          fields={[
            { name: 'amount', label: 'Amount (TSh)', type: 'money', required: true },
            { name: 'contribution_date', label: 'Date', type: 'date', required: true, max: today(), defaultValue: today() },
            { name: money.kind === 'save' ? 'from_account_id' : 'to_account_id', label: money.kind === 'save' ? 'Move from account (optional)' : 'Move to account (optional)', type: 'select', options: accountOptions(accounts).filter((o) => String(o.value) !== String(money.goal.account_id)) },
            { name: 'note', label: 'Note (optional)' },
          ]}
          submitLabel={money.kind === 'save' ? 'Add money' : 'Withdraw'} onClose={() => setMoney(null)}
          onSubmit={async (values) => {
            const response = await client.post(`/api/savings-goals/${money.goal.id}/${money.kind === 'save' ? 'contributions' : 'withdrawals'}`, values);
            (response.data.meta?.warnings || []).forEach((w) => toast.warning(w));
            toast.success(money.kind === 'save' ? 'Money added to the goal.' : 'Withdrawal recorded.');
            refresh();
          }} />
      )}

      {history && <Contributions goal={history} onClose={() => setHistory(null)} />}
      {remove && <ConfirmDialog title="Delete goal" message={`Delete "${remove.name}"? This only works for a goal with no savings recorded; otherwise cancel it.`} confirmLabel="Delete goal" danger onCancel={() => setRemove(null)}
        onConfirm={async () => { await client.delete(`/api/savings-goals/${remove.id}`); toast.success('Goal deleted.'); refresh(); }} />}
    </div>
  );
}

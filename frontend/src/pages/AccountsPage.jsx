import { useState } from 'react';
import client from '../api/client';
import ConfirmDialog from '../components/ConfirmDialog';
import DataTable from '../components/DataTable';
import FormModal from '../components/FormModal';
import { TransferFormModal } from '../components/TransactionForms';
import { Badge, Button, Modal, PageHeader, StatCard } from '../components/ui';
import { useLookups } from '../context/LookupContext';
import { useToast } from '../context/ToastContext';
import { announceDataChanged, useDataChanged } from '../hooks/useDataChanged';
import { useFetch } from '../hooks/useFetch';
import { usePagedList } from '../hooks/usePagedList';
import { getErrorMessage } from '../lib/errors';
import { formatDate, today } from '../lib/dates';
import { compareMoney, formatMoney } from '../lib/money';

const TYPES = [
  { value: 'cash', label: 'Cash in hand' },
  { value: 'bank', label: 'Bank account' },
  { value: 'mobile_money', label: 'Mobile money (M-Pesa, Airtel, Mixx…)' },
  { value: 'savings', label: 'Savings' },
  { value: 'other', label: 'Other' },
];
const typeLabel = (value) => TYPES.find((t) => t.value === value)?.label.split(' (')[0] || value;

export const Amount = ({ value, signed = false }) => {
  const negative = (compareMoney(value, '0') ?? 0) < 0;
  const positive = (compareMoney(value, '0') ?? 0) > 0;
  const text = signed && positive ? `+${formatMoney(value)}` : formatMoney(value);

  return <span className={negative ? 'text-red-600 dark:text-red-400' : signed && positive ? 'text-green-600 dark:text-green-400' : ''}>{text}</span>;
};

function Statement({ account, onClose }) {
  const [filters, setFilters] = useState({ from: '', to: '' });
  const list = usePagedList(`/api/accounts/${account.id}/ledger`, filters, { perPage: 15 });

  const columns = [
    { key: 'entry_date', header: 'Date', sortable: true, render: (r) => formatDate(r.entry_date) },
    { key: 'description', header: 'Details', render: (r) => <div><div>{r.description || r.type.replaceAll('_', ' ')}</div>{r.recipient && <div className="text-xs text-slate-500">{r.recipient}</div>}</div> },
    { key: 'amount', header: 'Amount', align: 'right', sortable: true, render: (r) => <Amount value={r.amount} signed /> },
    { key: 'balance_after', header: 'Balance after', align: 'right', render: (r) => formatMoney(r.balance_after) },
  ];

  return (
    <Modal title={`Statement: ${account.name}`} onClose={onClose} wide>
      <div className="mb-3 flex flex-wrap items-end gap-2">
        <label className="text-xs">From<input type="date" className="input mt-0.5 py-1" value={filters.from} onChange={(e) => setFilters((f) => ({ ...f, from: e.target.value }))} /></label>
        <label className="text-xs">To<input type="date" className="input mt-0.5 py-1" value={filters.to} onChange={(e) => setFilters((f) => ({ ...f, to: e.target.value }))} /></label>
        <p className="ml-auto text-sm">Current balance: <strong>{formatMoney(account.balance)}</strong></p>
      </div>
      <DataTable columns={columns} rows={list.rows} loading={list.loading} error={list.error && getErrorMessage(list.error)} onRetry={list.reload}
        emptyTitle="No transactions on this account yet" emptyHint="Opening balance: record deposits, expenses or transfers and they appear here."
        page={list.page} lastPage={list.lastPage} total={list.total} onPage={list.setPage} caption="Account statement, newest first" />
    </Modal>
  );
}

export default function AccountsPage() {
  const toast = useToast();
  const { reload: reloadLookups } = useLookups();
  const { data, loading, error, reload } = useFetch(() => client.get('/api/accounts').then((r) => r.data), []);
  useDataChanged(reload);

  const [form, setForm] = useState(null);       // { account? }
  const [statement, setStatement] = useState(null);
  const [reconcile, setReconcile] = useState(null);
  const [remove, setRemove] = useState(null);
  const [transfer, setTransfer] = useState(false);

  const accounts = data?.data || [];
  const totals = data?.meta?.totals;
  const refresh = () => { reload(); reloadLookups(); announceDataChanged(); };

  const fields = (editing) => [
    { name: 'scope', label: 'Belongs to', type: 'select', required: true, disabled: editing, options: [{ value: 'business', label: 'Business' }, { value: 'personal', label: 'Personal' }], hint: editing ? 'Cannot be changed after the account is created.' : 'Business and personal money are always kept apart.' },
    { name: 'name', label: 'Account name', required: true, placeholder: 'e.g. M-Pesa (shop)' },
    { name: 'type', label: 'Type', type: 'select', required: true, options: TYPES },
    { name: 'provider', label: 'Provider (optional)', placeholder: 'M-Pesa, Airtel Money, Mixx by Yas, bank name…' },
    { name: 'opening_balance', label: 'Opening balance (TSh)', type: 'money', required: true, allowZero: true, allowNegative: true, defaultValue: '0', hint: 'The money in the account on the opening date. Later transactions are added to it.' },
    { name: 'opening_balance_date', label: 'Opening date', type: 'date', required: true, max: today(), defaultValue: today() },
    ...(editing ? [{ name: 'is_active', label: 'Account is active', type: 'checkbox', hint: 'Inactive accounts cannot receive new transactions.' }] : []),
    { name: 'notes', label: 'Notes (optional)', type: 'textarea' },
  ];

  const columns = [
    { key: 'name', header: 'Account', sortable: true, render: (a) => <div><div className="font-medium">{a.name}</div><div className="text-xs text-slate-500">{a.provider || typeLabel(a.type)}</div></div> },
    { key: 'scope', header: 'Belongs to', sortable: true, render: (a) => <Badge tone={a.scope === 'business' ? 'info' : 'neutral'}>{a.scope}</Badge> },
    { key: 'type', header: 'Type', render: (a) => typeLabel(a.type) },
    { key: 'balance', header: 'Balance', align: 'right', sortable: true, render: (a) => <strong><Amount value={a.balance} /></strong> },
    { key: 'is_active', header: 'Status', render: (a) => (a.is_active ? <Badge tone="good">Active</Badge> : <Badge>Inactive</Badge>) },
    {
      key: 'actions', header: '', render: (a) => (
        <div className="flex flex-wrap justify-end gap-1" onClick={(e) => e.stopPropagation()}>
          <Button size="sm" onClick={() => setStatement(a)}>Statement</Button>
          <Button size="sm" onClick={() => setReconcile(a)}>Reconcile</Button>
          <Button size="sm" onClick={() => setForm({ account: a })}>Edit</Button>
          <Button size="sm" variant="ghost" onClick={() => setRemove(a)}>Delete</Button>
        </div>
      ),
    },
  ];

  return (
    <div>
      <PageHeader
        title="Accounts"
        subtitle="Cash, bank and mobile money accounts. Balances come from the opening balance plus recorded transactions."
        actions={<><Button onClick={() => setTransfer(true)}>Transfer</Button><Button variant="primary" onClick={() => setForm({})}>＋ Add account</Button></>}
      />

      <div className="mb-4 grid gap-3 sm:grid-cols-3">
        <StatCard title="Business accounts" value={totals && formatMoney(totals.business)} loading={loading && !totals} />
        <StatCard title="Personal accounts" value={totals && formatMoney(totals.personal)} loading={loading && !totals} />
        <StatCard title="All accounts together" value={totals && formatMoney(totals.all)} loading={loading && !totals} sub="Not your net worth: see the dashboard for assets and debts" />
      </div>

      <DataTable columns={columns} rows={accounts} loading={loading} error={error && getErrorMessage(error)} onRetry={reload}
        emptyTitle="No accounts yet" emptyHint="Add the places your money is kept: cash in hand, M-Pesa, your bank… Start with the amount you have today."
        emptyAction={<Button variant="primary" onClick={() => setForm({})}>＋ Add your first account</Button>} caption="Accounts and balances" />

      {form && (
        <FormModal
          title={form.account ? 'Edit account' : 'Add account'}
          fields={fields(!!form.account)}
          initial={form.account ? { ...form.account, opening_balance: form.account.opening_balance } : { scope: 'business' }}
          submitLabel={form.account ? 'Save changes' : 'Add account'}
          onClose={() => setForm(null)}
          onSubmit={async (values) => {
            if (form.account) {
              const { scope, ...rest } = values; // eslint-disable-line no-unused-vars
              await client.put(`/api/accounts/${form.account.id}`, rest);
              toast.success('Account updated.');
            } else {
              await client.post('/api/accounts', values);
              toast.success('Account added.');
            }
            refresh();
          }}
        />
      )}

      {reconcile && (
        <FormModal
          title={`Reconcile: ${reconcile.name}`}
          description={`The system says this account holds ${formatMoney(reconcile.balance)}. Count the real money (or check the statement) and enter what you actually have.`}
          fields={[
            { name: 'actual_balance', label: 'Actual balance (TSh)', type: 'money', required: true, allowZero: true, allowNegative: true },
            { name: 'reconciled_on', label: 'As of', type: 'date', required: true, max: today(), defaultValue: today() },
            { name: 'post_adjustment', label: 'Correct the account so it matches the count', type: 'checkbox', hint: 'Records a reconciliation adjustment for the difference. It is not income or an expense.' },
            { name: 'notes', label: 'Notes (optional)', type: 'textarea' },
          ]}
          submitLabel="Reconcile"
          onClose={() => setReconcile(null)}
          onSubmit={async (values) => {
            const response = await client.post(`/api/accounts/${reconcile.id}/reconciliations`, values);
            const difference = response.data.data.difference;
            if ((compareMoney(difference, '0') ?? 0) === 0) toast.success('The books match your count. No difference.');
            else toast.warning(`Difference of ${formatMoney(difference)} (actual minus recorded)${values.post_adjustment ? ' was corrected.' : ' was saved without changing the balance.'}`);
            refresh();
          }}
        />
      )}

      {transfer && <TransferFormModal onClose={() => setTransfer(false)} onDone={reload} />}
      {statement && <Statement account={statement} onClose={() => setStatement(null)} />}

      {remove && (
        <ConfirmDialog
          title="Delete account"
          message={`Delete "${remove.name}"? This only works for an account that was never used. If it has transactions, deactivate it instead (Edit → untick Active).`}
          confirmLabel="Delete account"
          danger
          onCancel={() => setRemove(null)}
          onConfirm={async () => { await client.delete(`/api/accounts/${remove.id}`); toast.success('Account deleted.'); refresh(); }}
        />
      )}
    </div>
  );
}

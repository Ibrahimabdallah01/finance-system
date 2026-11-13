import { useMemo, useState } from 'react';
import client from '../api/client';
import ConfirmDialog from '../components/ConfirmDialog';
import DataTable from '../components/DataTable';
import { TransactionFormModal } from '../components/TransactionForms';
import TransactionDetail, { TYPE_LABELS } from '../components/TransactionDetail';
import { Badge, Button, PageHeader, StatCard, Tabs } from '../components/ui';
import { useLookups } from '../context/LookupContext';
import { useToast } from '../context/ToastContext';
import { announceDataChanged } from '../hooks/useDataChanged';
import { useDebounce } from '../hooks/useDebounce';
import { usePagedList } from '../hooks/usePagedList';
import { formatDate } from '../lib/dates';
import { getErrorMessage } from '../lib/errors';
import { formatMoney } from '../lib/money';
import { Amount } from './AccountsPage';

const COPY = {
  personal: { title: 'Personal finance', subtitle: 'Your own income and spending. Business sales are never counted as personal income.', tabs: [{ key: '', label: 'All activity' }, { key: 'income', label: 'Income' }, { key: 'expense', label: 'Expenses' }], first: 'expense' },
  business: { title: 'Business expenses', subtitle: 'Running costs of the shop. Buying phones and stock is not an expense until it is sold: record it under Inventory.', tabs: [{ key: 'expense', label: 'Expenses' }, { key: '', label: 'All business activity' }], first: 'expense' },
};

export default function TransactionsPage({ scope }) {
  const copy = COPY[scope];
  const toast = useToast();
  const { categories, reload: reloadLookups } = useLookups();
  const [tab, setTab] = useState(copy.first === 'expense' && scope === 'business' ? 'expense' : '');
  const [search, setSearch] = useState('');
  const [category, setCategory] = useState('');
  const [status, setStatus] = useState('posted');
  const [dates, setDates] = useState({ from: '', to: '' });
  const debounced = useDebounce(search);

  const list = usePagedList('/api/transactions', { scope, type: tab, search: debounced, category_id: category, status, from: dates.from, to: dates.to });

  const [form, setForm] = useState(null);   // { kind, transaction? }
  const [detail, setDetail] = useState(null);
  const [voiding, setVoiding] = useState(null);

  const categoryOptions = useMemo(() => categories.filter((c) => c.scope === scope), [categories, scope]);
  const byType = list.meta?.summary?.by_type || {};

  const columns = [
    { key: 'transaction_date', header: 'Date', sortable: true, render: (t) => formatDate(t.transaction_date) },
    { key: 'description', header: 'Details', sortable: true, render: (t) => (
      <div>
        <div className="flex items-center gap-2">{t.description || TYPE_LABELS[t.type]} {t.status === 'voided' && <Badge tone="bad">Voided</Badge>}</div>
        <div className="text-xs text-slate-500">{[t.recipient, t.reference].filter(Boolean).join(' · ')}</div>
      </div>
    ) },
    { key: 'category', header: 'Category', render: (t) => (t.category?.name ? t.category.name : <Badge>{TYPE_LABELS[t.type] || t.type}</Badge>) },
    { key: 'account', header: 'Account', render: (t) => (t.entries || []).map((e) => e.account?.name).filter(Boolean).join(' → ') },
    { key: 'amount', header: 'Amount', align: 'right', sortable: true, render: (t) => (t.entries?.length === 1 ? <Amount value={t.entries[0].amount} signed /> : formatMoney(t.amount)) },
    { key: 'receipts_count', header: '📎', render: (t) => (t.receipts_count ? t.receipts_count : '') },
    { key: 'actions', header: '', render: (t) => (
      <div className="flex justify-end gap-1" onClick={(e) => e.stopPropagation()}>
        {t.status === 'posted' && (t.type === 'income' || t.type === 'expense') && <Button size="sm" onClick={() => setForm({ kind: t.type, transaction: t })}>Edit</Button>}
        {t.status === 'posted' && <Button size="sm" variant="ghost" onClick={() => setVoiding(t)}>Void</Button>}
      </div>
    ) },
  ];

  return (
    <div>
      <PageHeader
        title={copy.title}
        subtitle={copy.subtitle}
        actions={<><Button variant="primary" onClick={() => setForm({ kind: 'expense' })}>＋ Add expense</Button><Button onClick={() => setForm({ kind: 'income' })}>＋ Add {scope === 'business' ? 'other income' : 'income'}</Button></>}
      />

      <div className="mb-3 grid gap-3 sm:grid-cols-3">
        <StatCard title="Income (matching list)" value={formatMoney(byType.income?.total)} sub={`${byType.income?.count || 0} record(s)`} />
        <StatCard title="Expenses (matching list)" value={formatMoney(byType.expense?.total)} sub={`${byType.expense?.count || 0} record(s)`} />
        <StatCard title="Records shown" value={String(list.total)} sub="Voided records are never counted in totals" />
      </div>

      <Tabs tabs={copy.tabs} value={tab} onChange={setTab} />

      <div className="mb-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
        <input className="input lg:col-span-2" type="search" placeholder="Search description, name or reference…" aria-label="Search" value={search} onChange={(e) => setSearch(e.target.value)} />
        <select className="input" aria-label="Category" value={category} onChange={(e) => setCategory(e.target.value)}>
          <option value="">All categories</option>
          {categoryOptions.map((c) => <option key={c.id} value={c.id}>{c.label}</option>)}
        </select>
        <select className="input" aria-label="Status" value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="posted">Active records</option>
          <option value="voided">Voided</option>
          <option value="">Both</option>
        </select>
        <div className="flex gap-2">
          <input className="input" type="date" aria-label="From date" value={dates.from} onChange={(e) => setDates((d) => ({ ...d, from: e.target.value }))} />
          <input className="input" type="date" aria-label="To date" value={dates.to} onChange={(e) => setDates((d) => ({ ...d, to: e.target.value }))} />
        </div>
      </div>

      <DataTable columns={columns} rows={list.rows} loading={list.loading} error={list.error && getErrorMessage(list.error)} onRetry={list.reload}
        emptyTitle="No records match" emptyHint="Change the filters, or add your first expense or income."
        page={list.page} lastPage={list.lastPage} total={list.total} onPage={list.setPage} onRowClick={(t) => setDetail(t.id)} caption={`${copy.title} records`} />

      {form && <TransactionFormModal kind={form.kind} transaction={form.transaction} scope={scope} onClose={() => setForm(null)} onDone={list.reload} />}
      {detail && <TransactionDetail id={detail} onClose={() => setDetail(null)} />}
      {voiding && (
        <ConfirmDialog
          title="Void this record"
          message={`Void "${voiding.description || TYPE_LABELS[voiding.type]}" for ${formatMoney(voiding.amount)}? It stays in the history but no longer counts in balances, budgets or reports.`}
          confirmLabel="Void record"
          danger
          askReason
          onCancel={() => setVoiding(null)}
          onConfirm={async (reason) => {
            await client.post(`/api/transactions/${voiding.id}/void`, { reason });
            toast.success('Record voided.');
            reloadLookups();
            announceDataChanged();
          }}
        />
      )}
    </div>
  );
}

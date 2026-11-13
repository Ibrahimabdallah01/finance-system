import { useState } from 'react';
import client from '../api/client';
import ConfirmDialog from '../components/ConfirmDialog';
import DataTable from '../components/DataTable';
import FormModal from '../components/FormModal';
import ScheduleModal from '../components/ScheduleModal';
import { PAYMENT_METHODS, accountOptions } from '../components/TransactionForms';
import { Badge, Button, EmptyState, ErrorBanner, Modal, PageHeader, StatCard, Spinner, Tabs } from '../components/ui';
import { useLookups } from '../context/LookupContext';
import { useToast } from '../context/ToastContext';
import { announceDataChanged, useDataChanged } from '../hooks/useDataChanged';
import { useFetch } from '../hooks/useFetch';
import { formatDate, today } from '../lib/dates';
import { getErrorMessage } from '../lib/errors';
import { formatMoney, isValidAmount } from '../lib/money';

const SOURCES = { loan: 'Loan', supplier_credit: 'Supplier credit', other: 'Other' };
const STATUS_TONE = { open: 'info', settled: 'good', written_off: 'neutral', cancelled: 'neutral' };
const STATUS_LABEL = { open: 'Open', settled: 'Settled', written_off: 'Written off', cancelled: 'Cancelled' };
const SCHEDULE_TONE = { paid: 'good', partial: 'warn', overdue: 'bad', upcoming: 'neutral' };
const KIND_LABEL = { sale: 'Customer sale', debt: 'Debt', debt_installment: 'Installment' };

function DebtDetail({ id, onClose, onChanged }) {
  const toast = useToast();
  const { accounts, categories, reload: reloadLookups } = useLookups();
  const { data: debt, loading, error, reload } = useFetch(() => client.get(`/api/debts/${id}`).then((r) => r.data.data), [id]);
  const [action, setAction] = useState(null);
  const refresh = () => { reload(); reloadLookups(); onChanged?.(); announceDataChanged(); };
  const f = debt?.figures;
  const payable = debt?.direction === 'payable';

  return (
    <Modal title={debt ? `${payable ? 'I owe' : 'Owed to me'}: ${debt.party_name}` : 'Debt'} onClose={onClose} wide>
      {loading && !debt && <Spinner className="h-5 w-5" />}
      {error && !debt && <ErrorBanner message={getErrorMessage(error)} onRetry={reload} />}
      {debt && (
        <div className="space-y-5">
          <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
            <span>{SOURCES[debt.source]} · {debt.scope} · issued {formatDate(debt.issued_on)}{debt.due_date && ` · due ${formatDate(debt.due_date)}`}</span>
            <span className="flex gap-1">{f.overdue && <Badge tone="bad">{f.days_overdue} day(s) overdue</Badge>}<Badge tone={STATUS_TONE[f.status]}>{STATUS_LABEL[f.status]}</Badge></span>
          </div>
          <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {[['Original amount', f.original_amount], ['Repaid', f.repaid], ['Still outstanding', f.outstanding_balance], [payable ? 'Interest paid' : 'Interest received', f.interest_total]].map(([label, value]) => (
              <div key={label} className="card p-3"><dt className="text-xs text-slate-500">{label}</dt><dd className="mt-0.5 font-semibold">{formatMoney(value)}</dd></div>
            ))}
          </dl>

          {f.schedule.length > 0 && (
            <section>
              <h3 className="mb-1 font-medium">Repayment schedule</h3>
              <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-700">
                {f.schedule.map((row) => (
                  <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 py-1.5">
                    <span>{formatDate(row.due_date)}{row.note ? ` · ${row.note}` : ''}</span>
                    <span className="flex items-center gap-2"><span className="tabular-nums">{formatMoney(row.paid)} of {formatMoney(row.amount_due)}</span><Badge tone={SCHEDULE_TONE[row.status]}>{row.status}</Badge></span>
                  </li>
                ))}
              </ul>
            </section>
          )}

          {(debt.repayments.length > 0 || debt.interest.length > 0) && (
            <section>
              <h3 className="mb-1 font-medium">Payments</h3>
              <ul className="space-y-1 text-sm">
                {[...debt.repayments, ...debt.interest].sort((a, b) => a.transaction_date.localeCompare(b.transaction_date)).map((t) => (
                  <li key={t.id} className={`flex justify-between gap-2 ${t.status === 'voided' ? 'opacity-50 line-through' : ''}`}>
                    <span>{formatDate(t.transaction_date)} · {t.description}</span><span className="tabular-nums">{formatMoney(t.amount)}</span>
                  </li>
                ))}
              </ul>
            </section>
          )}
          {debt.notes && <p className="whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{debt.notes}</p>}

          {f.status === 'open' && (
            <div className="flex flex-wrap gap-2 border-t border-slate-200 pt-3 dark:border-slate-700">
              <Button variant="primary" onClick={() => setAction('repay')}>{payable ? 'Record repayment' : 'Record money received'}</Button>
              <Button onClick={() => setAction('schedule')}>Repayment schedule</Button>
              <Button variant="ghost" onClick={() => setAction('writeoff')}>Write off</Button>
            </div>
          )}
        </div>
      )}

      {action === 'repay' && debt && (
        <FormModal
          title={payable ? 'Record repayment' : 'Record money received'}
          description={`Outstanding: ${formatMoney(f.outstanding_balance)}. Repaying the debt itself is never an expense. Interest, if any, is recorded separately.`}
          fields={[
            { name: 'account_id', label: payable ? 'Paid from' : 'Received into', type: 'select', required: true, options: accountOptions(accounts, debt.scope) },
            { name: 'amount', label: 'Amount of the debt repaid (TSh)', type: 'money', required: true, defaultValue: f.outstanding_balance },
            { name: 'transaction_date', label: 'Date', type: 'date', required: true, max: today(), min: debt.issued_on, defaultValue: today() },
            { name: 'interest_amount', label: 'Interest paid on top (optional)', type: 'money', allowZero: true, hint: payable ? 'Recorded as an expense.' : 'Recorded as income.' },
            { name: 'interest_category_id', label: 'Interest category', type: 'select', required: true, show: (v) => isValidAmount(v.interest_amount),
              options: categories.filter((c) => c.scope === debt.scope && c.kind === (payable ? 'expense' : 'income')).map((c) => ({ value: c.id, label: c.label })) },
            { name: 'payment_method', label: 'Method (optional)', type: 'select', options: PAYMENT_METHODS },
            { name: 'reference', label: 'Reference (optional)' },
          ]}
          submitLabel="Save" onClose={() => setAction(null)}
          onSubmit={async (values) => {
            const response = await client.post(`/api/debts/${id}/repayments`, { ...values, interest_amount: values.interest_amount || null });
            (response.data.meta?.warnings || []).forEach((w) => toast.warning(w));
            toast.success('Recorded.');
            refresh();
          }} />
      )}
      {action === 'schedule' && debt && <ScheduleModal debt={debt} onClose={() => setAction(null)} onSaved={refresh} />}
      {action === 'writeoff' && (
        <ConfirmDialog title="Write off this debt" message={`Remove ${formatMoney(f.outstanding_balance)} from your totals? No money moves and profit is not changed. This cannot be undone.`}
          confirmLabel="Write off" danger askReason onCancel={() => setAction(null)}
          onConfirm={async (reason) => { await client.post(`/api/debts/${id}/write-off`, { reason }); toast.success('Debt written off.'); refresh(); }} />
      )}
    </Modal>
  );
}

export default function DebtsPage() {
  const toast = useToast();
  const { accounts, reload: reloadLookups } = useLookups();
  const [tab, setTab] = useState('payable');
  const [status, setStatus] = useState('open');
  const summary = useFetch(() => client.get('/api/debts/summary', { params: { days: 30 } }).then((r) => r.data.data), []);
  const debts = useFetch(() => client.get('/api/debts').then((r) => r.data.data), []);
  const receivables = useFetch(() => client.get('/api/receivables').then((r) => r.data.data), []);
  const reloadAll = () => { summary.reload(); debts.reload(); receivables.reload(); };
  useDataChanged(reloadAll);

  const [form, setForm] = useState(false);
  const [detail, setDetail] = useState(null);

  const s = summary.data;
  const rows = (debts.data || []).filter((d) => d.direction === tab && (!status || d.figures.status === status));

  const columns = [
    { key: 'party_name', header: 'Who', sortable: true, render: (d) => <div><div className="font-medium">{d.party_name}</div><div className="text-xs text-slate-500">{SOURCES[d.source]} · {d.scope}</div></div> },
    { key: 'original_amount', header: 'Original', align: 'right', sortable: true, render: (d) => formatMoney(d.original_amount) },
    { key: 'repaid', header: 'Repaid', align: 'right', sortValue: (d) => d.figures.repaid, sortable: true, render: (d) => formatMoney(d.figures.repaid) },
    { key: 'outstanding', header: 'Outstanding', align: 'right', sortValue: (d) => d.figures.outstanding_balance, sortable: true, render: (d) => <strong>{formatMoney(d.figures.outstanding_balance)}</strong> },
    { key: 'due_date', header: 'Due', sortable: true, render: (d) => (d.due_date ? <span>{formatDate(d.due_date)} {d.figures.overdue && <Badge tone="bad">Overdue</Badge>}</span> : '—') },
    { key: 'status', header: 'Status', render: (d) => <Badge tone={STATUS_TONE[d.figures.status]}>{STATUS_LABEL[d.figures.status]}</Badge> },
  ];

  const debtFields = [
    { name: 'direction', label: 'Who owes whom?', type: 'select', required: true, options: [{ value: 'payable', label: 'I owe someone' }, { value: 'receivable', label: 'Someone owes me' }] },
    { name: 'scope', label: 'Belongs to', type: 'select', required: true, options: [{ value: 'personal', label: 'Personal' }, { value: 'business', label: 'Business' }] },
    { name: 'source', label: 'Kind of debt', type: 'select', required: true, options: [{ value: 'loan', label: 'Loan: money moves now' }, { value: 'supplier_credit', label: 'Supplier credit' }, { value: 'other', label: 'Other: just keep track' }],
      hint: (v) => (v.source === 'loan' ? 'Borrowed money is a liability, not income. Lent money is a receivable, not an expense.' : v.source ? 'No money moves and no income or expense is recorded.' : undefined) },
    { name: 'party_name', label: 'Person or company', required: true },
    { name: 'original_amount', label: 'Amount (TSh)', type: 'money', required: true },
    { name: 'issued_on', label: 'Date', type: 'date', required: true, max: today(), defaultValue: today() },
    { name: 'due_date', label: 'Due date (optional)', type: 'date' },
    { name: 'account_id', label: 'Account', type: 'select', required: true, show: (v) => v.source === 'loan', options: (v) => accountOptions(accounts, v.scope),
      hint: (v) => (v.direction === 'receivable' ? 'The money you lent is paid out of this account.' : 'The money you borrowed is received into this account.') },
    { name: 'payment_method', label: 'Method (optional)', type: 'select', options: PAYMENT_METHODS, show: (v) => v.source === 'loan' },
    { name: 'notes', label: 'Notes (optional)', type: 'textarea' },
  ];

  return (
    <div>
      <PageHeader title="Debts" subtitle="Money you owe and money owed to you. Customer balances come from your sales and are never counted twice."
        actions={<Button variant="primary" onClick={() => setForm(true)}>＋ Add debt</Button>} />

      {summary.error && !s && <ErrorBanner message={getErrorMessage(summary.error)} onRetry={summary.reload} />}
      <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard title="Owed to me" value={s && formatMoney(s.owed_to_me.total)} loading={summary.loading && !s} sub={s && `Customers ${formatMoney(s.owed_to_me.customer_sales)} · Loans ${formatMoney(s.owed_to_me.debts)}`} />
        <StatCard title="I owe" value={s && formatMoney(s.i_owe.total)} loading={summary.loading && !s} sub={s && `Loans ${formatMoney(s.i_owe.loans)} · Suppliers ${formatMoney(s.i_owe.supplier_credit)}`} />
        <StatCard title="Overdue to me" value={s && String(s.overdue.receivable.count)} tone={s && s.overdue.receivable.count > 0 ? 'bad' : 'default'} loading={summary.loading && !s} sub={s && formatMoney(s.overdue.receivable.amount)} />
        <StatCard title="Overdue by me" value={s && String(s.overdue.payable.count)} tone={s && s.overdue.payable.count > 0 ? 'bad' : 'default'} loading={summary.loading && !s} sub={s && formatMoney(s.overdue.payable.amount)} />
      </div>

      {s && s.upcoming.length > 0 && (
        <section className="card mb-4">
          <h2 className="mb-2 font-semibold">Due in the next {s.horizon_days} days, and overdue</h2>
          <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-700">
            {s.upcoming.map((item) => (
              <li key={`${item.kind}-${item.id}-${item.due_date}`} className="flex flex-wrap items-center justify-between gap-2 py-1.5">
                <span>{formatDate(item.due_date)} · {item.party} <span className="text-xs text-slate-500">({KIND_LABEL[item.kind]}{item.reference ? ` ${item.reference}` : ''}, {item.direction === 'receivable' ? 'they pay me' : 'I pay'})</span></span>
                <span className="flex items-center gap-2"><span className="tabular-nums">{formatMoney(item.amount)}</span>{item.overdue && <Badge tone="bad">Overdue</Badge>}</span>
              </li>
            ))}
          </ul>
        </section>
      )}

      <Tabs tabs={[{ key: 'payable', label: 'I owe' }, { key: 'receivable', label: 'Owed to me (loans)' }, { key: 'customers', label: 'Customers who owe me' }]} value={tab} onChange={setTab} />

      {tab !== 'customers' && (
        <>
          <div className="mb-3 max-w-xs">
            <select className="input" aria-label="Status" value={status} onChange={(e) => setStatus(e.target.value)}>
              <option value="open">Open</option><option value="settled">Settled</option><option value="written_off">Written off</option><option value="cancelled">Cancelled</option><option value="">All</option>
            </select>
          </div>
          <DataTable columns={columns} rows={rows} loading={debts.loading} error={debts.error && getErrorMessage(debts.error)} onRetry={debts.reload} onRowClick={(d) => setDetail(d.id)}
            emptyTitle={tab === 'payable' ? 'You do not owe anyone' : 'Nobody owes you a loan'} emptyHint="Record loans, supplier credit or other money owed here." caption="Debts" />
        </>
      )}

      {tab === 'customers' && (
        <div className="card">
          {receivables.loading && !receivables.data && <Spinner className="h-5 w-5" />}
          {receivables.error && <ErrorBanner message={getErrorMessage(receivables.error)} onRetry={receivables.reload} />}
          {receivables.data && receivables.data.customers.length === 0 && <EmptyState title="No customer owes you money" hint="When a customer pays only part of a sale, the rest shows up here." />}
          <ul className="divide-y divide-slate-100 dark:divide-slate-700">
            {(receivables.data?.customers || []).map((c) => (
              <li key={c.customer?.id ?? 'walk-in'} className="py-3">
                <div className="flex flex-wrap items-center justify-between gap-2"><strong>{c.customer?.name || 'Customer'}{c.customer?.phone && <span className="ml-2 text-sm font-normal text-slate-500">{c.customer.phone}</span>}</strong><strong>{formatMoney(c.outstanding_total)}</strong></div>
                <ul className="mt-1 space-y-0.5 text-sm text-slate-600 dark:text-slate-300">
                  {c.sales.map((sale) => (
                    <li key={sale.id} className="flex justify-between gap-2"><span>{sale.sale_number} · {formatDate(sale.sale_date)}{sale.due_date && ` · due ${formatDate(sale.due_date)}`} {sale.overdue && <Badge tone="bad">Overdue</Badge>}</span><span className="tabular-nums">{formatMoney(sale.outstanding_balance)}</span></li>
                  ))}
                </ul>
              </li>
            ))}
          </ul>
          {receivables.data && <p className="mt-3 border-t border-slate-200 pt-2 text-sm dark:border-slate-700">Total owed by customers: <strong>{formatMoney(receivables.data.totals.customers)}</strong>. Record their payments on the sale (Business sales).</p>}
        </div>
      )}

      {form && (
        <FormModal title="Add debt" fields={debtFields} initial={{ direction: 'payable', scope: 'personal', source: 'loan' }} submitLabel="Add debt" onClose={() => setForm(false)}
          onSubmit={async (values) => {
            const body = { ...values, due_date: values.due_date || null };
            await client.post('/api/debts', body);
            toast.success('Debt recorded.');
            reloadLookups();
            reloadAll();
            announceDataChanged();
          }} />
      )}
      {detail && <DebtDetail id={detail} onClose={() => setDetail(null)} onChanged={reloadAll} />}
    </div>
  );
}

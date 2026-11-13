import { useState } from 'react';
import DataTable from '../components/DataTable';
import SaleDetail, { PAYMENT_LABELS, PAYMENT_TONES } from '../components/SaleDetail';
import SaleForm from '../components/SaleForm';
import { Badge, Button, PageHeader } from '../components/ui';
import { useDebounce } from '../hooks/useDebounce';
import { usePagedList } from '../hooks/usePagedList';
import { formatDate } from '../lib/dates';
import { getErrorMessage } from '../lib/errors';
import { formatMoney } from '../lib/money';

export default function SalesPage() {
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [dates, setDates] = useState({ from: '', to: '' });
  const [form, setForm] = useState(false);
  const [detail, setDetail] = useState(null);
  const debounced = useDebounce(search);
  const list = usePagedList('/api/sales', { search: debounced, status, from: dates.from, to: dates.to });

  const columns = [
    { key: 'sale_number', header: 'Sale', sortable: true, render: (s) => <strong>{s.sale_number}</strong> },
    { key: 'sale_date', header: 'Date', sortable: true, render: (s) => formatDate(s.sale_date) },
    { key: 'customer', header: 'Customer', sortValue: (s) => s.customer?.name || '', sortable: true, render: (s) => s.customer?.name || <span className="text-slate-500">Walk-in</span> },
    { key: 'items', header: 'Items', render: (s) => s.items.map((i) => i.product?.name).join(', ') },
    { key: 'total', header: 'Price', align: 'right', sortable: true, sortValue: (s) => s.figures.total, render: (s) => formatMoney(s.figures.total) },
    { key: 'outstanding', header: 'Owed', align: 'right', sortValue: (s) => s.figures.outstanding_balance, sortable: true, render: (s) => formatMoney(s.figures.outstanding_balance) },
    { key: 'profit', header: 'Net profit', align: 'right', sortValue: (s) => s.figures.net_profit, sortable: true, render: (s) => (s.status === 'cancelled' ? '—' : formatMoney(s.figures.net_profit)) },
    { key: 'status', header: 'Status', render: (s) => <Badge tone={PAYMENT_TONES[s.figures.payment_status]}>{PAYMENT_LABELS[s.figures.payment_status]}</Badge> },
  ];

  return (
    <div>
      <PageHeader
        title="Business sales"
        subtitle="Every sale with its price, what is owed and the profit. Revenue is counted on the sale date; payments only reduce what the customer owes."
        actions={<Button variant="primary" onClick={() => setForm(true)}>＋ New sale</Button>}
      />

      <div className="mb-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
        <input className="input lg:col-span-2" type="search" placeholder="Search sale number or customer…" aria-label="Search sales" value={search} onChange={(e) => setSearch(e.target.value)} />
        <select className="input" aria-label="Status" value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">All sales</option>
          <option value="completed">Completed</option>
          <option value="cancelled">Cancelled</option>
        </select>
        <input className="input" type="date" aria-label="From date" value={dates.from} onChange={(e) => setDates((d) => ({ ...d, from: e.target.value }))} />
        <input className="input" type="date" aria-label="To date" value={dates.to} onChange={(e) => setDates((d) => ({ ...d, to: e.target.value }))} />
      </div>

      <DataTable columns={columns} rows={list.rows} loading={list.loading} error={list.error && getErrorMessage(list.error)} onRetry={list.reload}
        emptyTitle="No sales yet" emptyHint="Buy stock under Inventory, then record your first sale here."
        emptyAction={<Button variant="primary" onClick={() => setForm(true)}>＋ Record a sale</Button>}
        page={list.page} lastPage={list.lastPage} total={list.total} onPage={list.setPage} onRowClick={(s) => setDetail(s.id)} caption="Sales" />

      {form && <SaleForm onClose={() => setForm(false)} onDone={(sale) => { list.reload(); setDetail(sale.id); }} />}
      {detail && <SaleDetail id={detail} onClose={() => setDetail(null)} onChanged={list.reload} />}
    </div>
  );
}

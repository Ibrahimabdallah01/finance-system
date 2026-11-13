import { useState } from 'react';
import client from '../api/client';
import DateRangeFilter from '../components/DateRangeFilter';
import { Button, ErrorBanner, PageHeader, Skeleton } from '../components/ui';
import { useFetch } from '../hooks/useFetch';
import { formatDate, presetRange } from '../lib/dates';
import { getErrorMessage } from '../lib/errors';
import { compareMoney, formatMoney } from '../lib/money';

function Line({ label, value, indent = false, strong = false, minus = false, hint }) {
  const negative = (compareMoney(value, '0') ?? 0) < 0;

  return (
    <tr className={strong ? 'border-t border-slate-300 font-semibold dark:border-slate-600' : ''} title={hint}>
      <td className={`py-1.5 pr-3 ${indent ? 'pl-5 text-slate-600 dark:text-slate-300' : ''}`}>{label}</td>
      <td className={`py-1.5 text-right tabular-nums ${negative ? 'text-red-600 dark:text-red-400' : ''}`}>{minus ? '−' : ''}{formatMoney(value)}</td>
    </tr>
  );
}

export default function ReportsPage() {
  const [range, setRange] = useState(() => ({ preset: 'month', ...presetRange('month') }));
  const pnl = useFetch(() => client.get('/api/reports/profit-loss', { params: { from: range.from, to: range.to } }).then((r) => r.data.data), [range.from, range.to]);
  const stock = useFetch(() => client.get('/api/inventory/summary', { params: { from: range.from, to: range.to } }).then((r) => r.data.data), [range.from, range.to]);
  const d = pnl.data;

  return (
    <div>
      <PageHeader
        title="Reports"
        subtitle={d ? `${formatDate(d.period.from)} to ${formatDate(d.period.to)}` : 'Profit, cash and stock for a period'}
        actions={<DateRangeFilter value={range} onChange={setRange} />}
      />

      <div className="mb-4 flex flex-wrap items-center gap-2 text-sm text-slate-500">
        <Button size="sm" disabled title="Coming in Phase 9">Export CSV</Button>
        <Button size="sm" disabled title="Coming in Phase 9">Export Excel</Button>
        <Button size="sm" disabled title="Coming in Phase 9">Export PDF</Button>
        <span>Exports arrive in the next phase.</span>
      </div>

      {pnl.error && !d && <ErrorBanner message={getErrorMessage(pnl.error)} onRetry={pnl.reload} />}
      {pnl.loading && !d && <Skeleton className="h-64 w-full" />}

      {d && (
        <div className="grid gap-4 lg:grid-cols-2">
          <section className="card" aria-labelledby="pnl-title">
            <h2 id="pnl-title" className="mb-2 font-semibold">Business profit and loss</h2>
            <table className="w-full text-sm">
              <tbody>
                <Line label="Sales" value={d.sales.gross_sales} />
                <Line label="Returns" value={d.sales.returns} indent minus />
                <Line label="Net sales" value={d.sales.net_sales} strong />
                <Line label="Cost of goods sold" value={d.cost_of_goods_sold.total} indent minus hint="Cost of what was sold, minus cost of items put back into stock" />
                <Line label="Gross profit" value={d.gross_profit} strong />
                <Line label="Inventory write-offs" value={d.inventory_write_offs} indent minus />
                <Line label="Business expenses" value={d.operating_expenses} indent minus hint="Includes delivery and repair costs of sales, counted once" />
                <Line label="Other business income" value={d.other_business_income} indent />
                <Line label="Net profit" value={d.net_profit} strong />
              </tbody>
            </table>
          </section>

          <section className="card" aria-labelledby="cash-title">
            <h2 id="cash-title" className="mb-2 font-semibold">Cash view (not profit)</h2>
            <table className="w-full text-sm">
              <tbody>
                <Line label="Received from customers" value={d.cash_view.customer_payments_received} />
                <Line label="Refunded to customers" value={d.cash_view.customer_refunds_paid} minus />
                <Line label="Paid for stock" value={d.cash_view.inventory_purchases_paid} minus hint="Lowers cash but is not an expense until the stock is sold" />
                <Line label="Paid for business expenses" value={d.cash_view.operating_expenses_paid} minus />
                <Line label="Taken out by you (owner withdrawals)" value={d.cash_view.owner_withdrawals} minus />
              </tbody>
            </table>
            <p className="mt-2 text-xs text-slate-500">Buying stock and customers paying later change your cash but not your profit.</p>
          </section>

          <section className="card lg:col-span-2" aria-labelledby="how-title">
            <h2 id="how-title" className="mb-2 font-semibold">How this is calculated</h2>
            <ul className="list-disc space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-300">{d.notes.map((n) => <li key={n}>{n}</li>)}</ul>
          </section>

          <section className="card lg:col-span-2">
            <h2 className="mb-2 font-semibold">Stock and products sold</h2>
            {stock.error && <ErrorBanner message={getErrorMessage(stock.error)} onRetry={stock.reload} />}
            {stock.data && (
              <>
                <p className="mb-2 text-sm">Stock on hand at cost: <strong>{formatMoney(stock.data.totals.stock_value_at_cost)}</strong> · {stock.data.totals.phones_in_stock} phone(s) · {stock.data.totals.counted_items_in_stock} counted item(s)</p>
                {stock.data.products_sold.length === 0 ? <p className="text-sm text-slate-500">No sales in this period.</p> : (
                  <table className="w-full text-sm"><thead><tr><th className="th">Product</th><th className="th text-right">Quantity</th><th className="th text-right">Sales before discount</th></tr></thead>
                    <tbody>{stock.data.products_sold.map((p) => <tr key={p.product_id}><td className="td">{p.name}</td><td className="td text-right">{p.quantity_sold}</td><td className="td text-right tabular-nums">{formatMoney(p.sales_before_discount)}</td></tr>)}</tbody>
                  </table>
                )}
              </>
            )}
          </section>
        </div>
      )}
    </div>
  );
}

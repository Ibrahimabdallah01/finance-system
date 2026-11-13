import { useState } from 'react';
import client from '../api/client';
import { useLookups } from '../context/LookupContext';
import { useToast } from '../context/ToastContext';
import { announceDataChanged } from '../hooks/useDataChanged';
import { useFetch } from '../hooks/useFetch';
import { formatDate, today } from '../lib/dates';
import { getErrorMessage } from '../lib/errors';
import { compareMoney, formatMoney } from '../lib/money';
import ConfirmDialog from './ConfirmDialog';
import FormModal from './FormModal';
import { PAYMENT_METHODS, accountOptions } from './TransactionForms';
import { Badge, Button, ErrorBanner, Modal, Spinner } from './ui';

export const PAYMENT_TONES = { paid: 'good', partial: 'warn', unpaid: 'bad', refund_due: 'warn', returned: 'neutral', cancelled: 'neutral' };
export const PAYMENT_LABELS = { paid: 'Paid', partial: 'Part paid', unpaid: 'Unpaid', refund_due: 'Refund due', returned: 'Returned', cancelled: 'Cancelled' };

const positive = (value) => (compareMoney(value, '0') ?? 0) > 0;

function Row({ label, value, strong, hint }) {
  return (
    <div className={`flex justify-between gap-3 py-1 ${strong ? 'border-t border-slate-200 pt-2 font-semibold dark:border-slate-700' : ''}`} title={hint}>
      <dt>{label}</dt>
      <dd className="tabular-nums">{value}</dd>
    </div>
  );
}

/** One sale: what was sold, what is owed, the profit, and everything you can do next. */
export default function SaleDetail({ id, onClose, onChanged }) {
  const toast = useToast();
  const { accounts, categories, reload: reloadLookups } = useLookups();
  const { data: sale, loading, error, reload } = useFetch(() => client.get(`/api/sales/${id}`).then((r) => r.data.data), [id]);
  const [action, setAction] = useState(null); // 'payment' | 'refund' | 'return' | 'cost' | 'cancel'

  const refresh = () => { reload(); reloadLookups(); onChanged?.(); announceDataChanged(); };
  const businessAccounts = accountOptions(accounts, 'business');
  const f = sale?.figures;
  const active = sale?.status === 'completed';

  const moneyFields = (label, amountDefault) => [
    { name: 'account_id', label, type: 'select', required: true, options: businessAccounts },
    { name: 'amount', label: 'Amount (TSh)', type: 'money', required: true, defaultValue: amountDefault },
    { name: 'transaction_date', label: 'Date', type: 'date', required: true, max: today(), min: sale?.sale_date, defaultValue: today() },
    { name: 'payment_method', label: 'Method (optional)', type: 'select', options: PAYMENT_METHODS },
    { name: 'reference', label: 'Reference (optional)' },
  ];

  const post = (path, message) => async (values) => { await client.post(`/api/sales/${id}/${path}`, values); toast.success(message); refresh(); };
  const returnable = (sale?.items || []).filter((item) => item.quantity - item.returned_quantity > 0);

  return (
    <Modal title={sale ? `Sale ${sale.sale_number}` : 'Sale'} onClose={onClose} wide>
      {loading && !sale && <Spinner className="h-5 w-5" />}
      {error && !sale && <ErrorBanner message={getErrorMessage(error)} onRetry={reload} />}
      {sale && (
        <div className="space-y-5">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="text-sm">
              {formatDate(sale.sale_date)} · {sale.customer?.name || 'Walk-in customer'}
              {sale.due_date && <> · due {formatDate(sale.due_date)}</>}
            </div>
            <Badge tone={PAYMENT_TONES[f.payment_status]}>{PAYMENT_LABELS[f.payment_status]}</Badge>
          </div>

          <div className="grid gap-5 md:grid-cols-2">
            <dl className="text-sm">
              <h3 className="mb-1 font-medium">Money</h3>
              <Row label="Subtotal" value={formatMoney(f.subtotal)} />
              {positive(f.discount) && <Row label="Discount" value={`−${formatMoney(f.discount, { symbol: false })}`} />}
              <Row label="Agreed price" value={formatMoney(f.total)} strong />
              {positive(f.returned_revenue) && <Row label="Returned" value={`−${formatMoney(f.returned_revenue, { symbol: false })}`} />}
              <Row label="Paid so far (after refunds)" value={formatMoney(f.amount_paid)} />
              <Row label="Customer still owes" value={formatMoney(f.outstanding_balance)} strong />
              {positive(f.refund_due) && <Row label="You owe the customer a refund" value={formatMoney(f.refund_due)} strong />}
            </dl>
            <dl className="text-sm">
              <h3 className="mb-1 font-medium">Profit on this sale</h3>
              <Row label="Revenue (after returns)" value={formatMoney(f.net_revenue)} hint="Counted once, on the sale date, whatever has been paid." />
              <Row label="Cost of goods sold" value={`−${formatMoney(f.cogs, { symbol: false })}`} />
              <Row label="Gross profit" value={formatMoney(f.gross_profit)} strong />
              <Row label="Delivery, repairs & other costs" value={`−${formatMoney(f.direct_costs, { symbol: false })}`} />
              <Row label="Net profit" value={formatMoney(f.net_profit)} strong />
            </dl>
          </div>

          <section>
            <h3 className="mb-1 font-medium">Items</h3>
            <ul className="divide-y divide-slate-100 text-sm dark:divide-slate-700">
              {sale.items.map((item) => (
                <li key={item.id} className="flex flex-wrap items-center justify-between gap-2 py-1.5">
                  <span>
                    {item.product?.name}{item.unit?.imei && <span className="text-slate-500"> · IMEI {item.unit.imei}</span>}
                    {item.unit?.storage && <span className="text-slate-500"> · {item.unit.storage}</span>}
                    {item.quantity > 1 && <span> × {item.quantity}</span>}
                    {item.returned_quantity > 0 && <Badge tone="warn">{item.returned_quantity} returned</Badge>}
                  </span>
                  <span className="tabular-nums">{formatMoney(item.line_total)}</span>
                </li>
              ))}
            </ul>
          </section>

          {(sale.payments.length > 0 || sale.costs.length > 0) && (
            <section>
              <h3 className="mb-1 font-medium">Payments and costs</h3>
              <ul className="space-y-1 text-sm">
                {[...sale.payments, ...sale.costs].map((t) => (
                  <li key={t.id} className="flex justify-between gap-2">
                    <span>{formatDate(t.transaction_date)} · {t.description || t.type.replaceAll('_', ' ')} {t.status === 'voided' && <Badge tone="bad">Voided</Badge>}</span>
                    <span className="tabular-nums">{formatMoney(t.amount)}</span>
                  </li>
                ))}
              </ul>
            </section>
          )}

          {sale.status === 'cancelled' && <p className="text-sm text-slate-500">This sale was cancelled{sale.cancel_reason ? `: ${sale.cancel_reason}` : ''}. It no longer counts in revenue or profit.</p>}

          {active && (
            <div className="flex flex-wrap gap-2 border-t border-slate-200 pt-3 dark:border-slate-700">
              {positive(f.outstanding_balance) && <Button variant="primary" onClick={() => setAction('payment')}>Record payment</Button>}
              {positive(f.amount_paid) && <Button onClick={() => setAction('refund')}>Refund customer</Button>}
              {returnable.length > 0 && <Button onClick={() => setAction('return')}>Record a return</Button>}
              <Button onClick={() => setAction('cost')}>Add delivery / repair cost</Button>
              <Button variant="ghost" onClick={() => setAction('cancel')}>Cancel sale</Button>
            </div>
          )}
        </div>
      )}

      {action === 'payment' && (
        <FormModal title="Record customer payment" description={`The customer owes ${formatMoney(f.outstanding_balance)}. A payment never changes the revenue; it only reduces what is owed.`}
          fields={moneyFields('Received into', f.outstanding_balance)} submitLabel="Record payment" onClose={() => setAction(null)} onSubmit={post('payments', 'Payment recorded.')} />
      )}
      {action === 'refund' && (
        <FormModal title="Refund the customer" description={`The customer has paid ${formatMoney(f.amount_paid)}. Record a return first if goods came back.`}
          fields={moneyFields('Paid out from', '')} submitLabel="Record refund" onClose={() => setAction(null)} onSubmit={post('refunds', 'Refund recorded.')} />
      )}
      {action === 'return' && (
        <FormModal title="Record a return" description="A return reverses the sale's revenue. If the phone goes back on the shelf its cost is reversed too; if it is damaged the cost stays as a loss. Refund the money separately."
          fields={[
            { name: 'sale_item_id', label: 'Item returned', type: 'select', required: true, options: returnable.map((i) => ({ value: i.id, label: `${i.product?.name}${i.unit?.imei ? ` (${i.unit.imei})` : ''}` })) },
            { name: 'quantity', label: 'Quantity', required: true, defaultValue: '1' },
            { name: 'return_date', label: 'Date', type: 'date', required: true, max: today(), min: sale.sale_date, defaultValue: today() },
            { name: 'return_amount', label: 'Amount returned (optional)', type: 'money', allowZero: true, hint: 'Leave empty to reverse the item’s share of the price (after any discount).' },
            { name: 'restock', label: 'Put the item back into stock', type: 'checkbox', defaultValue: true },
            { name: 'reason', label: 'Reason (optional)' },
          ]}
          initial={{ restock: true }} submitLabel="Record return" onClose={() => setAction(null)} onSubmit={post('returns', 'Return recorded.')} />
      )}
      {action === 'cost' && (
        <FormModal title="Add a cost to this sale" description="Delivery, repairs or other direct costs of this sale. It is a business expense counted once."
          fields={[
            { name: 'account_id', label: 'Paid from', type: 'select', required: true, options: businessAccounts },
            { name: 'category_id', label: 'Category', type: 'select', required: true, options: categories.filter((c) => c.scope === 'business' && c.kind === 'expense').map((c) => ({ value: c.id, label: c.label })) },
            { name: 'amount', label: 'Amount (TSh)', type: 'money', required: true },
            { name: 'transaction_date', label: 'Date', type: 'date', required: true, max: today(), defaultValue: today() },
            { name: 'description', label: 'Description', required: true, placeholder: 'e.g. Delivery to customer' },
            { name: 'recipient', label: 'Paid to', required: true },
          ]}
          submitLabel="Add cost" onClose={() => setAction(null)} onSubmit={post('costs', 'Cost added.')} />
      )}
      {action === 'cancel' && (
        <ConfirmDialog title="Cancel this sale" message="The sale disappears from revenue and profit and the phone goes back into stock. This only works if the customer has no money on the sale (refund first) and nothing was returned."
          confirmLabel="Cancel sale" danger askReason onCancel={() => setAction(null)}
          onConfirm={async (reason) => { await client.post(`/api/sales/${id}/cancel`, { reason }); toast.success('Sale cancelled.'); refresh(); }} />
      )}
    </Modal>
  );
}

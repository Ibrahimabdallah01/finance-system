import { useRef, useState } from 'react';
import client from '../api/client';
import { useToast } from '../context/ToastContext';
import { useFetch } from '../hooks/useFetch';
import { announceDataChanged } from '../hooks/useDataChanged';
import { formatDate } from '../lib/dates';
import { getErrorMessage } from '../lib/errors';
import { formatMoney } from '../lib/money';
import ConfirmDialog from './ConfirmDialog';
import { Badge, Button, ErrorBanner, Modal, Spinner } from './ui';

export const TYPE_LABELS = {
  income: 'Income', expense: 'Expense', transfer: 'Transfer', owner_withdrawal: 'Owner withdrawal', owner_contribution: 'Owner contribution',
  inventory_purchase: 'Stock purchase', customer_payment: 'Customer payment', customer_refund: 'Customer refund', loan_received: 'Loan received',
  loan_given: 'Loan given', debt_repayment_in: 'Repayment received', debt_repayment_out: 'Repayment made', adjustment: 'Reconciliation adjustment',
};

const EVENTS = { created: 'Recorded', updated: 'Edited', voided: 'Voided', receipt_added: 'Receipt added', receipt_removed: 'Receipt removed' };

function Receipts({ transaction }) {
  const toast = useToast();
  const input = useRef(null);
  const { data, loading, error, reload } = useFetch(() => client.get(`/api/transactions/${transaction.id}/receipts`).then((r) => r.data.data), [transaction.id]);
  const [busy, setBusy] = useState(false);
  const [remove, setRemove] = useState(null);

  const upload = async (event) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    const body = new FormData();
    body.append('file', file);
    setBusy(true);

    try {
      await client.post(`/api/transactions/${transaction.id}/receipts`, body);
      toast.success('Receipt attached.');
      reload();
      announceDataChanged();
    } catch (err) {
      toast.error(getErrorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <section>
      <div className="mb-1 flex items-center justify-between">
        <h3 className="font-medium">Receipts</h3>
        <Button size="sm" loading={busy} onClick={() => input.current?.click()}>Attach receipt</Button>
        <input ref={input} type="file" className="hidden" accept=".jpg,.jpeg,.png,.webp,.pdf,image/*,application/pdf" onChange={upload} aria-label="Choose a receipt file" />
      </div>
      <p className="mb-2 text-xs text-slate-500">Photos (JPG, PNG, WebP) or PDF, up to 5 MB each.</p>
      {loading && <Spinner />}
      {error && <ErrorBanner message={getErrorMessage(error)} onRetry={reload} />}
      {data && data.length === 0 && <p className="text-sm text-slate-500">No receipts attached.</p>}
      <ul className="space-y-1 text-sm">
        {(data || []).map((receipt) => (
          <li key={receipt.id} className="flex items-center justify-between gap-2">
            <a className="text-brand-700 underline dark:text-brand-300" href={`/api/receipts/${receipt.id}/download`}>{receipt.original_name}</a>
            <Button size="sm" variant="ghost" onClick={() => setRemove(receipt)}>Remove</Button>
          </li>
        ))}
      </ul>
      {remove && (
        <ConfirmDialog title="Remove receipt" message={`Remove "${remove.original_name}"? The file is deleted.`} confirmLabel="Remove" danger onCancel={() => setRemove(null)}
          onConfirm={async () => { await client.delete(`/api/receipts/${remove.id}`); toast.success('Receipt removed.'); reload(); announceDataChanged(); }} />
      )}
    </section>
  );
}

/** One transaction in full: details, the accounts it touched, its history and receipts. */
export default function TransactionDetail({ id, onClose }) {
  const { data, loading, error, reload } = useFetch(() => client.get(`/api/transactions/${id}`).then((r) => r.data.data), [id]);
  const history = useFetch(() => client.get(`/api/transactions/${id}/history`).then((r) => r.data.data), [id]);

  return (
    <Modal title="Transaction details" onClose={onClose} wide>
      {loading && !data && <Spinner className="h-5 w-5" />}
      {error && <ErrorBanner message={getErrorMessage(error)} onRetry={reload} />}
      {data && (
        <div className="grid gap-5 md:grid-cols-2">
          <div className="space-y-4">
            <dl className="grid grid-cols-3 gap-y-1.5 text-sm">
              <dt className="text-slate-500">Type</dt><dd className="col-span-2">{TYPE_LABELS[data.type] || data.type} {data.status === 'voided' && <Badge tone="bad">Voided</Badge>}</dd>
              <dt className="text-slate-500">Date</dt><dd className="col-span-2">{formatDate(data.transaction_date)}</dd>
              <dt className="text-slate-500">Amount</dt><dd className="col-span-2 font-semibold">{formatMoney(data.amount)}</dd>
              <dt className="text-slate-500">Belongs to</dt><dd className="col-span-2">{data.scope}</dd>
              {data.category && (<><dt className="text-slate-500">Category</dt><dd className="col-span-2">{data.category.name}</dd></>)}
              {data.description && (<><dt className="text-slate-500">Description</dt><dd className="col-span-2">{data.description}</dd></>)}
              {data.recipient && (<><dt className="text-slate-500">Paid to / from</dt><dd className="col-span-2">{data.recipient}</dd></>)}
              {data.reference && (<><dt className="text-slate-500">Reference</dt><dd className="col-span-2">{data.reference}</dd></>)}
              {data.notes && (<><dt className="text-slate-500">Notes</dt><dd className="col-span-2">{data.notes}</dd></>)}
              {data.void_reason && (<><dt className="text-slate-500">Void reason</dt><dd className="col-span-2">{data.void_reason}</dd></>)}
            </dl>
            <section>
              <h3 className="mb-1 font-medium">Accounts affected</h3>
              <ul className="space-y-1 text-sm">
                {(data.entries || []).map((entry) => (
                  <li key={entry.id} className="flex justify-between"><span>{entry.account?.name}</span><span className="tabular-nums">{formatMoney(entry.amount)}</span></li>
                ))}
              </ul>
            </section>
          </div>
          <div className="space-y-4">
            <Receipts transaction={data} />
            <section>
              <h3 className="mb-1 font-medium">History</h3>
              {history.loading && <Spinner />}
              <ul className="space-y-1 text-sm">
                {(history.data || []).map((event) => (
                  <li key={event.id} className="flex justify-between gap-2"><span>{EVENTS[event.event] || event.event}</span><span className="text-slate-500">{formatDate(event.created_at)}</span></li>
                ))}
              </ul>
            </section>
          </div>
        </div>
      )}
    </Modal>
  );
}

import { useMemo } from 'react';
import client from '../api/client';
import FormModal from './FormModal';
import { useLookups } from '../context/LookupContext';
import { useToast } from '../context/ToastContext';
import { announceDataChanged } from '../hooks/useDataChanged';
import { today } from '../lib/dates';

export const PAYMENT_METHODS = [
  { value: 'cash', label: 'Cash' },
  { value: 'mobile_money', label: 'Mobile money' },
  { value: 'bank', label: 'Bank' },
  { value: 'other', label: 'Other' },
];

export function accountOptions(accounts, scope) {
  return accounts.filter((a) => !scope || a.scope === scope).map((a) => ({ value: a.id, label: `${a.name} (${a.scope})` }));
}

function reportWarnings(toast, meta) {
  [...(meta?.warnings || []), ...(meta?.budget_warnings || [])].forEach((message) => toast.warning(message));
}

/** Add or edit an income or expense. A category must match the kind and the scope of the chosen account. */
export function TransactionFormModal({ kind, transaction, scope, onClose, onDone }) {
  const { accounts, categories, reload } = useLookups();
  const toast = useToast();
  const editing = !!transaction;
  const noun = kind === 'expense' ? 'expense' : 'income';

  const fields = useMemo(() => {
    const scopeOf = (values) => accounts.find((a) => String(a.id) === String(values.account_id))?.scope;

    return [
      { name: 'account_id', label: kind === 'expense' ? 'Paid from' : 'Received into', type: 'select', required: true, options: accountOptions(accounts, scope) },
      {
        name: 'category_id', label: 'Category', type: 'select', required: true,
        hint: (values) => (values.account_id ? undefined : 'Choose the account first: categories follow its scope.'),
        options: (values) => {
          const accountScope = scopeOf(values);
          return categories.filter((c) => c.kind === kind && (!accountScope || c.scope === accountScope)).map((c) => ({ value: c.id, label: c.label }));
        },
      },
      { name: 'amount', label: 'Amount (TSh)', type: 'money', required: true },
      { name: 'transaction_date', label: 'Date', type: 'date', required: true, max: today(), defaultValue: today() },
      { name: 'description', label: 'Description', required: true },
      {
        name: 'recipient', label: kind === 'expense' ? 'Paid to' : 'Received from',
        required: (values) => kind === 'expense' && scopeOf(values) === 'business',
        hint: (values) => (kind === 'expense' && scopeOf(values) === 'business' ? 'Required for business expenses.' : undefined),
      },
      { name: 'reference', label: 'Reference (optional)' },
      { name: 'payment_method', label: 'Payment method (optional)', type: 'select', options: PAYMENT_METHODS },
      { name: 'notes', label: 'Notes (optional)', type: 'textarea' },
    ];
  }, [accounts, categories, kind, scope]);

  const initial = editing
    ? {
        account_id: transaction.entries?.[0]?.account_id ?? '',
        category_id: transaction.category?.id ?? '',
        amount: transaction.amount,
        transaction_date: transaction.transaction_date,
        description: transaction.description ?? '',
        recipient: transaction.recipient ?? '',
        reference: transaction.reference ?? '',
        payment_method: transaction.payment_method ?? '',
        notes: transaction.notes ?? '',
      }
    : undefined;

  const submit = async (values) => {
    const path = kind === 'expense' ? '/api/expenses' : '/api/income';
    const response = editing ? await client.put(`${path}/${transaction.id}`, values) : await client.post(path, values);

    reportWarnings(toast, response.data.meta);
    toast.success(editing ? `The ${noun} was updated.` : `The ${noun} was recorded.`);
    reload();
    announceDataChanged();
    onDone?.();
  };

  return (
    <FormModal
      title={`${editing ? 'Edit' : 'Add'} ${noun}`}
      fields={fields}
      initial={initial}
      onSubmit={submit}
      onClose={onClose}
      submitLabel={editing ? 'Save changes' : `Save ${noun}`}
    />
  );
}

/** Money moved between your own accounts. It is never income or an expense. */
export function TransferFormModal({ onClose, onDone, initial }) {
  const { accounts, reload } = useLookups();
  const toast = useToast();

  const options = accountOptions(accounts);
  const fields = [
    { name: 'from_account_id', label: 'From account', type: 'select', required: true, options },
    { name: 'to_account_id', label: 'To account', type: 'select', required: true, options },
    { name: 'amount', label: 'Amount (TSh)', type: 'money', required: true },
    { name: 'transaction_date', label: 'Date', type: 'date', required: true, max: today(), defaultValue: today() },
    { name: 'description', label: 'Description (optional)' },
  ];

  const submit = async (values) => {
    const response = await client.post('/api/transfers', values);
    const type = response.data.data.type;

    reportWarnings(toast, response.data.meta);
    toast.success(
      type === 'owner_withdrawal' ? 'Recorded as an owner withdrawal (business to personal).'
        : type === 'owner_contribution' ? 'Recorded as an owner contribution (personal to business).'
          : 'Transfer recorded.',
    );
    reload();
    announceDataChanged();
    onDone?.();
  };

  return (
    <FormModal
      title="Transfer between accounts"
      description="Moving money between your own accounts does not change income or expenses. Moving between business and personal is recorded as an owner withdrawal or contribution."
      fields={fields}
      initial={initial}
      onSubmit={submit}
      onClose={onClose}
      submitLabel="Record transfer"
    />
  );
}

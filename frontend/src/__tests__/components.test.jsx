import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import ConfirmDialog from '../components/ConfirmDialog';
import DataTable from '../components/DataTable';
import DateRangeFilter from '../components/DateRangeFilter';
import Field from '../components/Field';
import FormModal from '../components/FormModal';
import { ProgressBar, StatCard } from '../components/ui';
import { validationError } from '../test/utils';

const columns = [
  { key: 'name', header: 'Name', sortable: true },
  { key: 'amount', header: 'Amount', sortable: true, align: 'right', render: (r) => `TSh ${r.amount}` },
];
const rows = [
  { id: 1, name: 'Bravo', amount: '900.00' },
  { id: 2, name: 'Alpha', amount: '10000.00' },
  { id: 3, name: 'Charlie', amount: '250.50' },
];
const names = () => screen.getAllByRole('row').slice(1).map((row) => within(row).getAllByRole('cell')[0].textContent);

describe('DataTable', () => {
  it('shows rows and sorts text and amounts correctly', async () => {
    const user = userEvent.setup();
    render(<DataTable columns={columns} rows={rows} />);

    expect(names()).toEqual(['Bravo', 'Alpha', 'Charlie']);

    await user.click(screen.getByRole('button', { name: /Name/ }));
    expect(names()).toEqual(['Alpha', 'Bravo', 'Charlie']);

    await user.click(screen.getByRole('button', { name: /Name/ }));
    expect(names()).toEqual(['Charlie', 'Bravo', 'Alpha']);

    // 10000 is bigger than 900 and 250.50: a text sort would put it first.
    await user.click(screen.getByRole('button', { name: /Amount/ }));
    expect(names()).toEqual(['Charlie', 'Bravo', 'Alpha']);
    await user.click(screen.getByRole('button', { name: /Amount/ }));
    expect(names()).toEqual(['Alpha', 'Bravo', 'Charlie']);
    expect(screen.getByRole('columnheader', { name: /Amount/ })).toHaveAttribute('aria-sort', 'descending');
  });

  it('pages through server results', async () => {
    const user = userEvent.setup();
    const onPage = vi.fn();
    render(<DataTable columns={columns} rows={rows} page={2} lastPage={3} total={61} onPage={onPage} />);

    expect(screen.getByText(/Page 2 of 3 · 61 records/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Next' }));
    await user.click(screen.getByRole('button', { name: 'Previous' }));
    expect(onPage).toHaveBeenNthCalledWith(1, 3);
    expect(onPage).toHaveBeenNthCalledWith(2, 1);
  });

  it('disables Previous on the first page and Next on the last', () => {
    const { rerender } = render(<DataTable columns={columns} rows={rows} page={1} lastPage={2} total={30} onPage={() => {}} />);
    expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled();
    rerender(<DataTable columns={columns} rows={rows} page={2} lastPage={2} total={30} onPage={() => {}} />);
    expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled();
  });

  it('explains an empty list, shows a loading state, and offers a retry on errors', async () => {
    const user = userEvent.setup();
    const onRetry = vi.fn();
    const { rerender } = render(<DataTable columns={columns} rows={[]} emptyTitle="No records match" emptyHint="Change the filters." />);
    expect(screen.getByText('No records match')).toBeInTheDocument();
    expect(screen.getByText('Change the filters.')).toBeInTheDocument();

    rerender(<DataTable columns={columns} rows={[]} loading />);
    expect(screen.queryByText('No records match')).not.toBeInTheDocument();

    rerender(<DataTable columns={columns} rows={[]} error="Cannot reach the server." onRetry={onRetry} />);
    expect(screen.getByRole('alert')).toHaveTextContent('Cannot reach the server.');
    await user.click(screen.getByRole('button', { name: 'Try again' }));
    expect(onRetry).toHaveBeenCalled();
  });

  it('calls onRowClick for a row', async () => {
    const user = userEvent.setup();
    const onRowClick = vi.fn();
    render(<DataTable columns={columns} rows={rows} onRowClick={onRowClick} />);
    await user.click(screen.getByText('Alpha'));
    expect(onRowClick).toHaveBeenCalledWith(rows[1]);
  });
});

describe('Field', () => {
  it('connects the label, the hint and the error message to the input', () => {
    render(<Field label="Amount" name="amount" type="money" value="" onChange={() => {}} hint="In shillings" error="Required" required />);
    const input = screen.getByLabelText(/Amount/);
    expect(input).toHaveAttribute('aria-invalid', 'true');
    expect(input).toHaveAccessibleDescription(/In shillings/);
    expect(input).toHaveAccessibleDescription(/Required/);
    expect(input).toHaveAttribute('inputmode', 'decimal');
  });
});

describe('FormModal', () => {
  const fields = [
    { name: 'name', label: 'Name', required: true },
    { name: 'amount', label: 'Amount', type: 'money', required: true },
    { name: 'kind', label: 'Kind', type: 'select', options: [{ value: 'a', label: 'Alpha' }, { value: 'b', label: 'Beta' }] },
    { name: 'extra', label: 'Extra', show: (v) => v.kind === 'b' },
  ];

  it('checks required fields and amounts before anything is sent', async () => {
    const user = userEvent.setup();
    const onSubmit = vi.fn();
    render(<FormModal title="New thing" fields={fields} onSubmit={onSubmit} onClose={() => {}} />);

    await user.click(screen.getByRole('button', { name: 'Save' }));
    expect(screen.getAllByText('This field is required.')).toHaveLength(2);

    await user.type(screen.getByLabelText(/Name/), 'Test');
    await user.type(screen.getByLabelText(/Amount/), '12.345');
    await user.click(screen.getByRole('button', { name: 'Save' }));
    expect(screen.getByText(/at most 2 decimals/)).toBeInTheDocument();
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it('sends only the visible fields and closes when saved', async () => {
    const user = userEvent.setup();
    const onSubmit = vi.fn().mockResolvedValue();
    const onClose = vi.fn();
    render(<FormModal title="New thing" fields={fields} onSubmit={onSubmit} onClose={onClose} />);

    await user.type(screen.getByLabelText(/Name/), 'Test');
    await user.type(screen.getByLabelText(/Amount/), '1500.50');
    await user.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledWith({ name: 'Test', amount: '1500.50', kind: '' }));
    expect(onClose).toHaveBeenCalled();

    expect(screen.queryByLabelText('Extra')).not.toBeInTheDocument();
  });

  it('shows a field only when its condition is met', async () => {
    const user = userEvent.setup();
    render(<FormModal title="New thing" fields={fields} onSubmit={vi.fn()} onClose={() => {}} />);

    expect(screen.queryByLabelText('Extra')).not.toBeInTheDocument();
    await user.selectOptions(screen.getByLabelText('Kind'), 'b');
    expect(screen.getByLabelText('Extra')).toBeInTheDocument();
  });

  it('puts the server’s messages next to the right fields and keeps the form open', async () => {
    const user = userEvent.setup();
    const onSubmit = vi.fn().mockRejectedValue(validationError({ amount: ['The amount must be greater than zero.'] }));
    const onClose = vi.fn();
    render(<FormModal title="New thing" fields={fields} onSubmit={onSubmit} onClose={onClose} />);

    await user.type(screen.getByLabelText(/Name/), 'Test');
    await user.type(screen.getByLabelText(/Amount/), '10');
    await user.click(screen.getByRole('button', { name: 'Save' }));

    expect(await screen.findByText('The amount must be greater than zero.')).toBeInTheDocument();
    expect(onClose).not.toHaveBeenCalled();
    expect(screen.getByLabelText(/Amount/)).toHaveAttribute('aria-invalid', 'true');
  });

  it('shows a banner when the failure is not about a field', async () => {
    const user = userEvent.setup();
    const failure = Object.assign(new Error('x'), { response: { status: 500, data: {} } });
    render(<FormModal title="New thing" fields={[{ name: 'name', label: 'Name' }]} onSubmit={vi.fn().mockRejectedValue(failure)} onClose={() => {}} />);

    await user.click(screen.getByRole('button', { name: 'Save' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(/Nothing was saved/);
  });

  it('closes with Escape', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    render(<FormModal title="New thing" fields={fields} onSubmit={vi.fn()} onClose={onClose} />);
    await user.keyboard('{Escape}');
    expect(onClose).toHaveBeenCalled();
  });
});

describe('ConfirmDialog', () => {
  it('needs a reason before a destructive action can be confirmed', async () => {
    const user = userEvent.setup();
    const onConfirm = vi.fn().mockResolvedValue();
    const onCancel = vi.fn();
    render(<ConfirmDialog title="Void" message="Void it?" confirmLabel="Void record" danger askReason onConfirm={onConfirm} onCancel={onCancel} />);

    const confirm = screen.getByRole('button', { name: 'Void record' });
    expect(confirm).toBeDisabled();

    await user.type(screen.getByLabelText('Reason'), 'ab');
    expect(confirm).toBeDisabled();
    await user.type(screen.getByLabelText('Reason'), 'c');
    expect(confirm).toBeEnabled();

    await user.click(confirm);
    await waitFor(() => expect(onConfirm).toHaveBeenCalledWith('abc'));
    expect(onCancel).toHaveBeenCalled();
  });

  it('shows why it failed and stays open', async () => {
    const user = userEvent.setup();
    const onCancel = vi.fn();
    const failure = validationError({ account: ['This account has transactions. Deactivate it instead of deleting it.'] });
    render(<ConfirmDialog title="Delete" message="Sure?" confirmLabel="Delete" onConfirm={vi.fn().mockRejectedValue(failure)} onCancel={onCancel} />);

    await user.click(screen.getByRole('button', { name: 'Delete' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Deactivate it instead');
    expect(onCancel).not.toHaveBeenCalled();
  });
});

describe('DateRangeFilter', () => {
  it('offers today, this week, this month, this year and a custom range', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<DateRangeFilter value={{ preset: 'month', from: '2026-10-01', to: '2026-10-14' }} onChange={onChange} />);

    for (const label of ['Today', 'This week', 'This month', 'This year', 'Custom']) {
      expect(screen.getByRole('button', { name: label })).toBeInTheDocument();
    }
    expect(screen.getByRole('button', { name: 'This month' })).toHaveAttribute('aria-pressed', 'true');

    await user.click(screen.getByRole('button', { name: 'Today' }));
    expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ preset: 'today' }));
    const today = onChange.mock.calls[0][0];
    expect(today.from).toBe(today.to);
  });

  it('accepts a custom range but not one that ends before it starts', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<DateRangeFilter value={{ preset: 'custom', from: '2026-10-01', to: '2026-10-14' }} onChange={onChange} />);

    const from = screen.getByLabelText('From');
    await user.clear(from);
    await user.type(from, '2026-10-20');
    expect(screen.getByRole('alert')).toHaveTextContent(/must not be after/);

    onChange.mockClear();
    const to = screen.getByLabelText('To');
    await user.clear(to);
    await user.type(to, '2026-10-25');
    expect(onChange).toHaveBeenLastCalledWith({ preset: 'custom', from: '2026-10-20', to: '2026-10-25' });
  });
});

describe('display components', () => {
  it('shows a statistic with its explanation', () => {
    render(<StatCard title="Net profit" value="TSh 225,000" sub="After expenses" tone="good" hint="Gross profit minus expenses" />);
    expect(screen.getByText('TSh 225,000')).toHaveClass('text-green-600');
    expect(screen.getByText('Net profit').closest('[title]')).toHaveAttribute('title', 'Gross profit minus expenses');
  });

  it('keeps a progress bar within 0 to 100 even when over budget', () => {
    render(<ProgressBar percent="140.5" tone="bad" label="Food used" />);
    expect(screen.getByRole('progressbar', { name: 'Food used' })).toHaveAttribute('aria-valuenow', '100');
  });
});

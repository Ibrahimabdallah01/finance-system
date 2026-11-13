import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { render } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ensureCsrfCookie } from '../api/client';
import App from '../App';
import { AuthProvider } from '../context/AuthContext';
import { ThemeProvider } from '../context/ThemeContext';
import { ToastProvider } from '../context/ToastContext';
import AccountsPage from '../pages/AccountsPage';
import BudgetsPage from '../pages/BudgetsPage';
import DashboardPage from '../pages/DashboardPage';
import DebtsPage from '../pages/DebtsPage';
import LoginPage from '../pages/LoginPage';
import ReportsPage from '../pages/ReportsPage';
import SavingsPage from '../pages/SavingsPage';
import TransactionsPage from '../pages/TransactionsPage';
import { apiError, mockApi, renderApp, validationError } from '../test/utils';

vi.mock('../api/client');
vi.mock('recharts', async (importOriginal) => {
  const actual = await importOriginal();
  const React = await import('react');
  // jsdom has no layout, so give every chart a fixed size.
  return { ...actual, ResponsiveContainer: ({ children }) => React.cloneElement(children, { width: 800, height: 300 }) };
});

const DASHBOARD = {
  period: { from: '2026-10-01', to: '2026-10-14' },
  cards: {
    cash: { total: '1250000.00', business: '875000.00', personal: '375000.00', accounts: [
      { id: 1, name: 'Shop cash', scope: 'business', type: 'cash', balance: '875000.00' },
      { id: 2, name: 'Wallet', scope: 'personal', type: 'mobile_money', balance: '350000.00' },
    ] },
    sales_revenue: '1150000.00', gross_profit: '245000.00', net_profit: '-20000.00',
    personal_income: '40000.00', personal_expenses: '15000.00', business_expenses: '20000.00',
    personal_savings: { accounts_total: '25000.00', goals_saved: '25000.00' },
    owed_to_me: '300000.00', i_owe: '100000.00',
    budget_usage: { count: 2, over: 1, warning: 1, highest: { name: 'Food', percent_used: '112.50', status: 'over' } },
    net_position: { assets: { cash: '1250000.00', inventory_at_cost: '0.00', receivables: '300000.00', total: '1550000.00' }, liabilities: { payables: '100000.00', total: '100000.00' }, net: '1450000.00' },
  },
  charts: {
    monthly: [{ month: '2026-10', personal_income: '40000.00', personal_expenses: '15000.00', business_sales: '1150000.00', business_cogs: '905000.00', business_expenses: '20000.00', business_gross_profit: '245000.00', business_net_profit: '225000.00' }],
    personal_expense_categories: [{ category_id: 10, name: 'Food', total: '15000.00', percent: '100.00' }],
    business_expense_categories: [{ category_id: 13, name: 'Rent', total: '20000.00', percent: '100.00' }],
    cash_flow: { granularity: 'day', points: [{ label: '2026-10-13', money_in: '100000.00', money_out: '20000.00', net: '80000.00', closing_balance: '1250000.00' }] },
    budget_vs_actual: [{ id: 1, name: 'Food', scope: 'personal', period: 'monthly', budget: '100000.00', spent: '112500.00', remaining: '-12500.00', percent_used: '112.50', status: 'over' }],
  },
  notes: ['Account balances are opening balances plus recorded transactions.'],
};

beforeEach(() => localStorage.clear());

describe('LoginPage', () => {
  const renderLogin = () => render(
    <MemoryRouter future={{ v7_startTransition: true, v7_relativeSplatPath: true }} initialEntries={['/login']}>
      <ThemeProvider><ToastProvider><AuthProvider><LoginPage /></AuthProvider></ToastProvider></ThemeProvider>
    </MemoryRouter>,
  );

  it('asks for both fields before contacting the server', async () => {
    const api = mockApi({ 'GET /api/auth/me': () => { throw apiError(401); } });
    const user = userEvent.setup();
    renderLogin();

    await user.click(await screen.findByRole('button', { name: 'Sign in' }));
    expect(screen.getByText('Enter your email address.')).toBeInTheDocument();
    expect(screen.getByText('Enter your password.')).toBeInTheDocument();
    expect(api.called('POST', '/api/auth/login')).toHaveLength(0);
  });

  it('gets the security cookie first, then signs in', async () => {
    const api = mockApi({ 'GET /api/auth/me': () => { throw apiError(401); }, 'POST /api/auth/login': () => ({ data: { id: 1, name: 'Owner', email: 'o@x.com' } }) });
    const user = userEvent.setup();
    renderLogin();

    await user.type(await screen.findByLabelText(/Email/), 'o@x.com');
    await user.type(screen.getByLabelText(/^Password/), 'secret-password');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    await waitFor(() => expect(api.called('POST', '/api/auth/login')).toHaveLength(1));
    expect(ensureCsrfCookie).toHaveBeenCalled();
    expect(api.last('POST', '/api/auth/login').body).toEqual({ email: 'o@x.com', password: 'secret-password' });
  });

  it('shows the server’s message when the password is wrong', async () => {
    mockApi({
      'GET /api/auth/me': () => { throw apiError(401); },
      'POST /api/auth/login': () => { throw validationError({ email: ['These credentials do not match our records.'] }); },
    });
    const user = userEvent.setup();
    renderLogin();

    await user.type(await screen.findByLabelText(/Email/), 'o@x.com');
    await user.type(screen.getByLabelText(/^Password/), 'wrong');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    expect(await screen.findByText('These credentials do not match our records.')).toBeInTheDocument();
  });

  it('says so when the server cannot be reached', async () => {
    mockApi({
      'GET /api/auth/me': () => { throw apiError(401); },
      'POST /api/auth/login': () => { throw Object.assign(new Error('Network Error'), { request: {} }); },
    });
    const user = userEvent.setup();
    renderLogin();

    await user.type(await screen.findByLabelText(/Email/), 'o@x.com');
    await user.type(screen.getByLabelText(/^Password/), 'pw');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    const alerts = await screen.findAllByRole('alert'); // a login page may wrap the banner in its own live region
    expect(alerts[0]).toHaveTextContent(/Cannot reach the server/);
  });
});

describe('App routing', () => {
  const renderRoute = (route) => render(
    <MemoryRouter future={{ v7_startTransition: true, v7_relativeSplatPath: true }} initialEntries={[route]}>
      <ThemeProvider><ToastProvider><AuthProvider><App /></AuthProvider></ToastProvider></ThemeProvider>
    </MemoryRouter>,
  );

  it('sends a signed-out visitor to the sign-in page', async () => {
    mockApi({ 'GET /api/auth/me': () => { throw apiError(401); } });
    renderRoute('/accounts');
    expect(await screen.findByRole('button', { name: 'Sign in' })).toBeInTheDocument();
  });

  it('shows the menu with every section once signed in', async () => {
    mockApi({ 'GET /api/dashboard': () => ({ data: DASHBOARD }) });
    renderRoute('/');

    const menu = await screen.findByRole('navigation', { name: 'Main' });
    for (const label of ['Dashboard', 'Business sales', 'Inventory', 'Business expenses', 'Personal finance', 'Accounts', 'Budgets', 'Savings', 'Debts', 'Reports', 'Settings']) {
      expect(within(menu).getByRole('link', { name: new RegExp(label) })).toBeInTheDocument();
    }
    expect(screen.getByText('Test Owner')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Quick add/ })).toBeInTheDocument();
  });

  it('switches between light and dark mode and remembers it', async () => {
    mockApi({ 'GET /api/dashboard': () => ({ data: DASHBOARD }) });
    const user = userEvent.setup();
    renderRoute('/');

    await user.click(await screen.findByRole('button', { name: /dark mode/ }));
    expect(document.documentElement).toHaveClass('dark');
    expect(localStorage.getItem('theme')).toBe('dark');

    await user.click(screen.getByRole('button', { name: /light mode/ }));
    expect(document.documentElement).not.toHaveClass('dark');
  });

  it('shows a friendly page for an unknown address', async () => {
    mockApi();
    renderRoute('/nowhere');
    expect(await screen.findByText('Page not found')).toBeInTheDocument();
  });

  it('opens Quick add with expense, income and transfer', async () => {
    mockApi({ 'GET /api/dashboard': () => ({ data: DASHBOARD }) });
    const user = userEvent.setup();
    renderRoute('/');

    await user.click(await screen.findByRole('button', { name: /Quick add/ }));
    const dialog = screen.getByRole('dialog', { name: 'Quick add' });
    expect(within(dialog).getByRole('button', { name: /Expense/ })).toBeInTheDocument();
    expect(within(dialog).getByRole('button', { name: /Income/ })).toBeInTheDocument();
    expect(within(dialog).getByRole('button', { name: /Transfer/ })).toBeInTheDocument();
  });
});

describe('DashboardPage', () => {
  it('shows the twelve summary figures in shillings', async () => {
    mockApi({ 'GET /api/dashboard': () => ({ data: DASHBOARD }) });
    renderApp(<DashboardPage />);

    expect(await screen.findByText('Cash in all accounts')).toBeInTheDocument();
    for (const title of ['Business sales revenue', 'Business gross profit', 'Business net profit', 'Personal income', 'Personal expenses', 'Business expenses', 'Personal savings', 'Money owed to me', 'Money I owe', 'Budget usage (this month)']) {
      expect(screen.getByText(title)).toBeInTheDocument();
    }
    expect(screen.getAllByText('Net financial position').length).toBeGreaterThanOrEqual(1); // the card, and the breakdown below
    expect(screen.getAllByText('TSh 1,250,000').length).toBeGreaterThan(0);
    expect(screen.getByText('Business TSh 875,000 · Personal TSh 375,000')).toBeInTheDocument();
    expect(screen.getAllByText('TSh 1,450,000').length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText('1 over, 1 near limit')).toBeInTheDocument();
  });

  it('shows a loss in red and a profit as normal text', async () => {
    mockApi({ 'GET /api/dashboard': () => ({ data: DASHBOARD }) });
    renderApp(<DashboardPage />);

    expect(await screen.findByText('-TSh 20,000')).toHaveClass('text-red-600');
    expect(screen.getByText('TSh 245,000')).toHaveClass('text-green-600');
  });

  it('keeps business and personal money apart in the list of accounts', async () => {
    mockApi({ 'GET /api/dashboard': () => ({ data: DASHBOARD }) });
    renderApp(<DashboardPage />);

    const panel = (await screen.findByText('Where the cash is')).closest('section');
    expect(within(panel).getByText('Shop cash')).toBeInTheDocument();
    expect(within(panel).getByText('business')).toBeInTheDocument();
    expect(within(panel).getByText('personal')).toBeInTheDocument();
  });

  it('asks for the chosen period when a filter button is pressed', async () => {
    const api = mockApi({ 'GET /api/dashboard': () => ({ data: DASHBOARD }) });
    const user = userEvent.setup();
    renderApp(<DashboardPage />);

    await screen.findByText('Cash in all accounts');
    const first = api.last('GET', '/api/dashboard').params;
    expect(first.from <= first.to).toBe(true);

    await user.click(screen.getByRole('button', { name: 'This year' }));
    await waitFor(() => expect(api.last('GET', '/api/dashboard').params.from).toMatch(/^\d{4}-01-01$/));

    await user.click(screen.getByRole('button', { name: 'Today' }));
    await waitFor(() => {
      const { from, to } = api.last('GET', '/api/dashboard').params;
      expect(from).toBe(to);
    });
  });

  it('draws the charts and explains the calculations', async () => {
    mockApi({ 'GET /api/dashboard': () => ({ data: DASHBOARD }) });
    renderApp(<DashboardPage />);

    expect(await screen.findByRole('img', { name: 'Monthly personal income versus expenses' })).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Monthly business profit' })).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Personal expenses by category' })).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Business expenses by category' })).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Cash flow over time' })).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Budget versus actual spending' })).toBeInTheDocument();
    expect(screen.getByText('How these numbers are calculated')).toBeInTheDocument();
  });

  it('switches the income chart between personal and business', async () => {
    mockApi({ 'GET /api/dashboard': () => ({ data: DASHBOARD }) });
    const user = userEvent.setup();
    renderApp(<DashboardPage />);

    await screen.findByRole('img', { name: 'Monthly personal income versus expenses' });
    await user.click(screen.getByRole('button', { name: 'Business' }));
    expect(screen.getByRole('img', { name: 'Monthly business sales versus costs' })).toBeInTheDocument();
  });

  it('says what is missing instead of drawing empty charts', async () => {
    const empty = { ...DASHBOARD, charts: { monthly: [], personal_expense_categories: [], business_expense_categories: [], cash_flow: { granularity: 'day', points: [] }, budget_vs_actual: [] } };
    mockApi({ 'GET /api/dashboard': () => ({ data: empty }) });
    renderApp(<DashboardPage />);

    await screen.findByText('Cash in all accounts');
    expect(screen.getByText('No money moved in this period.')).toBeInTheDocument();
    expect(screen.getByText('No active budgets apply to this period.')).toBeInTheDocument();
    expect(screen.getAllByText('No data in this period.').length).toBeGreaterThanOrEqual(4);
  });

  it('shows an error with a retry button when the server is down', async () => {
    let fail = true;
    mockApi({ 'GET /api/dashboard': () => { if (fail) throw Object.assign(new Error('Network Error'), { request: {} }); return { data: DASHBOARD }; } });
    const user = userEvent.setup();
    renderApp(<DashboardPage />);

    expect(await screen.findByRole('alert')).toHaveTextContent(/could not be loaded/);
    fail = false;
    await user.click(screen.getByRole('button', { name: 'Try again' }));
    expect(await screen.findByText('Cash in all accounts')).toBeInTheDocument();
  });
});

describe('AccountsPage', () => {
  it('lists accounts with separate business and personal totals', async () => {
    mockApi();
    renderApp(<AccountsPage />);

    expect(await screen.findByText('Shop cash')).toBeInTheDocument();
    expect(screen.getByText('Business accounts').closest('.card')).toHaveTextContent('TSh 875,000');
    expect(screen.getByText('Personal accounts').closest('.card')).toHaveTextContent('TSh 350,000');
    expect(screen.getByText('All accounts together').closest('.card')).toHaveTextContent('TSh 1,225,000');
  });

  it('adds an account with its opening balance', async () => {
    const api = mockApi({ 'POST /api/accounts': () => ({ data: { id: 3 } }) });
    const user = userEvent.setup();
    renderApp(<AccountsPage />);

    await user.click(await screen.findByRole('button', { name: /Add account/ }));
    const dialog = screen.getByRole('dialog', { name: 'Add account' });
    await user.type(within(dialog).getByLabelText(/Account name/), 'M-Pesa (shop)');
    await user.selectOptions(within(dialog).getByLabelText(/Type/), 'mobile_money');
    await user.clear(within(dialog).getByLabelText(/Opening balance/));
    await user.type(within(dialog).getByLabelText(/Opening balance/), '250000');
    await user.click(within(dialog).getByRole('button', { name: 'Add account' }));

    await waitFor(() => expect(api.called('POST', '/api/accounts')).toHaveLength(1));
    expect(api.last('POST', '/api/accounts').body).toMatchObject({ scope: 'business', name: 'M-Pesa (shop)', type: 'mobile_money', opening_balance: '250000' });
    expect(api.last('POST', '/api/accounts').body.opening_balance_date).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  });

  it('does not let the scope of an existing account be changed', async () => {
    mockApi();
    const user = userEvent.setup();
    renderApp(<AccountsPage />);

    const row = (await screen.findByText('Shop cash')).closest('tr');
    await user.click(within(row).getByRole('button', { name: 'Edit' }));
    expect(within(screen.getByRole('dialog', { name: 'Edit account' })).getByLabelText(/Belongs to/)).toBeDisabled();
  });

  it('explains why an account cannot be deleted', async () => {
    mockApi({ 'DELETE /api/accounts/1': () => { throw validationError({ account: ['This account has transactions. Deactivate it instead of deleting it.'] }); } });
    const user = userEvent.setup();
    renderApp(<AccountsPage />);

    const row = (await screen.findByText('Shop cash')).closest('tr');
    await user.click(within(row).getByRole('button', { name: 'Delete' }));
    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Delete account' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Deactivate it instead of deleting it.');
  });

  it('records a reconciliation and reports the difference', async () => {
    const api = mockApi({ 'POST /api/accounts/1/reconciliations': () => ({ data: { difference: '-100.00' } }) });
    const user = userEvent.setup();
    renderApp(<AccountsPage />);

    const row = (await screen.findByText('Shop cash')).closest('tr');
    await user.click(within(row).getByRole('button', { name: 'Reconcile' }));
    const dialog = screen.getByRole('dialog', { name: /Reconcile/ });
    expect(dialog).toHaveTextContent('TSh 875,000');
    await user.type(within(dialog).getByLabelText(/Actual balance/), '874900');
    await user.click(within(dialog).getByLabelText(/Correct the account/));
    await user.click(within(dialog).getByRole('button', { name: 'Reconcile' }));

    await waitFor(() => expect(api.called('POST', '/api/accounts/1/reconciliations')).toHaveLength(1));
    expect(api.last('POST', '/api/accounts/1/reconciliations').body).toMatchObject({ actual_balance: '874900', post_adjustment: true });
    expect(await screen.findByText(/Difference of -TSh 100/)).toBeInTheDocument();
  });

  it('shows an empty state that tells you what to do first', async () => {
    mockApi({ 'GET /api/accounts': () => ({ data: [], meta: { totals: { business: '0.00', personal: '0.00', all: '0.00' } } }) });
    renderApp(<AccountsPage />);
    expect(await screen.findByText('No accounts yet')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Add your first account/ })).toBeInTheDocument();
  });
});

const TRANSACTION = (overrides = {}) => ({
  id: 5, type: 'expense', scope: 'personal', status: 'posted', transaction_date: '2026-10-10', amount: '15000.00',
  category: { id: 10, name: 'Food', parent_id: null }, description: 'Groceries', recipient: 'Supermarket', reference: null,
  entries: [{ id: 1, account_id: 2, account: { id: 2, name: 'Wallet', scope: 'personal' }, amount: '-15000.00' }], receipts_count: 1, ...overrides,
});
const PAGE = (rows, extra = {}) => ({ data: rows, links: {}, meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length, summary: { by_type: { expense: { count: rows.length, total: '15000.00' } } }, ...extra } });

describe('TransactionsPage', () => {
  it('lists records with signed amounts, categories and accounts', async () => {
    mockApi({ 'GET /api/transactions': () => PAGE([TRANSACTION()]) });
    renderApp(<TransactionsPage scope="personal" />);

    const row = (await screen.findByText('Groceries')).closest('tr');
    expect(within(row).getByText('Food')).toBeInTheDocument();
    expect(within(row).getByText('Wallet')).toBeInTheDocument();
    expect(within(row).getByText('-TSh 15,000')).toHaveClass('text-red-600');
    expect(screen.getByText('Expenses (matching list)').closest('.card')).toHaveTextContent('TSh 15,000');
  });

  it('only asks for the right scope and tab', async () => {
    const api = mockApi({ 'GET /api/transactions': () => PAGE([]) });
    const user = userEvent.setup();
    renderApp(<TransactionsPage scope="personal" />);

    await screen.findByText('No records match');
    expect(api.last('GET', '/api/transactions').params).toMatchObject({ scope: 'personal', status: 'posted' });

    await user.click(screen.getByRole('tab', { name: 'Income' }));
    await waitFor(() => expect(api.last('GET', '/api/transactions').params.type).toBe('income'));
  });

  it('searches after you stop typing', async () => {
    const api = mockApi({ 'GET /api/transactions': () => PAGE([]) });
    const user = userEvent.setup();
    renderApp(<TransactionsPage scope="business" />);

    await screen.findByText('No records match');
    await user.type(screen.getByLabelText('Search'), 'rent');
    await waitFor(() => expect(api.last('GET', '/api/transactions').params.search).toBe('rent'));
    expect(api.last('GET', '/api/transactions').params).toMatchObject({ scope: 'business', type: 'expense' });
  });

  it('voids a record only with a reason', async () => {
    const api = mockApi({ 'GET /api/transactions': () => PAGE([TRANSACTION()]), 'POST /api/transactions/5/void': () => ({ data: {} }) });
    const user = userEvent.setup();
    renderApp(<TransactionsPage scope="personal" />);

    const row = (await screen.findByText('Groceries')).closest('tr');
    await user.click(within(row).getByRole('button', { name: 'Void' }));
    const dialog = screen.getByRole('dialog', { name: 'Void this record' });
    expect(within(dialog).getByRole('button', { name: 'Void record' })).toBeDisabled();

    await user.type(within(dialog).getByLabelText('Reason'), 'Entered twice');
    await user.click(within(dialog).getByRole('button', { name: 'Void record' }));

    await waitFor(() => expect(api.called('POST', '/api/transactions/5/void')).toHaveLength(1));
    expect(api.last('POST', '/api/transactions/5/void').body).toEqual({ reason: 'Entered twice' });
  });

  it('marks voided records and offers no edit for them', async () => {
    mockApi({ 'GET /api/transactions': () => PAGE([TRANSACTION({ status: 'voided' })]) });
    renderApp(<TransactionsPage scope="personal" />);

    const row = (await screen.findByText('Groceries')).closest('tr');
    expect(within(row).getByText('Voided')).toBeInTheDocument();
    expect(within(row).queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument();
    expect(within(row).queryByRole('button', { name: 'Void' })).not.toBeInTheDocument();
  });

  it('adds an expense whose category follows the chosen account', async () => {
    const api = mockApi({ 'GET /api/transactions': () => PAGE([]), 'POST /api/expenses': () => ({ data: { id: 9 }, meta: { warnings: [], budget_warnings: ['Budget "Food" is 85.00% used.'] } }) });
    const user = userEvent.setup();
    renderApp(<TransactionsPage scope="personal" />);

    await user.click(await screen.findByRole('button', { name: /Add expense/ }));
    const dialog = screen.getByRole('dialog', { name: 'Add expense' });
    await user.selectOptions(within(dialog).getByLabelText(/Paid from/), '2');

    const options = within(within(dialog).getByLabelText(/Category/)).getAllByRole('option').map((o) => o.textContent);
    expect(options).toContain('Food');
    expect(options).toContain('Food › Groceries');
    expect(options).not.toContain('Commissions'); // income category
    expect(options).not.toContain('Rent');        // business category

    await user.selectOptions(within(dialog).getByLabelText(/Category/), '11');
    await user.type(within(dialog).getByLabelText(/Amount/), '8500');
    await user.type(within(dialog).getByLabelText(/Description/), 'Rice and beans');
    await user.click(within(dialog).getByRole('button', { name: 'Save expense' }));

    await waitFor(() => expect(api.called('POST', '/api/expenses')).toHaveLength(1));
    expect(api.last('POST', '/api/expenses').body).toMatchObject({ account_id: '2', category_id: '11', amount: '8500', description: 'Rice and beans' });
    expect(await screen.findByText('Budget "Food" is 85.00% used.')).toBeInTheDocument();
  });

  it('requires a recipient for a business expense', async () => {
    mockApi({ 'GET /api/transactions': () => PAGE([]) });
    const user = userEvent.setup();
    renderApp(<TransactionsPage scope="business" />);

    await user.click(await screen.findByRole('button', { name: /Add expense/ }));
    const dialog = screen.getByRole('dialog', { name: 'Add expense' });
    await user.selectOptions(within(dialog).getByLabelText(/Paid from/), '1');
    await user.selectOptions(within(dialog).getByLabelText(/Category/), '13');
    await user.type(within(dialog).getByLabelText(/Amount/), '20000');
    await user.type(within(dialog).getByLabelText(/Description/), 'Shop rent');
    await user.click(within(dialog).getByRole('button', { name: 'Save expense' }));

    expect(within(dialog).getByLabelText(/Paid to/)).toBeRequired();
    expect(within(dialog).getByText('This field is required.')).toBeInTheDocument();
  });
});

describe('BudgetsPage', () => {
  const BUDGETS = [
    { id: 1, scope: 'personal', category: { id: 10, name: 'Food' }, category_id: 10, name: 'Food', period: 'monthly', amount: '100000.00', start_date: '2026-01-01', end_date: null, is_active: true, notes: null,
      performance: { period_start: '2026-10-01', period_end: '2026-10-31', budget: '100000.00', spent: '112500.00', remaining: '-12500.00', percent_used: '112.50', status: 'over', applies: true } },
    { id: 2, scope: 'business', category: null, category_id: null, name: 'Business costs', period: 'monthly', amount: '500000.00', start_date: '2026-01-01', end_date: null, is_active: true, notes: null,
      performance: { period_start: '2026-10-01', period_end: '2026-10-31', budget: '500000.00', spent: '100000.00', remaining: '400000.00', percent_used: '20.00', status: 'ok', applies: true } },
  ];

  it('shows how much of each budget is used and warns about overspending', async () => {
    mockApi({ 'GET /api/budgets': () => ({ data: BUDGETS }) });
    renderApp(<BudgetsPage />);

    expect(await screen.findByText('Over budget')).toBeInTheDocument();
    expect(screen.getByText('On track')).toBeInTheDocument();
    expect(screen.getByText(/Over by TSh 12,500/)).toBeInTheDocument();
    expect(screen.getByText(/Left TSh 400,000/)).toBeInTheDocument();
    expect(screen.getByRole('progressbar', { name: 'Food used' })).toHaveAttribute('aria-valuenow', '100');
    expect(screen.getByRole('progressbar', { name: 'Business costs used' })).toHaveAttribute('aria-valuenow', '20');
  });

  it('looks at another period when a date is picked', async () => {
    const api = mockApi({ 'GET /api/budgets': () => ({ data: BUDGETS }) });
    renderApp(<BudgetsPage />);
    await screen.findByText('Over budget');

    const input = screen.getByLabelText(/View the period that contains/);
    const { fireEvent } = await import('@testing-library/react');
    fireEvent.change(input, { target: { value: '2026-08-15' } });

    await waitFor(() => expect(api.last('GET', '/api/budgets').params.date).toBe('2026-08-15'));
  });

  it('creates a budget with only the matching expense categories on offer', async () => {
    const api = mockApi({ 'GET /api/budgets': () => ({ data: [] }), 'POST /api/budgets': () => ({ data: { id: 3 } }) });
    const user = userEvent.setup();
    renderApp(<BudgetsPage />);

    await user.click((await screen.findAllByRole('button', { name: /Create a budget|New budget/ }))[0]);
    const dialog = screen.getByRole('dialog', { name: 'New budget' });
    const options = within(within(dialog).getByLabelText(/Category/)).getAllByRole('option').map((o) => o.textContent);
    expect(options).toEqual(['All expenses', 'Food', 'Food › Groceries']);

    await user.type(within(dialog).getByLabelText(/Name/), 'Food');
    await user.selectOptions(within(dialog).getByLabelText(/Category/), '10');
    await user.type(within(dialog).getByLabelText(/Budget amount/), '100000');
    await user.click(within(dialog).getByRole('button', { name: 'Create budget' }));

    await waitFor(() => expect(api.called('POST', '/api/budgets')).toHaveLength(1));
    expect(api.last('POST', '/api/budgets').body).toMatchObject({ scope: 'personal', category_id: '10', name: 'Food', period: 'monthly', amount: '100000', end_date: null });
  });

  it('encourages a first budget when there are none', async () => {
    mockApi({ 'GET /api/budgets': () => ({ data: [] }) });
    renderApp(<BudgetsPage />);
    expect(await screen.findByText('No budgets yet')).toBeInTheDocument();
  });
});

describe('SavingsPage', () => {
  const GOAL = { id: 1, name: 'Laptop', goal_type: 'equipment', target_amount: '400000.00', target_date: '2027-02-01', status: 'active', account_id: 2, account: { id: 2, name: 'Wallet', scope: 'personal' },
    figures: { saved: '100000.00', target: '400000.00', remaining: '300000.00', percent_complete: '25.00', is_complete: false, months_left: 4, required_per_month: '75000.00', target_date_passed: false } };

  it('shows progress, what is left and what to save each month', async () => {
    mockApi({ 'GET /api/savings-goals': () => ({ data: [GOAL] }) });
    renderApp(<SavingsPage />);

    expect(await screen.findByText('Laptop')).toBeInTheDocument();
    expect(screen.getByText(/TSh 300,000 to go/)).toBeInTheDocument();
    expect(screen.getByText(/Save about TSh 75,000 a month for 4 month/)).toBeInTheDocument();
    expect(screen.getByRole('progressbar', { name: 'Laptop progress' })).toHaveAttribute('aria-valuenow', '25');
  });

  it('moves money into a goal from an account', async () => {
    const api = mockApi({ 'GET /api/savings-goals': () => ({ data: [GOAL] }), 'POST /api/savings-goals/1/contributions': () => ({ data: GOAL, meta: { warnings: [] } }) });
    const user = userEvent.setup();
    renderApp(<SavingsPage />);

    await user.click(await screen.findByRole('button', { name: 'Add money' }));
    const dialog = screen.getByRole('dialog', { name: /Add money to Laptop/ });
    await user.type(within(dialog).getByLabelText(/Amount/), '25000');
    await user.selectOptions(within(dialog).getByLabelText(/Move from account/), '1');
    await user.click(within(dialog).getByRole('button', { name: 'Add money' }));

    await waitFor(() => expect(api.called('POST', '/api/savings-goals/1/contributions')).toHaveLength(1));
    expect(api.last('POST', '/api/savings-goals/1/contributions').body).toMatchObject({ amount: '25000', from_account_id: '1' });
  });

  it('celebrates an achieved goal and hides the monthly target', async () => {
    const done = { ...GOAL, status: 'achieved', figures: { ...GOAL.figures, saved: '400000.00', remaining: '0.00', percent_complete: '100.00', is_complete: true, required_per_month: null, months_left: null } };
    mockApi({ 'GET /api/savings-goals': () => ({ data: [done] }) });
    renderApp(<SavingsPage />);

    expect(await screen.findByText(/Achieved/)).toBeInTheDocument();
    expect(screen.getByText('Target reached')).toBeInTheDocument();
    expect(screen.queryByText(/Save about/)).not.toBeInTheDocument();
  });
});

describe('DebtsPage', () => {
  const DEBT = (over = {}) => ({ id: 1, scope: 'personal', direction: 'payable', source: 'loan', party_name: 'Mama', original_amount: '300000.00', issued_on: '2026-09-20', due_date: '2026-09-30', notes: null,
    figures: { status: 'open', original_amount: '300000.00', repaid: '100000.00', outstanding_balance: '200000.00', interest_total: '0.00', overdue: true, days_overdue: 14, origin_transaction_id: 7, schedule: [] }, repayments: [], interest: [], ...over });
  const SUMMARY = {
    owed_to_me: { debts: '100000.00', customer_sales: '550000.00', total: '650000.00' }, i_owe: { total: '300000.00', loans: '300000.00', supplier_credit: '0.00', other: '0.00' },
    overdue: { receivable: { count: 1, amount: '550000.00' }, payable: { count: 1, amount: '200000.00' } }, horizon_days: 30,
    upcoming: [{ kind: 'sale', direction: 'receivable', party: 'Asha', id: 1, reference: 'S-00001', due_date: '2026-10-12', amount: '550000.00', overdue: true }],
  };
  const RECEIVABLES = { customers: [{ customer: { id: 1, name: 'Asha', phone: '0712000000' }, outstanding_total: '550000.00', sales: [{ id: 1, sale_number: 'S-00001', sale_date: '2026-10-09', due_date: '2026-10-12', outstanding_balance: '550000.00', overdue: true }] }], debts: [], totals: { customers: '550000.00', debts: '0.00', all: '550000.00' } };
  const routes = { 'GET /api/debts/summary': () => ({ data: SUMMARY }), 'GET /api/debts': () => ({ data: [DEBT()] }), 'GET /api/receivables': () => ({ data: RECEIVABLES }) };

  it('summarises what you owe and what is owed to you, without double counting', async () => {
    mockApi(routes);
    renderApp(<DebtsPage />);

    expect(await screen.findByText('TSh 650,000')).toBeInTheDocument();
    expect(screen.getByText(/Customers TSh 550,000 · Loans TSh 100,000/)).toBeInTheDocument();
    expect(screen.getAllByText('TSh 300,000').length).toBeGreaterThanOrEqual(1); // the card, and the debt below
    expect(screen.getByText(/Due in the next 30 days, and overdue/)).toBeInTheDocument();
    expect(screen.getByText(/Customer sale S-00001/)).toBeInTheDocument();
  });

  it('lists debts with what is outstanding and flags the late ones', async () => {
    mockApi(routes);
    renderApp(<DebtsPage />);

    const row = (await screen.findByText('Mama')).closest('tr');
    expect(within(row).getByText('TSh 200,000')).toBeInTheDocument();
    expect(within(row).getByText('Overdue')).toBeInTheDocument();
  });

  it('shows customers who still owe money on sales', async () => {
    mockApi(routes);
    const user = userEvent.setup();
    renderApp(<DebtsPage />);

    await user.click(await screen.findByRole('tab', { name: 'Customers who owe me' }));
    const customer = (await screen.findByText('Asha')).closest('li');
    expect(within(customer).getByText(/S-00001/)).toBeInTheDocument();
    expect(screen.getByText(/Total owed by customers:/).closest('p')).toHaveTextContent('TSh 550,000');
  });

  it('repays a debt and records interest separately', async () => {
    const api = mockApi({ ...routes, 'GET /api/debts/1': () => ({ data: DEBT() }), 'POST /api/debts/1/repayments': () => ({ data: DEBT(), meta: { warnings: [] } }) });
    const user = userEvent.setup();
    renderApp(<DebtsPage />);

    await user.click((await screen.findByText('Mama')).closest('tr'));
    await user.click(await screen.findByRole('button', { name: 'Record repayment' }));
    const dialog = screen.getByRole('dialog', { name: 'Record repayment' });

    expect(within(dialog).queryByLabelText(/Interest category/)).not.toBeInTheDocument();
    await user.selectOptions(within(dialog).getByLabelText(/Paid from/), '2');
    await user.clear(within(dialog).getByLabelText(/Amount of the debt repaid/));
    await user.type(within(dialog).getByLabelText(/Amount of the debt repaid/), '100000');
    await user.type(within(dialog).getByLabelText(/Interest paid on top/), '5000');

    const category = within(dialog).getByLabelText(/Interest category/);
    expect(within(category).getAllByRole('option').map((o) => o.textContent)).toEqual(['Choose…', 'Food', 'Food › Groceries']); // personal expense categories only
    await user.selectOptions(category, '10');
    await user.click(within(dialog).getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(api.called('POST', '/api/debts/1/repayments')).toHaveLength(1));
    expect(api.last('POST', '/api/debts/1/repayments').body).toMatchObject({ account_id: '2', amount: '100000', interest_amount: '5000', interest_category_id: '10' });
  });

  it('only offers accounts that match the debt when recording a loan', async () => {
    mockApi(routes);
    const user = userEvent.setup();
    renderApp(<DebtsPage />);

    await user.click(await screen.findByRole('button', { name: /Add debt/ }));
    const dialog = screen.getByRole('dialog', { name: 'Add debt' });
    const account = within(dialog).getByLabelText(/^Account/);
    expect(within(account).getAllByRole('option').map((o) => o.textContent)).toEqual(['Choose…', 'Wallet (personal)']);

    await user.selectOptions(within(dialog).getByLabelText(/Belongs to/), 'business');
    expect(within(account).getAllByRole('option').map((o) => o.textContent)).toEqual(['Choose…', 'Shop cash (business)']);

    await user.selectOptions(within(dialog).getByLabelText(/Kind of debt/), 'other');
    expect(within(dialog).queryByLabelText(/^Account/)).not.toBeInTheDocument();
  });
});

describe('ReportsPage', () => {
  it('shows profit and loss next to a separate cash view', async () => {
    mockApi({
      'GET /api/reports/profit-loss': () => ({ data: {
        period: { from: '2026-10-01', to: '2026-10-14' },
        sales: { gross_sales: '1150000.00', returns: '0.00', net_sales: '1150000.00' },
        cost_of_goods_sold: { sold: '905000.00', restocked_returns: '0.00', total: '905000.00' },
        gross_profit: '245000.00', inventory_write_offs: '0.00', operating_expenses: '20000.00', other_business_income: '0.00', net_profit: '225000.00',
        cash_view: { customer_payments_received: '850000.00', customer_refunds_paid: '0.00', inventory_purchases_paid: '905000.00', operating_expenses_paid: '20000.00', owner_withdrawals: '50000.00' },
        notes: ['Buying stock lowers cash but is not an expense.'],
      } }),
      'GET /api/inventory/summary': () => ({ data: { totals: { stock_value_at_cost: '0.00', phones_in_stock: 0, counted_items_in_stock: 0 }, products_sold: [] } }),
    });
    renderApp(<ReportsPage />);

    const pnl = (await screen.findByText('Business profit and loss')).closest('section');
    expect(within(pnl).getByText('TSh 225,000')).toBeInTheDocument();
    const cash = screen.getByText('Cash view (not profit)').closest('section');
    expect(within(cash).getByText(/905,000/)).toBeInTheDocument();
    expect(screen.getByText('Buying stock lowers cash but is not an expense.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Export PDF' })).toBeDisabled();
  });
});

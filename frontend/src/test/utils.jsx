import { render } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { vi } from 'vitest';
import client from '../api/client';
import { AuthProvider } from '../context/AuthContext';
import { LookupProvider } from '../context/LookupContext';
import { ThemeProvider } from '../context/ThemeContext';
import { ToastProvider } from '../context/ToastContext';
import { I18nProvider } from '../i18n/I18nContext';

export const USER = { id: 1, name: 'Test Owner', email: 'owner@example.com', business: { id: 1, name: 'Test Phones', currency: 'TZS' } };

/** What an axios error looks like to the app. */
export const apiError = (status, data = {}) => Object.assign(new Error(`HTTP ${status}`), { response: { status, data } });

export const validationError = (errors, message = 'The given data was invalid.') => apiError(422, { message, errors });

export const ACCOUNTS = [
  { id: 1, scope: 'business', name: 'Shop cash', type: 'cash', provider: null, opening_balance: '1000000.00', opening_balance_date: '2026-01-01', is_active: true, notes: null, balance: '875000.00' },
  { id: 2, scope: 'personal', name: 'Wallet', type: 'mobile_money', provider: 'M-Pesa', opening_balance: '200000.00', opening_balance_date: '2026-01-01', is_active: true, notes: null, balance: '350000.00' },
];

export const CATEGORY_TREE = [
  { id: 10, scope: 'personal', kind: 'expense', name: 'Food', parent_id: null, is_active: true, children: [{ id: 11, scope: 'personal', kind: 'expense', name: 'Groceries', parent_id: 10, is_active: true }] },
  { id: 12, scope: 'personal', kind: 'income', name: 'Commissions', parent_id: null, is_active: true, children: [] },
  { id: 13, scope: 'business', kind: 'expense', name: 'Rent', parent_id: null, is_active: true, children: [] },
];

/**
 * Describe the API for a test: { 'GET /api/accounts': ({ params, body }) => data, ... }.
 * Handlers return the JSON body (or throw apiError(...)). Unknown calls fail loudly.
 * Sign-in, accounts and categories have defaults so most tests only add what they need.
 */
export function mockApi(routes = {}) {
  const all = {
    'GET /api/auth/me': () => ({ data: USER }),
    'GET /api/accounts': () => ({ data: ACCOUNTS, meta: { totals: { business: '875000.00', personal: '350000.00', all: '1225000.00' } } }),
    'GET /api/categories': () => ({ data: CATEGORY_TREE }),
    ...routes,
  };
  const calls = [];

  const handle = (method) => (url, second, third) => {
    const hasBody = method === 'post' || method === 'put';
    const config = hasBody ? third : second;
    const body = hasBody ? second : undefined;
    calls.push({ method: method.toUpperCase(), url, params: config?.params, body });

    const handler = all[`${method.toUpperCase()} ${url}`];
    if (!handler) return Promise.reject(apiError(404, { message: `No mock for ${method.toUpperCase()} ${url}` }));

    return Promise.resolve()
      .then(() => handler({ params: config?.params, body }))
      .then((data) => ({ data }));
  };

  client.get.mockReset().mockImplementation(handle('get'));
  client.post.mockReset().mockImplementation(handle('post'));
  client.put.mockReset().mockImplementation(handle('put'));
  client.delete.mockReset().mockImplementation(handle('delete'));

  return {
    calls,
    called: (method, url) => calls.filter((c) => c.method === method && c.url === url),
    last: (method, url) => calls.filter((c) => c.method === method && c.url === url).at(-1),
  };
}

/** Renders inside everything the real app provides (router, theme, toasts, sign-in and lookups). */
export function renderApp(ui, { route = '/' } = {}) {
  return render(
    <MemoryRouter future={{ v7_startTransition: true, v7_relativeSplatPath: true }} initialEntries={[route]}>
      <I18nProvider>
        <ThemeProvider>
          <ToastProvider>
            <AuthProvider>
              <LookupProvider>{ui}</LookupProvider>
            </AuthProvider>
          </ToastProvider>
        </ThemeProvider>
      </I18nProvider>
    </MemoryRouter>,
  );
}

export { vi };

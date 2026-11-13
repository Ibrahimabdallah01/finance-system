import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import LanguageSwitcher from '../components/LanguageSwitcher';
import { Modal } from '../components/ui';
import { AuthProvider } from '../context/AuthContext';
import { ThemeProvider } from '../context/ThemeContext';
import { ToastProvider } from '../context/ToastContext';
import { I18nProvider, translate, useI18n } from '../i18n/I18nContext';
import { LANGUAGES, TRANSLATIONS } from '../i18n/translations';
import { formatDate, formatMonth, setDateLocale } from '../lib/dates';
import { mockApi } from '../test/utils';

vi.mock('../api/client');

const DASHBOARD = {
  period: { from: '2026-10-01', to: '2026-10-14' },
  cards: {
    cash: { total: '1250000.00', business: '875000.00', personal: '375000.00', accounts: [{ id: 1, name: 'Shop cash', scope: 'business', type: 'cash', balance: '875000.00' }] },
    sales_revenue: '0.00', gross_profit: '0.00', net_profit: '0.00', personal_income: '0.00', personal_expenses: '0.00', business_expenses: '0.00',
    personal_savings: { accounts_total: '0.00', goals_saved: '0.00' }, owed_to_me: '2990000.00', i_owe: '370000.00',
    budget_usage: { count: 0, over: 0, warning: 0, highest: null },
    net_position: { assets: { cash: '1250000.00', inventory_at_cost: '0.00', receivables: '2990000.00', total: '4240000.00' }, liabilities: { payables: '370000.00', total: '370000.00' }, net: '3870000.00' },
  },
  charts: { monthly: [], personal_expense_categories: [], business_expense_categories: [], cash_flow: { granularity: 'day', points: [] }, budget_vs_actual: [] },
  notes: [],
};

const renderApp = (route = '/') => render(
  <MemoryRouter future={{ v7_startTransition: true, v7_relativeSplatPath: true }} initialEntries={[route]}>
    <I18nProvider><ThemeProvider><ToastProvider><AuthProvider><App /></AuthProvider></ToastProvider></ThemeProvider></I18nProvider>
  </MemoryRouter>,
);

beforeEach(() => {
  localStorage.clear();
  document.documentElement.removeAttribute('dir');
  document.documentElement.lang = 'en';
  setDateLocale(null);
});

describe('translations', () => {
  it('offers five languages including Swahili', () => {
    expect(LANGUAGES.map((l) => l.code)).toEqual(['en', 'sw', 'fr', 'es', 'ar']);
    expect(LANGUAGES.find((l) => l.code === 'sw').label).toBe('Kiswahili');
  });

  it('has every word in every language, with the same placeholders', () => {
    const english = Object.keys(TRANSLATIONS.en);

    for (const { code } of LANGUAGES) {
      expect(Object.keys(TRANSLATIONS[code]).sort(), code).toEqual([...english].sort());

      for (const key of english) {
        const placeholders = (text) => (text.match(/\{\w+\}/g) || []).sort().join();
        expect(placeholders(TRANSLATIONS[code][key]), `${code} ${key}`).toBe(placeholders(TRANSLATIONS.en[key]));
        expect(TRANSLATIONS[code][key].trim(), `${code} ${key}`).not.toBe('');
      }
    }
  });

  it('fills placeholders and falls back to English instead of showing a raw key', () => {
    expect(translate('sw', 'dash.morning', { name: 'Ibrahim' })).toBe('Habari za asubuhi, Ibrahim');
    expect(translate('fr', 'card.cashSub', { business: 'A', personal: 'B' })).toBe('Pro A · Perso B');
    expect(translate('es', 'dash.period', { from: '1', to: '2' })).toBe('Del 1 al 2');
    expect(translate('sw', 'not.a.real.key')).toBe('not.a.real.key');
    expect(translate('xx', 'nav.dashboard')).toBe('Dashboard');
    expect(translate('en', 'dash.morning')).toBe('Good morning, {name}'); // unfilled placeholder stays visible
  });

  it('uses the words the owner asked for in Swahili for debts', () => {
    expect(TRANSLATIONS.sw['card.owed']).toBe('Ninachodai');
    expect(TRANSLATIONS.sw['card.owe']).toBe('Ninachodaiwa');
  });

  it('shows dates in the chosen language', () => {
    expect(formatDate('2026-10-13')).toBe('13 Oct 2026');
    setDateLocale('fr-FR');
    expect(formatDate('2026-10-13')).toMatch(/13 oct\.? 2026/);
    expect(formatMonth('2026-03')).toMatch(/mars 2026/);
    setDateLocale(null);
    expect(formatMonth('2026-03')).toBe('Mar 2026');
  });
});

describe('LanguageSwitcher', () => {
  function Probe() {
    const { t, dir, lang } = useI18n();
    return <p data-testid="probe">{t('nav.accounts')}|{dir}|{lang}</p>;
  }

  const setup = () => render(<I18nProvider><LanguageSwitcher /><Probe /></I18nProvider>);

  it('lists the languages and switches the whole app, remembering the choice', async () => {
    const user = userEvent.setup();
    setup();
    expect(screen.getByTestId('probe')).toHaveTextContent('Accounts|ltr|en');

    await user.click(screen.getByRole('button', { name: /Language: English/ }));
    const menu = screen.getByRole('menu', { name: 'Language' });
    expect(within(menu).getAllByRole('menuitemradio').map((i) => i.textContent.replace(/EN|SW|FR|ES|AR/g, ''))).toEqual(['English', 'Kiswahili', 'Français', 'Español', 'العربية']);

    await user.click(within(menu).getByRole('menuitemradio', { name: /Kiswahili/ }));
    expect(screen.getByTestId('probe')).toHaveTextContent('Akaunti|ltr|sw');
    expect(localStorage.getItem('language')).toBe('sw');
    expect(document.documentElement.lang).toBe('sw');
    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
  });

  it('turns the page right-to-left for Arabic and back again', async () => {
    const user = userEvent.setup();
    setup();

    await user.click(screen.getByRole('button', { name: /Language/ }));
    await user.click(screen.getByRole('menuitemradio', { name: /العربية/ }));
    expect(document.documentElement.dir).toBe('rtl');
    expect(screen.getByTestId('probe')).toHaveTextContent('الحسابات|rtl|ar');

    await user.click(screen.getByRole('button', { name: /اللغة/ }));
    await user.click(screen.getByRole('menuitemradio', { name: /English/ }));
    expect(document.documentElement.dir).toBe('ltr');
  });

  it('starts in the language that was saved', () => {
    localStorage.setItem('language', 'fr');
    setup();
    expect(screen.getByTestId('probe')).toHaveTextContent('Comptes|ltr|fr');
  });

  it('closes with Escape', async () => {
    const user = userEvent.setup();
    setup();
    await user.click(screen.getByRole('button', { name: /Language/ }));
    expect(screen.getByRole('menu')).toBeInTheDocument();
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
  });
});

describe('the app in another language', () => {
  it('shows the menu, header and dashboard in Swahili', async () => {
    localStorage.setItem('language', 'sw');
    mockApi({ 'GET /api/dashboard': () => ({ data: DASHBOARD }) });
    renderApp();

    const menu = await screen.findByRole('navigation', { name: 'Kuu' });
    for (const label of ['Dashibodi', 'Mauzo ya biashara', 'Hesabu ya bidhaa', 'Matumizi ya biashara', 'Fedha binafsi', 'Akaunti', 'Bajeti', 'Akiba', 'Madeni', 'Ripoti', 'Mipangilio']) {
      expect(within(menu).getByRole('link', { name: new RegExp(label) })).toBeInTheDocument();
    }
    expect(screen.getByRole('button', { name: /Ongeza haraka/ })).toBeInTheDocument();

    expect(await screen.findByText('Ninachodai')).toBeInTheDocument();
    expect(screen.getByText('Ninachodaiwa')).toBeInTheDocument();
    expect(screen.getByText('Fedha katika akaunti zote')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Mwezi huu' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Leo' })).toBeInTheDocument();
    expect(screen.getAllByText('TSh 2,990,000').length).toBeGreaterThan(0);
  });

  it('switches language from the header without reloading', async () => {
    mockApi({ 'GET /api/dashboard': () => ({ data: DASHBOARD }) });
    const user = userEvent.setup();
    renderApp();

    await screen.findByText('Money owed to me');
    await user.click(screen.getByRole('button', { name: /Language/ }));
    await user.click(screen.getByRole('menuitemradio', { name: /Français/ }));

    expect(await screen.findByText('On me doit')).toBeInTheDocument();
    expect(screen.getByText('Je dois')).toBeInTheDocument();
    expect(screen.getByRole('navigation', { name: 'Principal' })).toBeInTheDocument();
    expect(screen.queryByText('Money owed to me')).not.toBeInTheDocument();
  });

  it('opens Quick add in Spanish', async () => {
    localStorage.setItem('language', 'es');
    mockApi({ 'GET /api/dashboard': () => ({ data: DASHBOARD }) });
    const user = userEvent.setup();
    renderApp();

    await user.click(await screen.findByRole('button', { name: /Añadir rápido/ }));
    const dialog = screen.getByRole('dialog', { name: 'Añadir rápido' });
    expect(within(dialog).getByRole('button', { name: /Gasto/ })).toBeInTheDocument();
    expect(within(dialog).getByRole('button', { name: /Ingreso/ })).toBeInTheDocument();
    expect(within(dialog).getByRole('button', { name: /Transferencia/ })).toBeInTheDocument();
  });
});

describe('popups and side panels', () => {
  it('shows a short form as a centred popup', () => {
    render(<Modal title="Add account" onClose={() => {}}>content</Modal>);
    expect(screen.getByRole('dialog', { name: 'Add account' })).toHaveClass('animate-scale-in', 'max-w-lg');
  });

  it('shows long forms and detail views as a side panel', () => {
    render(<Modal title="New sale" onClose={() => {}} wide>content</Modal>);
    expect(screen.getByRole('dialog', { name: 'New sale' })).toHaveClass('animate-slide-in-end', 'max-w-3xl');
  });

  it('closes with Escape and when you click outside', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    const { container } = render(<Modal title="Add account" onClose={onClose}>content</Modal>);

    await user.keyboard('{Escape}');
    expect(onClose).toHaveBeenCalledTimes(1);

    await user.click(container.querySelector('.fixed.inset-0'));
    await waitFor(() => expect(onClose).toHaveBeenCalledTimes(2));
  });
});

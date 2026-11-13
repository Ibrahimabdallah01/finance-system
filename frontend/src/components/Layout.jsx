import { Suspense, useEffect, useRef, useState } from 'react';
import { NavLink, Outlet } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { useTheme } from '../context/ThemeContext';
import { useI18n } from '../i18n/I18nContext';
import Icon from './Icon';
import LanguageSwitcher from './LanguageSwitcher';
import { TransactionFormModal, TransferFormModal } from './TransactionForms';
import { Button, Modal, Spinner } from './ui';

export const NAV = [
  { to: '/', key: 'nav.dashboard', icon: 'dashboard', end: true },
  { to: '/sales', key: 'nav.sales', icon: 'sales' },
  { to: '/inventory', key: 'nav.inventory', icon: 'inventory' },
  { to: '/business-expenses', key: 'nav.businessExpenses', icon: 'expenses' },
  { to: '/personal', key: 'nav.personal', icon: 'personal' },
  { to: '/accounts', key: 'nav.accounts', icon: 'accounts' },
  { to: '/budgets', key: 'nav.budgets', icon: 'budgets' },
  { to: '/savings', key: 'nav.savings', icon: 'savings' },
  { to: '/debts', key: 'nav.debts', icon: 'debts' },
  { to: '/reports', key: 'nav.reports', icon: 'reports' },
  { to: '/settings', key: 'nav.settings', icon: 'settings' },
];

function Brand() {
  return (
    <div className="flex items-center gap-3 px-5 py-5">
      <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-violet-600 text-white shadow-lg shadow-brand-600/30">
        <Icon name="trend" className="h-5 w-5" />
      </div>
      <div>
        <p className="text-base font-semibold leading-tight tracking-tight">FinanceFlow</p>
        <p className="text-[10px] font-medium uppercase tracking-[0.18em] text-slate-400">Finance</p>
      </div>
    </div>
  );
}

function SidebarLinks({ onNavigate }) {
  const { t } = useI18n();

  return (
    <nav aria-label={t('nav.main')} className="flex flex-col gap-1 px-3 pb-4">
      {NAV.map((item) => (
        <NavLink
          key={item.to}
          to={item.to}
          end={item.end}
          onClick={onNavigate}
          className={({ isActive }) =>
            `group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition duration-200 ${
              isActive
                ? 'bg-gradient-to-r from-brand-500/15 to-violet-500/10 text-brand-700 dark:text-white'
                : 'text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-white/[0.05] dark:hover:text-slate-100'
            }`
          }
        >
          {({ isActive }) => (
            <>
              {isActive && <span className="absolute inset-y-2 start-0 w-1 rounded-full bg-gradient-to-b from-brand-400 to-violet-500" aria-hidden="true" />}
              <span className={`flex h-8 w-8 items-center justify-center rounded-lg transition ${isActive ? 'bg-brand-500/15 text-brand-600 dark:text-brand-300' : 'text-slate-400 group-hover:text-slate-600 dark:group-hover:text-slate-200'}`}>
                <Icon name={item.icon} className="h-[18px] w-[18px]" />
              </span>
              {t(item.key)}
            </>
          )}
        </NavLink>
      ))}
    </nav>
  );
}

function QuickAdd() {
  const { t } = useI18n();
  const [step, setStep] = useState(null); // null | 'choose' | 'expense' | 'income' | 'transfer'
  const close = () => setStep(null);
  const options = [
    { key: 'expense', icon: 'arrowUp', tone: 'text-rose-500 bg-rose-500/10' },
    { key: 'income', icon: 'arrowDown', tone: 'text-emerald-500 bg-emerald-500/10' },
    { key: 'transfer', icon: 'debts', tone: 'text-brand-500 bg-brand-500/10' },
  ];

  return (
    <>
      <Button variant="primary" onClick={() => setStep('choose')}>
        <Icon name="plus" className="h-4 w-4" />
        <span className="hidden sm:inline">{t('header.quickAdd')}</span>
        <span className="sr-only sm:hidden">{t('header.quickAdd')}</span>
      </Button>
      {step === 'choose' && (
        <Modal title={t('quick.title')} onClose={close}>
          <p className="mb-4 text-sm text-slate-600 dark:text-slate-300">{t('quick.ask')}</p>
          <div className="grid gap-3 sm:grid-cols-3">
            {options.map((option) => (
              <button key={option.key} type="button" onClick={() => setStep(option.key)} className="card card-hover flex flex-col items-center gap-2 py-5 text-sm font-medium">
                <span className={`flex h-11 w-11 items-center justify-center rounded-2xl ${option.tone}`}><Icon name={option.icon} className="h-5 w-5" /></span>
                {t(`quick.${option.key}`)}
              </button>
            ))}
          </div>
          <p className="mt-4 text-xs text-slate-500 dark:text-slate-400">{t('quick.note')}</p>
        </Modal>
      )}
      {(step === 'expense' || step === 'income') && <TransactionFormModal kind={step} onClose={close} />}
      {step === 'transfer' && <TransferFormModal onClose={close} />}
    </>
  );
}

function UserMenu() {
  const { user, logout } = useAuth();
  const { t } = useI18n();
  const [open, setOpen] = useState(false);
  const ref = useRef(null);

  useEffect(() => {
    if (!open) return undefined;
    const close = (event) => { if (!ref.current?.contains(event.target)) setOpen(false); };
    const key = (event) => { if (event.key === 'Escape') setOpen(false); };
    document.addEventListener('mousedown', close);
    document.addEventListener('keydown', key);
    return () => { document.removeEventListener('mousedown', close); document.removeEventListener('keydown', key); };
  }, [open]);

  return (
    <div className="relative" ref={ref}>
      <button type="button" aria-haspopup="menu" aria-expanded={open} aria-label={t('header.account')} onClick={() => setOpen((o) => !o)}
        className="flex items-center gap-2 rounded-xl border border-slate-200 bg-white py-1.5 ps-1.5 pe-3 text-sm transition hover:bg-slate-50 dark:border-white/10 dark:bg-white/[0.04] dark:hover:bg-white/[0.08]">
        <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-gradient-to-br from-brand-500 to-violet-600 text-xs font-semibold text-white">{(user?.name || '?').slice(0, 1).toUpperCase()}</span>
        <span className="hidden max-w-[9rem] truncate font-medium sm:inline">{user?.name}</span>
        <Icon name="chevron" className={`h-3.5 w-3.5 text-slate-400 transition ${open ? 'rotate-180' : ''}`} />
      </button>
      {open && (
        <div role="menu" className="absolute end-0 z-50 mt-2 w-60 animate-scale-in rounded-xl border border-slate-200 bg-white p-1.5 shadow-xl dark:border-white/10 dark:bg-ink-700">
          <div className="px-3 py-2">
            <p className="truncate text-sm font-semibold">{user?.business?.name || user?.name}</p>
            <p className="truncate text-xs text-slate-500 dark:text-slate-400">{user?.email}</p>
          </div>
          <button type="button" role="menuitem" onClick={logout} className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-rose-600 hover:bg-rose-50 dark:text-rose-300 dark:hover:bg-rose-500/10">
            <Icon name="logout" className="h-4 w-4" />
            {t('header.signOut')}
          </button>
        </div>
      )}
    </div>
  );
}

export default function Layout() {
  const { user } = useAuth();
  const { theme, toggle } = useTheme();
  const { t } = useI18n();
  const [open, setOpen] = useState(false);

  return (
    <div className="min-h-screen lg:ps-64">
      <aside className="fixed inset-y-0 start-0 z-30 hidden w-64 overflow-y-auto border-e border-slate-200 bg-white/80 backdrop-blur-xl dark:border-white/[0.06] dark:bg-ink-800/80 lg:block">
        <Brand />
        <SidebarLinks />
      </aside>

      {open && (
        <div className="fixed inset-0 z-40 lg:hidden" role="dialog" aria-label="Menu">
          <div className="absolute inset-0 animate-fade-in bg-ink-900/60 backdrop-blur-sm" onClick={() => setOpen(false)} />
          <aside className="absolute inset-y-0 start-0 w-72 animate-slide-in-start overflow-y-auto bg-white dark:bg-ink-800 rtl:animate-slide-in-end">
            <div className="flex items-center justify-between pe-3">
              <Brand />
              <button type="button" className="btn-ghost btn-sm" onClick={() => setOpen(false)} aria-label={t('header.closeMenu')}><Icon name="x" className="h-4 w-4" /></button>
            </div>
            <SidebarLinks onNavigate={() => setOpen(false)} />
          </aside>
        </div>
      )}

      <header className="sticky top-0 z-20 flex items-center gap-2 border-b border-slate-200 bg-white/75 px-4 py-3 backdrop-blur-xl dark:border-white/[0.06] dark:bg-ink-900/75 sm:px-6">
        <button type="button" className="btn-secondary btn-sm lg:hidden" onClick={() => setOpen(true)} aria-label={t('header.openMenu')}><Icon name="menu" className="h-4 w-4" /></button>
        <div className="flex-1 truncate text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">{user?.business?.name || 'FinanceFlow'}</div>
        <LanguageSwitcher />
        <QuickAdd />
        <Button size="sm" className="!rounded-xl !px-2.5 !py-2" onClick={toggle} aria-label={theme === 'dark' ? t('header.lightMode') : t('header.darkMode')}>
          <Icon name={theme === 'dark' ? 'sun' : 'moon'} className="h-4 w-4" />
        </Button>
        <UserMenu />
      </header>

      <main className="mx-auto max-w-7xl p-4 sm:p-6">
        <Suspense fallback={<div className="flex justify-center py-16"><Spinner className="h-6 w-6 text-brand-500" /></div>}>
          <Outlet />
        </Suspense>
      </main>
    </div>
  );
}

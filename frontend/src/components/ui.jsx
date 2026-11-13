import { useEffect, useRef } from 'react';
import { useI18n } from '../i18n/I18nContext';
import Icon from './Icon';

export function Spinner({ className = 'h-4 w-4' }) {
  return (
    <svg className={`animate-spin ${className}`} viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
    </svg>
  );
}

export function Button({ variant = 'secondary', size, loading = false, disabled, children, className = '', ...rest }) {
  const base = { primary: 'btn-primary', secondary: 'btn-secondary', danger: 'btn-danger', ghost: 'btn-ghost' }[variant];

  return (
    <button type="button" className={`${base} ${size === 'sm' ? 'btn-sm' : ''} ${className}`} disabled={disabled || loading} {...rest}>
      {loading && <Spinner />}
      {children}
    </button>
  );
}

export function Skeleton({ className = 'h-6 w-full' }) {
  return <div className={`skeleton ${className}`} aria-hidden="true" />;
}

export function ErrorBanner({ message, onRetry }) {
  const { t } = useI18n();

  return (
    <div role="alert" className="flex items-center justify-between gap-3 rounded-xl border border-rose-300/70 bg-rose-50 p-3 text-sm text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200">
      <span className="flex items-center gap-2"><Icon name="alert" className="h-4 w-4 shrink-0" />{message}</span>
      {onRetry && (
        <button type="button" className="btn-secondary btn-sm" onClick={onRetry}>
          {t('common.tryAgain')}
        </button>
      )}
    </div>
  );
}

export function EmptyState({ title, hint, action }) {
  return (
    <div className="flex flex-col items-center justify-center gap-2 px-4 py-12 text-center animate-fade-in">
      <div className="mb-1 flex h-14 w-14 animate-float items-center justify-center rounded-2xl bg-gradient-to-br from-brand-500/15 to-violet-500/15 text-brand-500 dark:text-brand-300">
        <Icon name="wallet" className="h-7 w-7" />
      </div>
      <p className="font-semibold">{title}</p>
      {hint && <p className="max-w-md text-sm text-slate-500 dark:text-slate-400">{hint}</p>}
      {action}
    </div>
  );
}

const BADGES = {
  neutral: 'bg-slate-100 text-slate-700 dark:bg-white/[0.08] dark:text-slate-200',
  good: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
  bad: 'bg-rose-100 text-rose-800 dark:bg-rose-500/15 dark:text-rose-300',
  warn: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
  info: 'bg-brand-100 text-brand-800 dark:bg-brand-500/15 dark:text-brand-200',
};

export function Badge({ tone = 'neutral', children }) {
  return <span className={`inline-block rounded-full px-2.5 py-0.5 text-xs font-medium ${BADGES[tone]}`}>{children}</span>;
}

const BARS = { good: 'from-emerald-500 to-emerald-400', warn: 'from-amber-500 to-amber-400', bad: 'from-rose-500 to-rose-400', info: 'from-brand-500 to-violet-500' };

/** percent can be a number or a numeric string. The bar stops at 100 % but the text shows the real value. */
export function ProgressBar({ percent, tone = 'info', label }) {
  const value = Math.max(0, Math.min(100, Number(percent) || 0));

  return (
    <div className="h-2.5 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-white/[0.08]" role="progressbar" aria-valuenow={Math.round(value)} aria-valuemin={0} aria-valuemax={100} aria-label={label}>
      <div className={`h-full origin-left animate-grow-x rounded-full bg-gradient-to-r ${BARS[tone]}`} style={{ width: `${value}%` }} />
    </div>
  );
}

/** Tone for budget and similar statuses. */
export function statusTone(status) {
  return { over: 'bad', warning: 'warn', ok: 'good' }[status] || 'info';
}

const TONES = {
  default: '',
  good: 'text-green-600 dark:text-green-400',
  bad: 'text-red-600 dark:text-red-400',
  warn: 'text-amber-600 dark:text-amber-400',
};
const ICON_TONES = {
  default: 'bg-brand-500/10 text-brand-600 dark:bg-brand-400/15 dark:text-brand-300',
  good: 'bg-emerald-500/10 text-emerald-600 dark:bg-emerald-400/15 dark:text-emerald-300',
  bad: 'bg-rose-500/10 text-rose-600 dark:bg-rose-400/15 dark:text-rose-300',
  warn: 'bg-amber-500/10 text-amber-600 dark:bg-amber-400/15 dark:text-amber-300',
};

/** A figure with its title, optional icon and explanation. `delay` (ms) staggers the entrance animation. */
export function StatCard({ title, value, sub, tone = 'default', hint, loading = false, icon, delay = 0, className = '' }) {
  return (
    <div className={`card card-hover animate-fade-up ${className}`} title={hint} style={{ animationDelay: `${delay}ms` }}>
      <div className="flex items-start justify-between gap-2">
        <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{title}</p>
        {icon && <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-xl ${ICON_TONES[tone]}`}><Icon name={icon} className="h-4 w-4" /></span>}
      </div>
      {loading ? <Skeleton className="mt-3 h-7 w-32" /> : <p className={`mt-2 break-words text-xl font-semibold tabular-nums tracking-tight ${TONES[tone]}`}>{value}</p>}
      {sub && !loading && <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">{sub}</p>}
    </div>
  );
}

export function PageHeader({ title, subtitle, actions }) {
  return (
    <div className="mb-6 flex flex-wrap items-start justify-between gap-3 animate-fade-up">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
        {subtitle && <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{subtitle}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}

export function Tabs({ tabs, value, onChange }) {
  return (
    <div className="mb-4 flex gap-1 overflow-x-auto rounded-xl bg-slate-100 p-1 dark:bg-white/[0.04]" role="tablist">
      {tabs.map((tab) => (
        <button
          key={tab.key}
          type="button"
          role="tab"
          aria-selected={value === tab.key}
          onClick={() => onChange(tab.key)}
          className={`whitespace-nowrap rounded-lg px-3.5 py-1.5 text-sm font-medium transition ${
            value === tab.key
              ? 'bg-white text-brand-700 shadow-sm dark:bg-brand-500/20 dark:text-brand-200'
              : 'text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'
          }`}
        >
          {tab.label}
        </button>
      ))}
    </div>
  );
}

/**
 * A popup in the middle of the screen, or (with `wide`) a side panel for long forms and detail views.
 * Closes with Escape or a click outside, and keeps focus inside while open.
 */
export function Modal({ title, onClose, children, wide = false, size = 'md' }) {
  const ref = useRef(null);
  const { t } = useI18n();

  useEffect(() => {
    const previous = document.activeElement;
    const focusable = ref.current?.querySelector('input, select, textarea, button:not([data-close])');
    (focusable || ref.current)?.focus();

    const onKey = (event) => {
      if (event.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', onKey);
    document.body.style.overflow = 'hidden';

    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = '';
      previous?.focus?.();
    };
  }, [onClose]);

  const panel = wide
    ? 'ms-auto flex min-h-full w-full max-w-3xl animate-slide-in-end flex-col rtl:animate-slide-in-start sm:min-h-screen sm:rounded-s-3xl'
    : `m-auto w-full ${size === 'lg' ? 'max-w-2xl' : 'max-w-lg'} animate-scale-in rounded-2xl`;

  return (
    <div className="fixed inset-0 z-50 flex animate-fade-in overflow-y-auto bg-ink-900/60 p-0 backdrop-blur-sm sm:p-6" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div
        ref={ref}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        tabIndex={-1}
        className={`${panel} border border-slate-200 bg-white p-5 shadow-2xl outline-none dark:border-white/10 dark:bg-ink-700 sm:p-6`}
      >
        <div className="mb-4 flex items-start justify-between gap-3">
          <h2 className="text-lg font-semibold tracking-tight">{title}</h2>
          <button type="button" data-close className="btn-ghost btn-sm !rounded-lg" onClick={onClose} aria-label={t('common.close')}>
            <Icon name="x" className="h-4 w-4" />
          </button>
        </div>
        {children}
      </div>
    </div>
  );
}

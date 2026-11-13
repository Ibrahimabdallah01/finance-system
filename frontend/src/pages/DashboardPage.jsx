import { useState } from 'react';
import client from '../api/client';
import { BudgetChart, CashFlowChart, CategoryPie, IncomeExpenseChart, ProfitChart } from '../components/charts';
import DateRangeFilter from '../components/DateRangeFilter';
import Icon from '../components/Icon';
import { Badge, ErrorBanner, PageHeader, Skeleton, StatCard, statusTone } from '../components/ui';
import { useAuth } from '../context/AuthContext';
import { useDataChanged } from '../hooks/useDataChanged';
import { useFetch } from '../hooks/useFetch';
import { useI18n } from '../i18n/I18nContext';
import { formatDate, presetRange } from '../lib/dates';
import { compareMoney, formatMoney } from '../lib/money';

function Panel({ title, children, actions, delay = 0, className = '' }) {
  return (
    <section className={`card animate-fade-up ${className}`} style={{ animationDelay: `${delay}ms` }}>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-sm font-semibold tracking-tight">{title}</h2>
        {actions}
      </div>
      {children}
    </section>
  );
}

const signTone = (value) => ((compareMoney(value, '0') ?? 0) < 0 ? 'bad' : (compareMoney(value, '0') ?? 0) > 0 ? 'good' : 'default');

function greetingKey(date = new Date()) {
  const hour = date.getHours();

  return hour < 12 ? 'dash.morning' : hour < 18 ? 'dash.afternoon' : 'dash.evening';
}

/** The headline: what you would have if everyone paid you and you paid everyone. */
function Hero({ net, loading, t }) {
  return (
    <section className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-brand-600 via-indigo-600 to-violet-700 p-6 text-white shadow-xl shadow-brand-700/25 animate-fade-up sm:col-span-2 xl:row-span-2" title={t('hint.position')}>
      <div className="pointer-events-none absolute -end-10 -top-10 h-48 w-48 rounded-full bg-white/10 blur-2xl" aria-hidden="true" />
      <div className="pointer-events-none absolute -bottom-16 start-10 h-40 w-40 rounded-full bg-cyan-300/10 blur-2xl" aria-hidden="true" />
      <div className="relative flex h-full flex-col justify-between gap-6">
        <div>
          <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-white/75">
            <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-white/15"><Icon name="scale" className="h-4 w-4" /></span>
            {t('card.position')}
          </div>
          {loading ? <Skeleton className="mt-4 h-10 w-56" /> : <p className="mt-4 break-words text-3xl font-semibold tabular-nums tracking-tight sm:text-4xl">{formatMoney(net.net)}</p>}
          {!loading && <p className="mt-2 text-sm text-white/75">{t('card.positionSub', { assets: formatMoney(net.assets.total), liabilities: formatMoney(net.liabilities.total) })}</p>}
        </div>
        {!loading && (
          <dl className="grid grid-cols-3 gap-3 text-sm">
            {[['own.assets', net.assets.total, 'arrowDown'], ['own.liabilities', net.liabilities.total, 'arrowUp'], ['own.cash', net.assets.cash, 'wallet']].map(([key, value, icon]) => (
              <div key={key} className="rounded-xl bg-white/10 p-3 backdrop-blur">
                <dt className="flex items-center gap-1.5 text-[11px] uppercase tracking-wide text-white/70"><Icon name={icon} className="h-3.5 w-3.5" />{t(key)}</dt>
                <dd className="mt-1 break-words font-semibold tabular-nums">{formatMoney(value)}</dd>
              </div>
            ))}
          </dl>
        )}
      </div>
    </section>
  );
}

export default function DashboardPage() {
  const { t } = useI18n();
  const { user } = useAuth();
  const [range, setRange] = useState(() => ({ preset: 'month', ...presetRange('month') }));
  const [scope, setScope] = useState('personal');

  const { data, loading, error, reload } = useFetch(
    () => client.get('/api/dashboard', { params: { from: range.from, to: range.to } }).then((response) => response.data.data),
    [range.from, range.to],
  );
  useDataChanged(reload);

  const cards = data?.cards;
  const charts = data?.charts;
  const budget = cards?.budget_usage;
  const net = cards?.net_position;
  const waiting = loading && !cards;
  const name = (user?.name || '').split(' ')[0];

  const stat = (key, props) => ({ key, ...props });
  const items = [
    stat('cash', { title: t('card.cash'), icon: 'wallet', value: cards && formatMoney(cards.cash.total), sub: cards && t('card.cashSub', { business: formatMoney(cards.cash.business), personal: formatMoney(cards.cash.personal) }), hint: t('hint.cash') }),
    stat('revenue', { title: t('card.revenue'), icon: 'sales', value: cards && formatMoney(cards.sales_revenue), sub: t('card.revenueSub'), hint: t('hint.revenue') }),
    stat('gross', { title: t('card.gross'), icon: 'trend', value: cards && formatMoney(cards.gross_profit), tone: cards && signTone(cards.gross_profit), sub: t('card.grossSub') }),
    stat('net', { title: t('card.net'), icon: 'reports', value: cards && formatMoney(cards.net_profit), tone: cards && signTone(cards.net_profit), sub: t('card.netSub') }),
    stat('pIncome', { title: t('card.pIncome'), icon: 'arrowDown', tone: 'good', value: cards && formatMoney(cards.personal_income), hint: t('hint.pIncome') }),
    stat('pExpenses', { title: t('card.pExpenses'), icon: 'arrowUp', tone: 'bad', value: cards && formatMoney(cards.personal_expenses), hint: t('hint.pExpenses') }),
    stat('bExpenses', { title: t('card.bExpenses'), icon: 'expenses', value: cards && formatMoney(cards.business_expenses), hint: t('hint.bExpenses') }),
    stat('savings', { title: t('card.savings'), icon: 'savings', value: cards && formatMoney(cards.personal_savings.accounts_total), sub: cards && t('card.savingsSub', { amount: formatMoney(cards.personal_savings.goals_saved) }), hint: t('hint.savings') }),
    stat('owed', { title: t('card.owed'), icon: 'users', value: cards && formatMoney(cards.owed_to_me), sub: t('card.owedSub') }),
    stat('owe', { title: t('card.owe'), icon: 'debts', value: cards && formatMoney(cards.i_owe), sub: t('card.oweSub') }),
    stat('budget', {
      title: t('card.budget'), icon: 'budgets',
      value: budget && (budget.count === 0 ? t('card.noBudgets') : budget.over + budget.warning === 0 ? t('card.onTrack') : t('card.overNear', { over: budget.over, warning: budget.warning })),
      tone: budget ? (budget.over > 0 ? 'bad' : budget.warning > 0 ? 'warn' : 'default') : 'default',
      sub: budget && (budget.highest ? t('card.highest', { name: budget.highest.name, percent: budget.highest.percent_used }) : t('card.addBudgets')),
    }),
  ];

  return (
    <div>
      <PageHeader
        title={name ? t(greetingKey(), { name }) : t('dash.title')}
        subtitle={data ? t('dash.period', { from: formatDate(data.period.from), to: formatDate(data.period.to) }) : t('dash.fallback')}
        actions={<DateRangeFilter value={range} onChange={setRange} />}
      />

      {error && !data && <ErrorBanner message={t('dash.loadError')} onRetry={reload} />}

      {(data || loading) && !(error && !data) && (
        <>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-busy={loading}>
            <Hero net={net} loading={waiting} t={t} />
            {items.slice(0, 4).map(({ key, ...props }, i) => (
              <StatCard key={key} loading={waiting} delay={80 + i * 60} {...props} />
            ))}
            {items.slice(4).map(({ key, ...props }, i) => (
              <StatCard key={key} loading={waiting} delay={320 + i * 55} {...props} />
            ))}
          </div>

          {cards && (
            <div className="mt-3 grid gap-3 lg:grid-cols-2">
              <Panel title={t('panel.whereCash')} delay={100}>
                {cards.cash.accounts.length === 0 ? (
                  <p className="text-sm text-slate-500">{t('panel.noAccounts')}</p>
                ) : (
                  <ul className="divide-y divide-slate-100 text-sm dark:divide-white/[0.06]">
                    {cards.cash.accounts.map((account) => (
                      <li key={account.id} className="flex items-center justify-between gap-2 py-2.5">
                        <span className="flex items-center gap-2">{account.name} <Badge tone={account.scope === 'business' ? 'info' : 'neutral'}>{t(`scope.${account.scope}`)}</Badge></span>
                        <span className={`tabular-nums ${(compareMoney(account.balance, '0') ?? 0) < 0 ? 'text-red-600 dark:text-red-400' : ''}`}>{formatMoney(account.balance)}</span>
                      </li>
                    ))}
                  </ul>
                )}
              </Panel>
              <Panel title={t('panel.ownOwe')} delay={160}>
                <dl className="space-y-2 text-sm">
                  <div className="flex justify-between"><dt>{t('own.cash')}</dt><dd className="tabular-nums">{formatMoney(net.assets.cash)}</dd></div>
                  <div className="flex justify-between"><dt>{t('own.stock')}</dt><dd className="tabular-nums">{formatMoney(net.assets.inventory_at_cost)}</dd></div>
                  <div className="flex justify-between"><dt>{t('own.owedToYou')}</dt><dd className="tabular-nums">{formatMoney(net.assets.receivables)}</dd></div>
                  <div className="flex justify-between border-t border-slate-200 pt-2 font-medium dark:border-white/10"><dt>{t('own.totalAssets')}</dt><dd className="tabular-nums">{formatMoney(net.assets.total)}</dd></div>
                  <div className="flex justify-between"><dt>{t('own.youOwe')}</dt><dd className="tabular-nums">−{formatMoney(net.liabilities.total, { symbol: false })}</dd></div>
                  <div className="flex justify-between border-t border-slate-200 pt-2 text-base font-semibold dark:border-white/10"><dt>{t('card.position')}</dt><dd className="tabular-nums">{formatMoney(net.net)}</dd></div>
                </dl>
              </Panel>
            </div>
          )}

          <div className="mt-3 grid gap-3 lg:grid-cols-2">
            <Panel
              title={t('chart.incomeExpenses')}
              delay={120}
              actions={
                <div className="inline-flex gap-1 rounded-xl bg-slate-100 p-1 dark:bg-white/[0.05]" role="group" aria-label={t('chart.choose')}>
                  {['personal', 'business'].map((key) => (
                    <button key={key} type="button" aria-pressed={scope === key} onClick={() => setScope(key)} className={`btn btn-sm !rounded-lg ${scope === key ? 'bg-white text-brand-700 shadow-sm dark:bg-brand-500/25 dark:text-white' : 'text-slate-600 dark:text-slate-400'}`}>
                      {key === 'personal' ? t('chart.personal') : t('chart.business')}
                    </button>
                  ))}
                </div>
              }
            >
              {charts && <IncomeExpenseChart monthly={charts.monthly} scope={scope} />}
            </Panel>
            <Panel title={t('chart.profit')} delay={180}>{charts && <ProfitChart monthly={charts.monthly} />}</Panel>
            <Panel title={t('chart.personalCats')} delay={140}>{charts && <CategoryPie items={charts.personal_expense_categories} label={t('chart.aria.personalCats')} />}</Panel>
            <Panel title={t('chart.businessCats')} delay={200}>{charts && <CategoryPie items={charts.business_expense_categories} label={t('chart.aria.businessCats')} />}</Panel>
            <Panel title={t('chart.cashFlow')} delay={160} className="lg:col-span-2">{charts && <CashFlowChart cashFlow={charts.cash_flow} />}</Panel>
            <Panel title={t('chart.budgets')} delay={200} className="lg:col-span-2">
              {charts && (
                <>
                  <BudgetChart items={charts.budget_vs_actual} />
                  {charts.budget_vs_actual.length > 0 && (
                    <ul className="mt-3 space-y-1.5 text-sm">
                      {charts.budget_vs_actual.map((item) => (
                        <li key={item.id} className="flex items-center justify-between gap-2">
                          <span>{item.name} <span className="text-xs text-slate-500">({item.period})</span></span>
                          <span className="flex items-center gap-2"><span className="tabular-nums">{formatMoney(item.spent)} / {formatMoney(item.budget)}</span><Badge tone={statusTone(item.status)}>{item.percent_used}%</Badge></span>
                        </li>
                      ))}
                    </ul>
                  )}
                </>
              )}
            </Panel>
          </div>

          {data && (
            <details className="card mt-3 text-sm animate-fade-up">
              <summary className="cursor-pointer font-medium">{t('dash.how')}</summary>
              <ul className="mt-2 list-disc space-y-1 ps-5 text-slate-600 dark:text-slate-300">
                {data.notes.map((note) => <li key={note}>{note}</li>)}
              </ul>
            </details>
          )}
        </>
      )}
    </div>
  );
}

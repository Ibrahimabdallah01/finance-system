import { Area, Bar, BarChart, CartesianGrid, Cell, ComposedChart, Legend, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { useI18n } from '../i18n/I18nContext';
import { formatMonth, shortLabel } from '../lib/dates';
import { formatMoney, toChartNumber } from '../lib/money';

const COLORS = ['#3380fa', '#8b5cf6', '#10b981', '#f59e0b', '#ef4444', '#14b8a6', '#f97316', '#64748b', '#ec4899', '#84cc16'];
const AXIS = '#94a3b8';
const n = toChartNumber;

const compact = (value) => {
  const abs = Math.abs(value);
  if (abs >= 1e9) return `${(value / 1e9).toFixed(1)}B`;
  if (abs >= 1e6) return `${(value / 1e6).toFixed(1)}M`;
  if (abs >= 1e3) return `${(value / 1e3).toFixed(0)}K`;
  return String(value);
};

const tooltipMoney = (value) => formatMoney(String(value));
const tooltipStyle = {
  contentStyle: { borderRadius: 12, border: '1px solid rgba(148,163,184,.25)', background: 'rgba(12,19,35,.96)', color: '#e2e8f0', boxShadow: '0 12px 30px rgba(0,0,0,.35)' },
  labelStyle: { color: '#cbd5e1', fontWeight: 600 },
  itemStyle: { color: '#e2e8f0' },
  cursor: { fill: 'rgba(148,163,184,.08)' },
};

/** Gradient fills shared by the charts. */
function Gradients() {
  const stops = { gBlue: ['#60a5fa', '#3380fa'], gGreen: ['#34d399', '#059669'], gRed: ['#fb7185', '#e11d48'], gViolet: ['#a78bfa', '#7c3aed'], gAmber: ['#fbbf24', '#d97706'], gSlate: ['#94a3b8', '#64748b'] };

  return (
    <defs>
      {Object.entries(stops).map(([id, [top, bottom]]) => (
        <linearGradient key={id} id={id} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor={top} stopOpacity={0.95} />
          <stop offset="100%" stopColor={bottom} stopOpacity={0.75} />
        </linearGradient>
      ))}
      <linearGradient id="gArea" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stopColor="#3380fa" stopOpacity={0.45} />
        <stop offset="100%" stopColor="#3380fa" stopOpacity={0} />
      </linearGradient>
    </defs>
  );
}

function ChartBox({ label, children, height = 260 }) {
  return (
    <div role="img" aria-label={label} style={{ width: '100%', height }}>
      <ResponsiveContainer width="100%" height="100%">{children}</ResponsiveContainer>
    </div>
  );
}

export function NoData({ children }) {
  const { t } = useI18n();

  return <p className="py-10 text-center text-sm text-slate-500 dark:text-slate-400">{children ?? t('chart.noData')}</p>;
}

const grid = <CartesianGrid strokeDasharray="3 3" stroke={AXIS} strokeOpacity={0.18} vertical={false} />;

/** Personal: income vs expenses. Business: net sales vs cost of goods sold plus expenses. */
export function IncomeExpenseChart({ monthly = [], scope }) {
  const { t } = useI18n();
  const personal = scope === 'personal';
  const data = monthly.map((m) => ({
    month: formatMonth(m.month),
    first: personal ? n(m.personal_income) : n(m.business_sales),
    second: personal ? n(m.personal_expenses) : n(m.business_cogs) + n(m.business_expenses),
  }));

  if (!data.some((d) => d.first || d.second)) return <NoData />;

  return (
    <ChartBox label={personal ? t('chart.aria.personal') : t('chart.aria.business')}>
      <BarChart data={data} margin={{ top: 8, right: 8, left: 0, bottom: 0 }} barGap={4}>
        <Gradients />
        {grid}
        <XAxis dataKey="month" stroke={AXIS} fontSize={12} tickLine={false} axisLine={false} />
        <YAxis stroke={AXIS} fontSize={12} tickFormatter={compact} width={48} tickLine={false} axisLine={false} />
        <Tooltip formatter={tooltipMoney} {...tooltipStyle} />
        <Legend />
        <Bar dataKey="first" name={personal ? t('chart.income') : t('chart.netSales')} fill="url(#gGreen)" radius={[6, 6, 0, 0]} animationDuration={900} />
        <Bar dataKey="second" name={personal ? t('chart.expenses') : t('chart.costs')} fill="url(#gRed)" radius={[6, 6, 0, 0]} animationDuration={900} />
      </BarChart>
    </ChartBox>
  );
}

export function ProfitChart({ monthly = [] }) {
  const { t } = useI18n();
  const data = monthly.map((m) => ({ month: formatMonth(m.month), gross: n(m.business_gross_profit), net: n(m.business_net_profit) }));

  if (!data.some((d) => d.gross || d.net)) return <NoData />;

  return (
    <ChartBox label={t('chart.aria.profit')}>
      <BarChart data={data} margin={{ top: 8, right: 8, left: 0, bottom: 0 }} barGap={4}>
        <Gradients />
        {grid}
        <XAxis dataKey="month" stroke={AXIS} fontSize={12} tickLine={false} axisLine={false} />
        <YAxis stroke={AXIS} fontSize={12} tickFormatter={compact} width={48} tickLine={false} axisLine={false} />
        <Tooltip formatter={tooltipMoney} {...tooltipStyle} />
        <Legend />
        <Bar dataKey="gross" name={t('chart.grossProfit')} fill="url(#gBlue)" radius={[6, 6, 0, 0]} animationDuration={900} />
        <Bar dataKey="net" name={t('chart.netProfit')} fill="url(#gViolet)" radius={[6, 6, 0, 0]} animationDuration={900} />
      </BarChart>
    </ChartBox>
  );
}

/** A donut plus a list, so the figures are readable without hovering. */
export function CategoryPie({ items = [], label }) {
  if (items.length === 0) return <NoData />;

  const data = items.map((item) => ({ name: item.name, value: n(item.total) }));

  return (
    <div>
      <ChartBox label={label} height={220}>
        <PieChart>
          <Pie data={data} dataKey="value" nameKey="name" innerRadius={52} outerRadius={88} paddingAngle={3} stroke="none" animationDuration={900}>
            {data.map((_, index) => <Cell key={index} fill={COLORS[index % COLORS.length]} />)}
          </Pie>
          <Tooltip formatter={tooltipMoney} {...tooltipStyle} />
        </PieChart>
      </ChartBox>
      <ul className="mt-2 space-y-1.5 text-sm">
        {items.map((item, index) => (
          <li key={item.category_id ?? item.name} className="flex items-center justify-between gap-2">
            <span className="flex items-center gap-2">
              <span className="inline-block h-2.5 w-2.5 rounded-full" style={{ background: COLORS[index % COLORS.length] }} aria-hidden="true" />
              {item.name}
            </span>
            <span className="tabular-nums">{formatMoney(item.total)} <span className="text-xs text-slate-500">({item.percent}%)</span></span>
          </li>
        ))}
      </ul>
    </div>
  );
}

export function CashFlowChart({ cashFlow }) {
  const { t } = useI18n();
  const points = cashFlow?.points || [];
  const data = points.map((p) => ({ label: shortLabel(p.label), in: n(p.money_in), out: n(p.money_out), balance: n(p.closing_balance) }));

  if (!data.some((d) => d.in || d.out)) return <NoData>{t('chart.noMoney')}</NoData>;

  return (
    <div>
      <ChartBox label={t('chart.aria.cashFlow')} height={300}>
        <ComposedChart data={data} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
          <Gradients />
          {grid}
          <XAxis dataKey="label" stroke={AXIS} fontSize={11} interval="preserveStartEnd" tickLine={false} axisLine={false} />
          <YAxis stroke={AXIS} fontSize={12} tickFormatter={compact} width={48} tickLine={false} axisLine={false} />
          <Tooltip formatter={tooltipMoney} {...tooltipStyle} />
          <Legend />
          <Area type="monotone" dataKey="balance" name={t('chart.balance')} stroke="#3380fa" strokeWidth={2.5} fill="url(#gArea)" animationDuration={1100} />
          <Bar dataKey="in" name={t('chart.moneyIn')} fill="url(#gGreen)" radius={[4, 4, 0, 0]} animationDuration={900} />
          <Bar dataKey="out" name={t('chart.moneyOut')} fill="url(#gRed)" radius={[4, 4, 0, 0]} animationDuration={900} />
        </ComposedChart>
      </ChartBox>
      <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">{cashFlow.granularity === 'day' ? t('chart.byDay') : t('chart.byMonth')}</p>
    </div>
  );
}

export function BudgetChart({ items = [] }) {
  const { t } = useI18n();

  if (items.length === 0) return <NoData>{t('chart.noBudgets')}</NoData>;

  const data = items.map((b) => ({ name: b.name, budget: n(b.budget), spent: n(b.spent) }));

  return (
    <ChartBox label={t('chart.aria.budget')}>
      <BarChart data={data} margin={{ top: 8, right: 8, left: 0, bottom: 0 }} barGap={4}>
        <Gradients />
        {grid}
        <XAxis dataKey="name" stroke={AXIS} fontSize={12} tickLine={false} axisLine={false} />
        <YAxis stroke={AXIS} fontSize={12} tickFormatter={compact} width={48} tickLine={false} axisLine={false} />
        <Tooltip formatter={tooltipMoney} {...tooltipStyle} />
        <Legend />
        <Bar dataKey="budget" name={t('chart.budget')} fill="url(#gSlate)" radius={[6, 6, 0, 0]} animationDuration={900} />
        <Bar dataKey="spent" name={t('chart.spent')} fill="url(#gAmber)" radius={[6, 6, 0, 0]} animationDuration={900} />
      </BarChart>
    </ChartBox>
  );
}

/** Budget versus actual for past periods (oldest first). */
export function BudgetHistoryChart({ periods = [] }) {
  const { t } = useI18n();
  const data = periods.map((p) => ({ label: shortLabel(p.period_start), budget: n(p.budget), spent: n(p.spent) }));

  if (!data.some((d) => d.spent)) return <NoData />;

  return (
    <ChartBox label={t('chart.aria.budget')}>
      <BarChart data={data} margin={{ top: 8, right: 8, left: 0, bottom: 0 }} barGap={4}>
        <Gradients />
        {grid}
        <XAxis dataKey="label" stroke={AXIS} fontSize={12} tickLine={false} axisLine={false} />
        <YAxis stroke={AXIS} fontSize={12} tickFormatter={compact} width={48} tickLine={false} axisLine={false} />
        <Tooltip formatter={tooltipMoney} {...tooltipStyle} />
        <Legend />
        <Bar dataKey="budget" name={t('chart.budget')} fill="url(#gSlate)" radius={[6, 6, 0, 0]} animationDuration={900} />
        <Bar dataKey="spent" name={t('chart.spent')} fill="url(#gAmber)" radius={[6, 6, 0, 0]} animationDuration={900} />
      </BarChart>
    </ChartBox>
  );
}

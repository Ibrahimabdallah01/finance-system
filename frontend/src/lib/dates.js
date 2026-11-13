// Dates are plain "YYYY-MM-DD" strings in the user's local calendar (never converted through UTC).

const pad = (n) => String(n).padStart(2, '0');
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

export function toISODate(date) {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

export function today(now = new Date()) {
  return toISODate(now);
}

export const PRESETS = [
  { key: 'today', label: 'Today' },
  { key: 'week', label: 'This week' },
  { key: 'month', label: 'This month' },
  { key: 'year', label: 'This year' },
  { key: 'custom', label: 'Custom' },
];

/** Range for a preset. Week starts on Monday. The end is always today. */
export function presetRange(key, now = new Date()) {
  const end = toISODate(now);
  const start = new Date(now.getFullYear(), now.getMonth(), now.getDate());

  switch (key) {
    case 'today':
      return { from: end, to: end };
    case 'week': {
      const offset = (start.getDay() + 6) % 7; // Monday = 0
      start.setDate(start.getDate() - offset);
      return { from: toISODate(start), to: end };
    }
    case 'year':
      return { from: `${now.getFullYear()}-01-01`, to: end };
    case 'month':
    default:
      return { from: `${now.getFullYear()}-${pad(now.getMonth() + 1)}-01`, to: end };
  }
}

let dateLocale = null; // null = English with the fixed short month names below

/** Set by the language switcher. Use null for English. */
export function setDateLocale(locale) {
  dateLocale = locale;
}

function localised(y, m, d, options) {
  try {
    return new Intl.DateTimeFormat(dateLocale, { timeZone: 'UTC', ...options }).format(new Date(Date.UTC(y, m - 1, d)));
  } catch {
    return null;
  }
}

/** "2026-10-13" -> "13 Oct 2026" (or the same date in the chosen language). */
export function formatDate(value) {
  if (!value) return '';

  const [y, m, d] = String(value).slice(0, 10).split('-').map(Number);
  if (!y || !m || !d) return String(value);

  return (dateLocale && localised(y, m, d, { day: 'numeric', month: 'short', year: 'numeric' })) || `${d} ${MONTHS[m - 1]} ${y}`;
}

/** "2026-10" -> "Oct 2026". */
export function formatMonth(value) {
  if (!value) return '';

  const [y, m] = String(value).split('-').map(Number);

  return (dateLocale && localised(y, m, 1, { month: 'short', year: 'numeric' })) || `${MONTHS[m - 1]} ${y}`;
}

/** Chart axis label for a day ("13 Oct") or a month ("Oct 2026"). */
export function shortLabel(label) {
  if (!label) return '';

  if (String(label).length === 7) return formatMonth(label);

  const [y, m, d] = String(label).split('-').map(Number);

  return (dateLocale && localised(y, m, d, { day: 'numeric', month: 'short' })) || formatDate(label).slice(0, -5);
}

/** Adds whole months to a "YYYY-MM-DD" date, keeping the day where possible (31 Jan + 1 month = 28/29 Feb). */
export function addMonths(iso, months) {
  const [y, m, d] = iso.split('-').map(Number);
  const target = new Date(y, m - 1 + months, 1);
  const lastDay = new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate();
  target.setDate(Math.min(d, lastDay));

  return toISODate(target);
}

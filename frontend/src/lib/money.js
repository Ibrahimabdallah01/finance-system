// Money is handled as decimal STRINGS (like the API) so nothing is ever rounded by floating point.
// Numbers are produced only for drawing charts.

const PATTERN = /^-?\d+(\.\d+)?$/;

/** "150000.5" -> "TSh 150,000.50". Whole amounts show no decimals: "TSh 150,000". */
export function formatMoney(value, { symbol = true } = {}) {
  const prefix = symbol ? 'TSh ' : '';

  if (value === null || value === undefined || value === '') return `${prefix}0`;

  const text = String(value).trim();
  if (!PATTERN.test(text)) return String(value);

  const negative = text.startsWith('-');
  const [whole, fraction = ''] = (negative ? text.slice(1) : text).split('.');
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  const cents = fraction.slice(0, 2).padEnd(2, '0');
  const body = /^0+$/.test(cents) ? grouped : `${grouped}.${cents}`;
  const isZero = /^0+$/.test(whole) && /^0+$/.test(cents);

  return `${negative && !isZero ? '-' : ''}${prefix}${body}`;
}

/** Exact amount in cents as a BigInt, or null when the text is not an amount. */
export function toCents(value) {
  const text = String(value ?? '').trim();
  if (!PATTERN.test(text)) return null;

  const negative = text.startsWith('-');
  const [whole, fraction = ''] = (negative ? text.slice(1) : text).split('.');
  const cents = BigInt(whole) * 100n + BigInt(fraction.slice(0, 2).padEnd(2, '0'));

  return negative ? -cents : cents;
}

/** Exact comparison of two amounts: -1, 0 or 1 (null when either is not an amount). */
export function compareMoney(a, b) {
  const x = toCents(a);
  const y = toCents(b);

  if (x === null || y === null) return null;

  return x < y ? -1 : x > y ? 1 : 0;
}

/** True for an amount with at most 2 decimals that the API will accept (> 0 unless allowZero). */
export function isValidAmount(value, { allowZero = false, allowNegative = false } = {}) {
  const text = String(value ?? '').trim();

  if (!/^-?\d+(\.\d{1,2})?$/.test(text)) return false;

  const cents = toCents(text);

  if (cents < 0n && !allowNegative) return false;
  if (cents === 0n && !allowZero) return false;

  return true;
}

/** For charts only. Never use the result for calculations that are shown as money. */
export function toChartNumber(value) {
  const n = Number(value);

  return Number.isFinite(n) ? n : 0;
}

/** Cents back to a 2-decimal string: 150050n -> "1500.50". */
export function fromCents(cents) {
  const negative = cents < 0n;
  const abs = negative ? -cents : cents;
  const whole = abs / 100n;
  const fraction = String(abs % 100n).padStart(2, '0');

  return `${negative ? '-' : ''}${whole}.${fraction}`;
}

/** Exact sum of amount strings. Anything that is not an amount counts as zero (for form previews). */
export function sumMoney(values) {
  return fromCents(values.reduce((total, value) => total + (toCents(value) ?? 0n), 0n));
}

export function subMoney(a, b) {
  return fromCents((toCents(a) ?? 0n) - (toCents(b) ?? 0n));
}

/** Amount times a whole-number quantity. */
export function mulMoney(amount, quantity) {
  const q = Number.parseInt(quantity, 10);

  return fromCents((toCents(amount) ?? 0n) * BigInt(Number.isFinite(q) && q > 0 ? q : 0));
}

/** Splits an amount into n parts that add up exactly; any leftover cents go to the last part. */
export function splitMoney(total, n) {
  const count = Number.parseInt(n, 10);
  const cents = toCents(total);

  if (cents === null || !Number.isFinite(count) || count < 1) return [];

  const each = cents / BigInt(count);
  const parts = Array.from({ length: count }, () => each);
  parts[count - 1] += cents - each * BigInt(count);

  return parts.map(fromCents);
}

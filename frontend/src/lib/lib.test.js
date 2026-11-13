import { describe, expect, it } from 'vitest';
import { compareMoney, formatMoney, isValidAmount, toCents, toChartNumber } from './money';
import { formatDate, formatMonth, presetRange, shortLabel, toISODate } from './dates';
import { getErrorMessage, getFieldErrors } from './errors';
import { cleanParams, normalizePage } from '../api/normalize';

describe('money', () => {
  it('formats amounts like the API and the brief: TSh 150,000', () => {
    expect(formatMoney('150000')).toBe('TSh 150,000');
    expect(formatMoney('150000.00')).toBe('TSh 150,000');
    expect(formatMoney('1234567.5')).toBe('TSh 1,234,567.50');
    expect(formatMoney('-5000.00')).toBe('-TSh 5,000');
    expect(formatMoney('0.00')).toBe('TSh 0');
    expect(formatMoney('-0.00')).toBe('TSh 0');
    expect(formatMoney(null)).toBe('TSh 0');
    expect(formatMoney('999', { symbol: false })).toBe('999');
  });

  it('leaves text that is not an amount alone', () => {
    expect(formatMoney('abc')).toBe('abc');
  });

  it('compares exactly, without floating point', () => {
    expect(compareMoney('0.10', '0.1')).toBe(0);
    expect(compareMoney('10000000000000.01', '10000000000000.00')).toBe(1);
    expect(compareMoney('1.00', '1.01')).toBe(-1);
    expect(compareMoney('x', '1')).toBeNull();
    expect(toCents('12.3')).toBe(1230n);
    expect(toCents('-0.05')).toBe(-5n);
  });

  it('validates amounts the way the API does', () => {
    expect(isValidAmount('150000')).toBe(true);
    expect(isValidAmount('1.5')).toBe(true);
    expect(isValidAmount('1.555')).toBe(false);
    expect(isValidAmount('0')).toBe(false);
    expect(isValidAmount('0', { allowZero: true })).toBe(true);
    expect(isValidAmount('-5')).toBe(false);
    expect(isValidAmount('-5', { allowNegative: true })).toBe(true);
    expect(isValidAmount('abc')).toBe(false);
    expect(isValidAmount('')).toBe(false);
  });

  it('converts to numbers only for charts', () => {
    expect(toChartNumber('1500.50')).toBe(1500.5);
    expect(toChartNumber('oops')).toBe(0);
  });
});

describe('dates', () => {
  const wednesday = new Date(2026, 9, 14); // Wed 14 Oct 2026

  it('builds the filter presets, ending today', () => {
    expect(presetRange('today', wednesday)).toEqual({ from: '2026-10-14', to: '2026-10-14' });
    expect(presetRange('week', wednesday)).toEqual({ from: '2026-10-12', to: '2026-10-14' }); // Monday
    expect(presetRange('month', wednesday)).toEqual({ from: '2026-10-01', to: '2026-10-14' });
    expect(presetRange('year', wednesday)).toEqual({ from: '2026-01-01', to: '2026-10-14' });
  });

  it('starts the week on Monday, also when today is Sunday', () => {
    expect(presetRange('week', new Date(2026, 9, 18))).toEqual({ from: '2026-10-12', to: '2026-10-18' });
    expect(presetRange('week', new Date(2026, 9, 12))).toEqual({ from: '2026-10-12', to: '2026-10-12' });
  });

  it('uses the local calendar day, not UTC', () => {
    expect(toISODate(new Date(2026, 0, 1, 23, 59))).toBe('2026-01-01');
  });

  it('formats dates for people', () => {
    expect(formatDate('2026-10-13')).toBe('13 Oct 2026');
    expect(formatDate('')).toBe('');
    expect(formatMonth('2026-03')).toBe('Mar 2026');
    expect(shortLabel('2026-10-13')).toBe('13 Oct');
    expect(shortLabel('2026-10')).toBe('Oct 2026');
  });
});

describe('errors', () => {
  const failure = (status, data) => ({ response: { status, data } });

  it('turns failed requests into plain messages', () => {
    expect(getErrorMessage(failure(422, { errors: { amount: ['The amount must be greater than zero.'] } }))).toBe(
      'The amount must be greater than zero.',
    );
    expect(getErrorMessage(failure(401, {}))).toMatch(/sign in again/);
    expect(getErrorMessage(failure(404, {}))).toMatch(/no longer exists/);
    expect(getErrorMessage(failure(500, {}))).toMatch(/Nothing was saved/);
    expect(getErrorMessage({ request: {} })).toMatch(/Cannot reach the server/);
  });

  it('maps validation errors to fields', () => {
    const error = failure(422, { errors: { 'units.0.imei': ['Duplicate.'], amount: ['Too big.', 'Other.'] } });
    expect(getFieldErrors(error)).toEqual({ 'units.0.imei': 'Duplicate.', amount: 'Too big.' });
    expect(getFieldErrors(failure(500, {}))).toEqual({});
  });
});

describe('api helpers', () => {
  it('reads both kinds of page', () => {
    expect(normalizePage({ data: [1], meta: { current_page: 2, last_page: 4, total: 80, per_page: 25 } })).toMatchObject({
      rows: [1], page: 2, lastPage: 4, total: 80, perPage: 25,
    });
    expect(normalizePage({ data: [1, 2], current_page: 1, last_page: 1, total: 2, per_page: 50 })).toMatchObject({
      page: 1, lastPage: 1, total: 2,
    });
    expect(normalizePage({ data: [] }).rows).toEqual([]);
  });

  it('drops empty filters', () => {
    expect(cleanParams({ a: '', b: 'x', c: null, d: 0, e: undefined })).toEqual({ b: 'x', d: 0 });
  });
});

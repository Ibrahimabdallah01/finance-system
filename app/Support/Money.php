<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact money arithmetic on decimal STRINGS using BCMath. Never use floats for money.
 * Amounts are TZS with at most 2 decimals, matching the DECIMAL(15,2) columns.
 * Floats are rejected by the type declarations (strict_types).
 */
final class Money
{
    public const SCALE = 2;

    public static function add(string|int $a, string|int $b): string
    {
        return bcadd(self::normalize($a), self::normalize($b), self::SCALE);
    }

    public static function sub(string|int $a, string|int $b): string
    {
        return bcsub(self::normalize($a), self::normalize($b), self::SCALE);
    }

    /** @param iterable<string|int> $amounts */
    public static function sum(iterable $amounts): string
    {
        $total = '0.00';

        foreach ($amounts as $amount) {
            $total = self::add($total, $amount);
        }

        return $total;
    }

    /** Multiply by a quantity or rate. The result is rounded half away from zero to 2 decimals. */
    public static function mul(string|int $amount, string|int $factor): string
    {
        $factor = (string) $factor;

        if (! preg_match('/^-?\d+(\.\d{1,8})?$/', $factor)) {
            throw new InvalidArgumentException("Invalid factor: {$factor}");
        }

        $product = bcmul(self::normalize($amount), $factor, 10);

        return bcadd($product, str_starts_with($product, '-') ? '-0.005' : '0.005', self::SCALE);
    }

    /** Returns -1, 0 or 1. */
    public static function compare(string|int $a, string|int $b): int
    {
        return bccomp(self::normalize($a), self::normalize($b), self::SCALE);
    }

    /** Validates an amount (max 2 decimals) and returns it with exactly 2 decimals. */
    public static function normalize(string|int $value): string
    {
        $value = (string) $value;

        if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $value)) {
            throw new InvalidArgumentException("Invalid money amount: {$value}");
        }

        return bcadd($value, '0', self::SCALE);
    }

    /**
     * Converts a decoded JSON value (string, int or float) into an exact 2-decimal string.
     * Boundary use only (request input). Amounts with more than 2 decimals are rejected.
     */
    public static function fromInput(string|int|float $value): string
    {
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Invalid money amount.');
            }

            $value = rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
        }

        return self::normalize((string) $value);
    }

    /** Converts a value read from the database (decimal string, or null for "nothing") to 2 decimals. */
    public static function fromDatabase(string|int|float|null $value): string
    {
        if ($value === null) {
            return '0.00';
        }

        if (is_float($value)) {
            return number_format($value, self::SCALE, '.', '');
        }

        $value = (string) $value;

        if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("Invalid database amount: {$value}");
        }

        return bcadd($value, '0', self::SCALE);
    }

    /** Display format, e.g. "TSh 150,000" or "TSh 1,234.50". */
    public static function format(string|int $amount, bool $withSymbol = true): string
    {
        $value = self::normalize($amount);
        $negative = str_starts_with($value, '-');

        if ($negative) {
            $value = substr($value, 1);
        }

        [$integer, $fraction] = explode('.', $value);
        $integer = strrev(implode(',', str_split(strrev($integer), 3)));
        $text = $fraction === '00' ? $integer : $integer.'.'.$fraction;

        return ($negative ? '-' : '').($withSymbol ? 'TSh ' : '').$text;
    }
}

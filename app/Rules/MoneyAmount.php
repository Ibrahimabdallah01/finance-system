<?php

namespace App\Rules;

use App\Support\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/** Validates a TZS amount: at most 2 decimals, fits DECIMAL(15,2), optional sign/zero rules. */
class MoneyAmount implements ValidationRule
{
    public function __construct(
        private bool $allowZero = false,
        private bool $allowNegative = false,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            $fail('The :attribute must be a valid amount.');

            return;
        }

        try {
            $amount = Money::fromInput($value);
        } catch (InvalidArgumentException) {
            $fail('The :attribute must be a valid amount with at most 2 decimals.');

            return;
        }

        if (strlen(ltrim($amount, '-')) > 16) {
            $fail('The :attribute is too large.');

            return;
        }

        $comparison = Money::compare($amount, '0');

        if ($comparison < 0 && ! $this->allowNegative) {
            $fail('The :attribute must not be negative.');
        } elseif ($comparison === 0 && ! $this->allowZero) {
            $fail('The :attribute must be greater than zero.');
        }
    }
}

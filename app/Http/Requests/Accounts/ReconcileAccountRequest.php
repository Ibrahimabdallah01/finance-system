<?php

namespace App\Http\Requests\Accounts;

use App\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;

class ReconcileAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'actual_balance' => ['required', new MoneyAmount(allowZero: true, allowNegative: true)],
            'reconciled_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'post_adjustment' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

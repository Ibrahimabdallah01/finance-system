<?php

namespace App\Http\Requests\Debts;

use App\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DebtRepaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_id' => ['required', 'integer'],
            'amount' => ['required', new MoneyAmount], // principal only
            'transaction_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'interest_amount' => ['nullable', new MoneyAmount(allowZero: true)], // recorded separately
            'interest_category_id' => ['nullable', 'integer'],
            'payment_method' => ['nullable', Rule::in(['cash', 'mobile_money', 'bank', 'other'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

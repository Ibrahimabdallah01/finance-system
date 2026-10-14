<?php

namespace App\Http\Requests\Transactions;

use App\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIncomeExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_id' => ['required', 'integer'],
            'category_id' => ['required', 'integer'],
            'amount' => ['required', new MoneyAmount],
            'transaction_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:255'],
            'recipient' => ['nullable', 'string', 'max:255'], // payee for expenses, payer for income
            'reference' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', Rule::in(['cash', 'mobile_money', 'bank', 'other'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

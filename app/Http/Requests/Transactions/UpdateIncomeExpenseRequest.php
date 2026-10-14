<?php

namespace App\Http\Requests\Transactions;

use App\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIncomeExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_id' => ['sometimes', 'required', 'integer'],
            'category_id' => ['sometimes', 'required', 'integer'],
            'amount' => ['sometimes', 'required', new MoneyAmount],
            'transaction_date' => ['sometimes', 'required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'description' => ['sometimes', 'required', 'string', 'max:255'],
            'recipient' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'payment_method' => ['sometimes', 'nullable', Rule::in(['cash', 'mobile_money', 'bank', 'other'])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}

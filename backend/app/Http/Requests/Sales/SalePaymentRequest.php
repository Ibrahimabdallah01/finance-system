<?php

namespace App\Http\Requests\Sales;

use App\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Customer payments and refunds share the same shape. */
class SalePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_id' => ['required', 'integer'],
            'amount' => ['required', new MoneyAmount],
            'transaction_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'payment_method' => ['nullable', Rule::in(['cash', 'mobile_money', 'bank', 'other'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

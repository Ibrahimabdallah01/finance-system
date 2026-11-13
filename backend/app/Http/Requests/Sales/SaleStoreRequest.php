<?php

namespace App\Http\Requests\Sales;

use App\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaleStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $methods = Rule::in(['cash', 'mobile_money', 'bank', 'other']);

        return [
            'customer_id' => ['nullable', 'integer'],
            'sale_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:sale_date'],
            'discount_amount' => ['nullable', new MoneyAmount(allowZero: true)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'cost_account_id' => ['nullable', 'integer'], // pays for directly sold items

            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.inventory_unit_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'items.*.unit_price' => ['required', new MoneyAmount(allowZero: true)],
            'items.*.unit_cost' => ['nullable', new MoneyAmount(allowZero: true)], // direct-sale items only

            'payments' => ['nullable', 'array', 'max:10'],
            'payments.*.account_id' => ['required', 'integer'],
            'payments.*.amount' => ['required', new MoneyAmount],
            'payments.*.payment_method' => ['nullable', $methods],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}

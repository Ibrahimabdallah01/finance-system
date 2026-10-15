<?php

namespace App\Http\Requests\Inventory;

use App\Enums\ItemCondition;
use App\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'purchase_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'account_id' => ['nullable', 'integer'],
            'on_credit' => ['sometimes', 'boolean'],
            'opening_stock' => ['sometimes', 'boolean'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:purchase_date'],
            'reference' => ['nullable', 'string', 'max:100'],

            // Phones tracked by IMEI / serial number
            'units' => ['sometimes', 'array', 'max:200'],
            'units.*.imei' => ['nullable', 'string', 'regex:/^\d{14,16}$/'],
            'units.*.serial_number' => ['nullable', 'string', 'max:64'],
            'units.*.storage' => ['nullable', 'string', 'max:30'],
            'units.*.color' => ['nullable', 'string', 'max:50'],
            'units.*.item_condition' => ['nullable', Rule::enum(ItemCondition::class)],
            'units.*.purchase_price' => ['required_with:units', new MoneyAmount(allowZero: true)],
            'units.*.additional_costs' => ['nullable', new MoneyAmount(allowZero: true)],
            'units.*.notes' => ['nullable', 'string', 'max:2000'],

            // Counted items (accessories)
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'unit_cost' => ['sometimes', new MoneyAmount(allowZero: true)],
            'additional_costs' => ['nullable', new MoneyAmount(allowZero: true)],
        ];
    }

    public function messages(): array
    {
        return ['units.*.imei.regex' => 'An IMEI is 14 to 16 digits.'];
    }
}

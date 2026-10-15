<?php

namespace App\Http\Requests\Sales;

use App\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;

class SaleReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sale_item_id' => ['required', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'return_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'return_amount' => ['nullable', new MoneyAmount(allowZero: true)], // default: proportional share of the sale total
            'restock' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

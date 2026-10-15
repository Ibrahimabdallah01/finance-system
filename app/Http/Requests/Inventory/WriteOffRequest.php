<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class WriteOffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'inventory_unit_id' => ['nullable', 'integer', 'required_without:product_id'],
            'product_id' => ['nullable', 'integer', 'required_without:inventory_unit_id'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'write_off_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}

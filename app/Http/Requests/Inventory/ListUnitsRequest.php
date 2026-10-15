<?php

namespace App\Http\Requests\Inventory;

use App\Enums\UnitStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListUnitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(UnitStatus::class)],
            'product_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'], // IMEI, serial number, storage or colour
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }
}

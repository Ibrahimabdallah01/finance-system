<?php

namespace App\Http\Requests\Inventory;

use App\Enums\ItemCondition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Descriptive details only. Costs are never edited here: they follow the stock purchase. */
class UnitUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $unit = $this->route('unit');
        $uid = $this->user()->id;

        return [
            'imei' => [
                'sometimes', 'nullable', 'string', 'regex:/^\d{14,16}$/',
                Rule::unique('inventory_units', 'imei')->where('user_id', $uid)->ignore($unit->id),
            ],
            'serial_number' => [
                'sometimes', 'nullable', 'string', 'max:64',
                Rule::unique('inventory_units', 'serial_number')->where('user_id', $uid)->ignore($unit->id),
            ],
            'storage' => ['sometimes', 'nullable', 'string', 'max:30'],
            'color' => ['sometimes', 'nullable', 'string', 'max:50'],
            'item_condition' => ['sometimes', Rule::enum(ItemCondition::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}

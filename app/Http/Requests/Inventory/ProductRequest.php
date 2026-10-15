<?php

namespace App\Http\Requests\Inventory;

use App\Enums\Tracking;
use App\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Creating and updating products. How a product is tracked cannot change after it is created. */
class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';
        $product = $this->route('product');

        $rules = [
            'product_type' => [$required, Rule::in(['phone', 'accessory', 'other'])],
            'name' => [$required, 'string', 'max:255'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:255'],
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sku' => [
                'sometimes', 'nullable', 'string', 'max:60',
                Rule::unique('products', 'sku')->where('user_id', $this->user()->id)->ignore($product?->id),
            ],
            'default_selling_price' => ['sometimes', 'nullable', new MoneyAmount(allowZero: true)],
            'reorder_level' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];

        if ($creating) {
            $rules['tracking'] = ['required', Rule::enum(Tracking::class)];
        }

        return $rules;
    }
}

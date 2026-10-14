<?php

namespace App\Http\Requests\Categories;

use App\Enums\CategoryKind;
use App\Enums\Scope;
use App\Models\TransactionCategory;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::enum(Scope::class)],
            'kind' => ['required', Rule::enum(CategoryKind::class)],
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('transaction_categories', 'name')->where(
                    fn ($query) => $query
                        ->where('user_id', $this->user()->id)
                        ->where('scope', $this->input('scope'))
                        ->where('kind', $this->input('kind'))
                        ->where('parent_id', $this->input('parent_id'))
                ),
            ],
            'parent_id' => ['nullable', 'integer'],
            'sort_order' => ['sometimes', 'integer', 'between:0,65535'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty() || ! $this->filled('parent_id')) {
                    return;
                }

                $parent = TransactionCategory::query()->find($this->integer('parent_id'));

                if ($parent === null) {
                    $validator->errors()->add('parent_id', 'Parent category not found.');
                } elseif ($parent->parent_id !== null) {
                    $validator->errors()->add('parent_id', 'Subcategories can only go one level deep.');
                } elseif ($parent->scope->value !== $this->input('scope') || $parent->kind->value !== $this->input('kind')) {
                    $validator->errors()->add('parent_id', 'The parent must have the same scope and kind.');
                }
            },
        ];
    }
}

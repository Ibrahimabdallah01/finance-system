<?php

namespace App\Http\Requests\Budgets;

use App\Enums\CategoryKind;
use App\Enums\Scope;
use App\Models\Budget;
use App\Models\TransactionCategory;
use App\Rules\MoneyAmount;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::enum(Scope::class)],
            'category_id' => ['nullable', 'integer'], // empty = all expenses of that scope
            'name' => ['required', 'string', 'max:100'],
            'period' => ['required', Rule::in(['daily', 'weekly', 'monthly', 'yearly'])],
            'amount' => ['required', new MoneyAmount],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->filled('category_id')) {
                    $category = TransactionCategory::query()->find($this->integer('category_id'));

                    if ($category === null) {
                        $validator->errors()->add('category_id', 'Category not found.');

                        return;
                    }

                    if ($category->kind !== CategoryKind::Expense) {
                        $validator->errors()->add('category_id', 'Budgets apply to expense categories.');

                        return;
                    }

                    if ($category->scope->value !== $this->input('scope')) {
                        $validator->errors()->add('category_id', 'The category must be a '.$this->input('scope').' category.');

                        return;
                    }
                }

                $duplicate = Budget::query()
                    ->where('is_active', true)
                    ->where('scope', $this->input('scope'))
                    ->where('period', $this->input('period'))
                    ->where('category_id', $this->filled('category_id') ? $this->integer('category_id') : null)
                    ->exists();

                if ($duplicate && $this->boolean('is_active', true)) {
                    $validator->errors()->add('period', 'An active budget already exists for this scope, category and period.');
                }
            },
        ];
    }
}

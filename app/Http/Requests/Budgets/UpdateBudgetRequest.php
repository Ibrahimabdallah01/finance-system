<?php

namespace App\Http\Requests\Budgets;

use App\Models\Budget;
use App\Rules\MoneyAmount;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/** Scope, category, period and start date are fixed; create a new budget to change them. */
class UpdateBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $budget = $this->route('budget');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'amount' => ['sometimes', new MoneyAmount],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.$budget->start_date->toDateString()],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $budget = $this->route('budget');

                if ($validator->errors()->isNotEmpty() || ! $this->boolean('is_active') || $budget->is_active) {
                    return;
                }

                $duplicate = Budget::query()
                    ->where('is_active', true)
                    ->where('id', '!=', $budget->id)
                    ->where('scope', $budget->scope->value)
                    ->where('period', $budget->period)
                    ->where('category_id', $budget->category_id)
                    ->exists();

                if ($duplicate) {
                    $validator->errors()->add('is_active', 'Another active budget already exists for this scope, category and period.');
                }
            },
        ];
    }
}

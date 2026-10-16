<?php

namespace App\Http\Requests\Budgets;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A reference date picks the period to look at (any past or current period). */
class BudgetDateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
            'periods' => ['nullable', 'integer', 'between:1,24'],
            'scope' => ['nullable', Rule::in(['personal', 'business'])],
            'active' => ['nullable', 'boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests\Savings;

use App\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavingsGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'goal_type' => [$required, Rule::in([
                'business_capital', 'stock_purchase', 'emergency', 'equipment', 'rent', 'education', 'other',
            ])],
            'target_amount' => [$required, new MoneyAmount],
            'target_date' => array_filter(['sometimes', 'nullable', 'date_format:Y-m-d', $creating ? 'after:today' : null]),
            'account_id' => ['sometimes', 'nullable', 'integer'], // where the saved money is kept
            'status' => ['sometimes', Rule::in(['active', 'cancelled'])], // "achieved" is set automatically
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}

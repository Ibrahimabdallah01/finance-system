<?php

namespace App\Http\Requests\Debts;

use App\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;

class DebtScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'installments' => ['required', 'array', 'min:1', 'max:60'],
            'installments.*.due_date' => ['required', 'date_format:Y-m-d'],
            'installments.*.amount_due' => ['required', new MoneyAmount],
            'installments.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }
}

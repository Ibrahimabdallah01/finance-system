<?php

namespace App\Http\Requests\Debts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListDebtsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'direction' => ['nullable', Rule::in(['receivable', 'payable'])],
            'scope' => ['nullable', Rule::in(['personal', 'business'])],
            'status' => ['nullable', Rule::in(['open', 'settled', 'written_off', 'cancelled'])],
            'search' => ['nullable', 'string', 'max:100'],
            'days' => ['nullable', 'integer', 'between:1,365'], // look-ahead for the summary
        ];
    }
}

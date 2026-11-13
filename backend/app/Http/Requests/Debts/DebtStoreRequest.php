<?php

namespace App\Http\Requests\Debts;

use App\Enums\Scope;
use App\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DebtStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::enum(Scope::class)],
            'direction' => ['required', Rule::in(['receivable', 'payable'])], // receivable = owed to me
            'source' => ['required', Rule::in(['loan', 'supplier_credit', 'other'])],
            'party_name' => ['required', 'string', 'max:255'],
            'supplier_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'original_amount' => ['required', new MoneyAmount],
            'issued_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issued_on'],
            'account_id' => ['nullable', 'integer'], // loans only: where the money moved
            'payment_method' => ['nullable', Rule::in(['cash', 'mobile_money', 'bank', 'other'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

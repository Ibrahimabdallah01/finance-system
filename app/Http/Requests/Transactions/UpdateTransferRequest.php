<?php

namespace App\Http\Requests\Transactions;

use App\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_account_id' => ['sometimes', 'required', 'integer'],
            'to_account_id' => ['sometimes', 'required', 'integer'],
            'amount' => ['sometimes', 'required', new MoneyAmount],
            'transaction_date' => ['sometimes', 'required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}

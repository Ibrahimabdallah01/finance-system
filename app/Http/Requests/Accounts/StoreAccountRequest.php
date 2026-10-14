<?php

namespace App\Http\Requests\Accounts;

use App\Enums\AccountType;
use App\Enums\Scope;
use App\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'scope' => ['required', Rule::enum(Scope::class)],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('accounts', 'name')->where(
                    fn ($query) => $query->where('user_id', $userId)->where('scope', $this->input('scope'))
                ),
            ],
            'type' => ['required', Rule::enum(AccountType::class)],
            'provider' => ['nullable', 'string', 'max:255'],
            'opening_balance' => ['sometimes', new MoneyAmount(allowZero: true, allowNegative: true)],
            'opening_balance_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

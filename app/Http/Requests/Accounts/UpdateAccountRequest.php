<?php

namespace App\Http\Requests\Accounts;

use App\Enums\AccountType;
use App\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The scope (business/personal) of an account can never be changed after creation. */
class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $account = $this->route('account');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('accounts', 'name')
                    ->where(fn ($query) => $query->where('user_id', $this->user()->id)->where('scope', $account->scope->value))
                    ->ignore($account->id),
            ],
            'type' => ['sometimes', 'required', Rule::enum(AccountType::class)],
            'provider' => ['sometimes', 'nullable', 'string', 'max:255'],
            'opening_balance' => ['sometimes', new MoneyAmount(allowZero: true, allowNegative: true)],
            'opening_balance_date' => ['sometimes', 'required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}

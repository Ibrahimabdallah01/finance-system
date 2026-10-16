<?php

namespace App\Http\Requests\Savings;

use App\Rules\MoneyAmount;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Contributions: optional from_account_id (money moves into the goal's savings account).
 * Withdrawals: optional to_account_id (money moves out of it). Without an account the amount is only earmarked.
 */
class GoalMoneyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', new MoneyAmount],
            'contribution_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'from_account_id' => ['nullable', 'integer'],
            'to_account_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}

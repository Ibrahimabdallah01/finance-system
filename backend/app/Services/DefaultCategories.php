<?php

namespace App\Services;

use App\Models\TransactionCategory;
use App\Models\User;

/**
 * Starting categories. Everything is editable afterwards. Buying phone stock is NOT an
 * expense category: stock is an asset until sold (inventory purchases come in Phase 6).
 */
final class DefaultCategories
{
    public const SET = [
        ['personal', 'expense', [
            'Food and drinks', 'Rent and accommodation', 'Transport and fuel', 'Electricity and water',
            'Internet and phone bundles', 'Clothing', 'Health expenses', 'Family support',
            'Education and courses', 'Entertainment', 'Travel', 'Charity and religious giving',
            'Personal shopping', 'Emergencies', 'Loan interest', 'Other expenses',
        ]],
        ['personal', 'income', [
            'Phone brokerage commissions', 'Freelance software development', 'Salary or employment',
            'Other services', 'Other personal income',
        ]],
        ['business', 'expense', [
            'Delivery and transport', 'Advertising and marketing', 'Internet and communication',
            'Repairs and maintenance', 'Packaging', 'Business rent and utilities',
            'Software and subscriptions', 'Bank and mobile money fees', 'Employee payments',
            'Loan interest', 'Other business expenses',
        ]],
        ['business', 'income', [
            'Other business income',
        ]],
    ];

    /** Adds any missing default categories for the user. Safe to run again. Returns how many were added. */
    public function seedFor(User $user): int
    {
        $created = 0;

        foreach (self::SET as [$scope, $kind, $names]) {
            foreach ($names as $order => $name) {
                $exists = TransactionCategory::query()->withoutGlobalScopes()
                    ->where('user_id', $user->id)
                    ->where('scope', $scope)
                    ->where('kind', $kind)
                    ->whereNull('parent_id')
                    ->where('name', $name)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $category = new TransactionCategory([
                    'scope' => $scope,
                    'kind' => $kind,
                    'name' => $name,
                    'is_system' => true,
                    'is_active' => true,
                    'sort_order' => $order,
                ]);
                $category->user_id = $user->id;
                $category->save();
                $created++;
            }
        }

        return $created;
    }
}

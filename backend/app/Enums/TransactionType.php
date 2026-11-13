<?php

namespace App\Enums;

enum TransactionType: string
{
    case Income = 'income';
    case Expense = 'expense';
    case Transfer = 'transfer';
    case OwnerWithdrawal = 'owner_withdrawal';
    case OwnerContribution = 'owner_contribution';
    case InventoryPurchase = 'inventory_purchase';
    case CustomerPayment = 'customer_payment';
    case CustomerRefund = 'customer_refund';
    case LoanReceived = 'loan_received';
    case LoanGiven = 'loan_given';
    case DebtRepaymentIn = 'debt_repayment_in';
    case DebtRepaymentOut = 'debt_repayment_out';
    case Adjustment = 'adjustment';

    /** Types that move money between two of the owner's own accounts (two ledger entries). */
    public function isPair(): bool
    {
        return in_array($this, [self::Transfer, self::OwnerWithdrawal, self::OwnerContribution], true);
    }

    /** Direction for single-account types: 1 = money in, -1 = money out, 0 = chosen per entry or paired. */
    public function sign(): int
    {
        return match ($this) {
            self::Income, self::CustomerPayment, self::LoanReceived, self::DebtRepaymentIn => 1,
            self::Expense, self::InventoryPurchase, self::CustomerRefund, self::LoanGiven, self::DebtRepaymentOut => -1,
            default => 0,
        };
    }

    /** Business-only events: stock, customer money. */
    public function requiresBusinessAccount(): bool
    {
        return in_array($this, [self::InventoryPurchase, self::CustomerPayment, self::CustomerRefund], true);
    }
}

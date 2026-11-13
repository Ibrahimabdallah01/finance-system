<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Sale;
use App\Support\Money;

/**
 * All per-sale figures, derived from records (nothing is stored):
 *   net revenue  = agreed total - revenue reversed by returns
 *   COGS         = cost of items sold - cost of items put back in stock
 *   gross profit = net revenue - COGS
 *   net profit   = gross profit - direct costs recorded against the sale (delivery, repairs, ...)
 * Payments only change what the customer owes, never the revenue.
 */
final class SaleCalculator
{
    /** @return array<string, string> */
    public static function figures(Sale $sale): array
    {
        $sale->loadMissing(['items', 'returns', 'transactions']);

        $posted = $sale->transactions->filter(fn ($t) => $t->status === 'posted');
        $sum = fn (TransactionType $type) => Money::sum(
            $posted->filter(fn ($t) => $t->type === $type)->pluck('amount')
        );

        $payments = $sum(TransactionType::CustomerPayment);
        $refunds = $sum(TransactionType::CustomerRefund);
        $directCosts = $sum(TransactionType::Expense);

        $items = $sale->items->keyBy('id');
        $subtotal = Money::sum($sale->items->map(fn ($i) => Money::mul($i->unit_price, $i->quantity)));
        $returned = Money::sum($sale->returns->pluck('return_amount'));
        $cancelled = $sale->status === 'cancelled';

        $netRevenue = $cancelled ? '0.00' : Money::sub($sale->total_amount, $returned);

        $cogsSold = Money::sum($sale->items->map(fn ($i) => Money::mul($i->unit_cost_snapshot, $i->quantity)));
        $cogsBack = Money::sum($sale->returns->filter(fn ($r) => $r->restock)->map(
            fn ($r) => Money::mul($items[$r->sale_item_id]->unit_cost_snapshot, $r->quantity)
        ));
        $cogs = $cancelled ? '0.00' : Money::sub($cogsSold, $cogsBack);

        $paid = Money::sub($payments, $refunds);
        $difference = Money::sub($netRevenue, $paid); // > 0 customer owes, < 0 refund due
        $outstanding = Money::compare($difference, '0') > 0 ? $difference : '0.00';
        $refundDue = Money::compare($difference, '0') < 0 ? Money::sub('0', $difference) : '0.00';

        $grossProfit = Money::sub($netRevenue, $cogs);

        return [
            'subtotal' => $subtotal,
            'discount' => (string) $sale->discount_amount,
            'total' => (string) $sale->total_amount,
            'returned_revenue' => $returned,
            'net_revenue' => $netRevenue,
            'payments_received' => $payments,
            'refunds_paid' => $refunds,
            'amount_paid' => $paid,
            'outstanding_balance' => $outstanding,
            'refund_due' => $refundDue,
            'payment_status' => self::paymentStatus($cancelled, $netRevenue, $paid, $outstanding, $refundDue),
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            'direct_costs' => $directCosts,
            'net_profit' => Money::sub($grossProfit, $directCosts),
        ];
    }

    private static function paymentStatus(bool $cancelled, string $netRevenue, string $paid, string $outstanding, string $refundDue): string
    {
        return match (true) {
            $cancelled => 'cancelled',
            Money::compare($refundDue, '0') > 0 => 'refund_due',
            Money::compare($netRevenue, '0') === 0 => 'returned',
            Money::compare($outstanding, '0') === 0 => 'paid',
            Money::compare($paid, '0') === 0 => 'unpaid',
            default => 'partial',
        };
    }
}

<?php

namespace App\Services;

use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Business profit and loss from saved records only.
 *   Net sales      = completed sales - revenue reversed by returns
 *   COGS           = cost of items sold - cost of items put back in stock
 *   Gross profit   = Net sales - COGS
 *   Net profit     = Gross profit - write-offs - business operating expenses + other business income
 * Cash flow is shown separately: buying stock reduces cash but is NOT an expense.
 */
final class ProfitReportService
{
    public function profitAndLoss(string $from, string $to): array
    {
        $uid = Auth::id();
        $sum = fn ($query, string $expression) => Money::fromDatabase(
            $query->selectRaw("COALESCE(SUM({$expression}), 0) as v")->value('v')
        );

        $grossSales = $sum(DB::table('sales')->where('user_id', $uid)->where('status', 'completed')
            ->whereBetween('sale_date', [$from, $to]), 'total_amount');

        $returns = $sum(DB::table('sale_returns as r')->join('sales as s', 's.id', '=', 'r.sale_id')
            ->where('r.user_id', $uid)->where('s.status', 'completed')
            ->whereBetween('r.return_date', [$from, $to]), 'r.return_amount');

        $cogsSold = $sum(DB::table('sale_items as i')->join('sales as s', 's.id', '=', 'i.sale_id')
            ->where('s.user_id', $uid)->where('s.status', 'completed')
            ->whereBetween('s.sale_date', [$from, $to]), 'i.quantity * i.unit_cost_snapshot');

        $cogsRestocked = $sum(DB::table('sale_returns as r')
            ->join('sale_items as i', 'i.id', '=', 'r.sale_item_id')
            ->join('sales as s', 's.id', '=', 'r.sale_id')
            ->where('r.user_id', $uid)->where('s.status', 'completed')->where('r.restock', true)
            ->whereBetween('r.return_date', [$from, $to]), 'r.quantity * i.unit_cost_snapshot');

        $writeOffs = $sum(DB::table('inventory_movements')->where('user_id', $uid)
            ->where('movement_type', 'write_off')
            ->whereBetween('movement_date', [$from, $to]), '-quantity * unit_cost');

        $business = fn (string $type) => DB::table('transactions')->where('user_id', $uid)
            ->where('status', 'posted')->where('scope', 'business')->where('type', $type)
            ->whereBetween('transaction_date', [$from, $to]);

        $expenses = $sum($business('expense'), 'amount');
        $otherIncome = $sum($business('income'), 'amount');

        $netSales = Money::sub($grossSales, $returns);
        $cogs = Money::sub($cogsSold, $cogsRestocked);
        $grossProfit = Money::sub($netSales, $cogs);
        $netProfit = Money::add(Money::sub(Money::sub($grossProfit, $writeOffs), $expenses), $otherIncome);

        $cash = DB::table('transactions')->where('user_id', $uid)->where('status', 'posted')
            ->where('scope', 'business')->whereBetween('transaction_date', [$from, $to])
            ->groupBy('type')->selectRaw('type, COALESCE(SUM(amount), 0) as v')->pluck('v', 'type');

        return [
            'period' => ['from' => $from, 'to' => $to],
            'sales' => [
                'gross_sales' => $grossSales,
                'returns' => $returns,
                'net_sales' => $netSales,
            ],
            'cost_of_goods_sold' => [
                'sold' => $cogsSold,
                'restocked_returns' => $cogsRestocked,
                'total' => $cogs,
            ],
            'gross_profit' => $grossProfit,
            'inventory_write_offs' => $writeOffs,
            'operating_expenses' => $expenses,
            'other_business_income' => $otherIncome,
            'net_profit' => $netProfit,
            'cash_view' => [
                'customer_payments_received' => Money::fromDatabase($cash['customer_payment'] ?? 0),
                'customer_refunds_paid' => Money::fromDatabase($cash['customer_refund'] ?? 0),
                'inventory_purchases_paid' => Money::fromDatabase($cash['inventory_purchase'] ?? 0),
                'operating_expenses_paid' => Money::fromDatabase($cash['expense'] ?? 0),
                'owner_withdrawals' => Money::fromDatabase($cash['owner_withdrawal'] ?? 0),
            ],
            'notes' => [
                'Gross profit = net sales - cost of goods sold. Net profit = gross profit - write-offs - operating expenses + other business income.',
                'Revenue is counted on the sale date, whether or not the customer has paid. Customer payments only appear in the cash view.',
                'Buying stock lowers cash but is not an expense: its cost enters profit as cost of goods sold when the item is sold, or as a write-off if it is lost.',
                'Cancelled sales are excluded. Direct selling costs (delivery, repairs) are business expenses counted once, in operating expenses.',
                'Owner withdrawals and transfers between accounts are not expenses.',
            ],
        ];
    }
}

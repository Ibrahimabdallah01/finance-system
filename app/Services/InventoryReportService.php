<?php

namespace App\Services;

use App\Models\Product;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class InventoryReportService
{
    /**
     * Stock on hand per product, valued at cost.
     * Serialized phones: sum of each unit's price + additional costs.
     * Counted items: quantity x weighted-average cost.
     *
     * @return array<int, array{quantity: int, value: string}> keyed by product id
     */
    public function stockFor(iterable $products): array
    {
        $products = collect($products);
        $serializedIds = $products->filter(fn (Product $p) => $p->tracking->value === 'serialized')->pluck('id')->all();

        $serialized = $serializedIds === [] ? collect() : DB::table('inventory_units')
            ->where('user_id', Auth::id())
            ->whereIn('status', ['in_stock', 'reserved'])
            ->whereIn('product_id', $serializedIds)
            ->groupBy('product_id')
            ->selectRaw('product_id, COUNT(*) as qty, COALESCE(SUM(purchase_price + additional_costs), 0) as value')
            ->get()
            ->keyBy('product_id');

        $result = [];

        foreach ($products as $product) {
            if ($product->tracking->value === 'serialized') {
                $row = $serialized->get($product->id);
                $result[$product->id] = [
                    'quantity' => $row ? (int) $row->qty : 0,
                    'value' => Money::fromDatabase($row?->value),
                ];
            } elseif ($product->tracking->value === 'quantity') {
                $result[$product->id] = [
                    'quantity' => (int) $product->quantity_on_hand,
                    'value' => Money::mul($product->average_cost, (int) $product->quantity_on_hand),
                ];
            } else {
                $result[$product->id] = ['quantity' => 0, 'value' => '0.00'];
            }
        }

        return $result;
    }

    public function summary(?string $from, ?string $to): array
    {
        $products = Product::query()->where('is_active', true)->whereIn('tracking', ['serialized', 'quantity'])
            ->orderBy('name')->get();
        $stock = $this->stockFor($products);

        $items = $products->map(function (Product $product) use ($stock) {
            $row = $stock[$product->id];
            $low = $product->tracking->value === 'quantity'
                && $product->reorder_level !== null
                && $row['quantity'] <= $product->reorder_level;

            return [
                'product_id' => $product->id,
                'name' => $product->name,
                'tracking' => $product->tracking->value,
                'quantity' => $row['quantity'],
                'stock_value_at_cost' => $row['value'],
                'reorder_level' => $product->reorder_level,
                'low_stock' => $low,
            ];
        })->values();

        $sold = DB::table('sale_items as i')
            ->join('sales as s', 's.id', '=', 'i.sale_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('s.user_id', Auth::id())
            ->where('s.status', 'completed')
            ->when($from, fn ($q) => $q->where('s.sale_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('s.sale_date', '<=', $to))
            ->groupBy('p.id', 'p.name')
            ->orderBy('p.name')
            ->selectRaw('p.id as product_id, p.name, SUM(i.quantity - i.returned_quantity) as quantity_sold, COALESCE(SUM((i.quantity - i.returned_quantity) * i.unit_price), 0) as sales_before_discount')
            ->get()
            ->map(fn ($row) => [
                'product_id' => (int) $row->product_id,
                'name' => $row->name,
                'quantity_sold' => (int) $row->quantity_sold,
                'sales_before_discount' => Money::fromDatabase($row->sales_before_discount),
            ])->values();

        return [
            'stock' => $items,
            'low_stock' => $items->where('low_stock', true)->values(),
            'totals' => [
                'stock_value_at_cost' => Money::sum($items->pluck('stock_value_at_cost')),
                'phones_in_stock' => $items->where('tracking', 'serialized')->sum('quantity'),
                'counted_items_in_stock' => $items->where('tracking', 'quantity')->sum('quantity'),
            ],
            'products_sold' => $sold,
            'notes' => [
                'Stock value is the cost you paid (price plus additional costs), not the selling price.',
                'products_sold is for completed sales, net of returned quantities, before discounts.',
            ],
        ];
    }
}

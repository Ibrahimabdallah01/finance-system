<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Enums\Tracking;
use App\Enums\UnitStatus;
use App\Models\Debt;
use App\Models\InventoryMovement;
use App\Models\InventoryUnit;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\Supplier;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Owns stock. Buying stock moves cash out but creates an ASSET, not an expense: cost only
 * reaches profit (as cost of goods sold) when the item is sold, or as a write-off if lost.
 */
final class InventoryService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Records stock coming in. Cash purchase: one inventory_purchase transaction for price + additional costs.
     * Credit purchase: a payable debt for the price; only additional costs are paid now.
     * Opening stock: no cash and no debt (stock you already owned at your start date).
     *
     * @return array{transaction: ?\App\Models\Transaction, debt: ?Debt, units: list<InventoryUnit>, quantity_added: int, total_cost: string}
     */
    public function purchase(array $input): array
    {
        $this->requireUser();

        return DB::transaction(function () use ($input) {
            $product = Product::query()->lockForUpdate()->find($input['product_id'] ?? 0)
                ?? throw self::invalid('product_id', 'Product not found.');

            if (! $product->is_active) {
                throw self::invalid('product_id', 'This product is inactive.');
            }

            if ($product->tracking === Tracking::Direct) {
                throw self::invalid('product_id', 'Direct-sale products are not stocked. Record them directly on a sale.');
            }

            $date = self::date($input['purchase_date'] ?? null, 'purchase_date');
            $opening = (bool) ($input['opening_stock'] ?? false);
            $onCredit = (bool) ($input['on_credit'] ?? false) && ! $opening;

            $supplier = null;

            if (! empty($input['supplier_id'])) {
                $supplier = Supplier::query()->find($input['supplier_id'])
                    ?? throw self::invalid('supplier_id', 'Supplier not found.');
            }

            if ($onCredit && $supplier === null) {
                throw self::invalid('supplier_id', 'Choose the supplier you owe for a credit purchase.');
            }

            $serialized = $product->tracking === Tracking::Serialized;
            $unitRows = [];
            $quantity = 0;

            if ($serialized) {
                $unitRows = $this->validatedUnitRows($input['units'] ?? []);
                $priceTotal = Money::sum(array_column($unitRows, 'price'));
                $additionalTotal = Money::sum(array_column($unitRows, 'extra'));
            } else {
                $quantity = (int) ($input['quantity'] ?? 0);

                if ($quantity < 1) {
                    throw self::invalid('quantity', 'Enter how many items were bought.');
                }

                if (! isset($input['unit_cost'])) {
                    throw self::invalid('unit_cost', 'Enter the cost of one item.');
                }

                $priceTotal = Money::mul(Money::fromInput($input['unit_cost']), $quantity);
                $additionalTotal = Money::fromInput($input['additional_costs'] ?? '0');
            }

            $totalCost = Money::add($priceTotal, $additionalTotal);
            $transaction = null;
            $debt = null;

            if (! $opening) {
                $paidNow = $onCredit ? $additionalTotal : $totalCost;

                if (Money::compare($paidNow, '0') > 0) {
                    if (empty($input['account_id'])) {
                        throw self::invalid('account_id', $onCredit
                            ? 'Choose the account that paid the additional costs.'
                            : 'Choose the account that paid for the stock.');
                    }

                    $transaction = $this->ledger->post([
                        'type' => TransactionType::InventoryPurchase,
                        'account_id' => $input['account_id'],
                        'amount' => $paidNow,
                        'transaction_date' => $date->toDateString(),
                        'description' => 'Stock purchase: '.$product->name,
                        'recipient' => $supplier?->name,
                        'reference' => $input['reference'] ?? null,
                    ]);
                }

                if ($onCredit && Money::compare($priceTotal, '0') > 0) {
                    $debt = Debt::create([
                        'scope' => 'business',
                        'direction' => 'payable',
                        'source' => 'supplier_credit',
                        'party_name' => $supplier->name,
                        'supplier_id' => $supplier->id,
                        'original_amount' => $priceTotal,
                        'issued_on' => $date->toDateString(),
                        'due_date' => $input['due_date'] ?? null,
                        'status' => 'open',
                        'notes' => 'Stock purchase: '.$product->name,
                    ]);
                }
            }

            $units = [];

            if ($serialized) {
                foreach ($unitRows as $row) {
                    $unit = new InventoryUnit([
                        'supplier_id' => $supplier?->id,
                        'imei' => $row['imei'],
                        'serial_number' => $row['serial_number'],
                        'storage' => $row['storage'],
                        'color' => $row['color'],
                        'item_condition' => $row['item_condition'],
                        'purchase_date' => $date->toDateString(),
                        'purchase_price' => $row['price'],
                        'additional_costs' => $row['extra'],
                        'notes' => $row['notes'],
                    ]);
                    $unit->forceFill([
                        'user_id' => Auth::id(),
                        'product_id' => $product->id,
                        'acquisition_source' => $opening ? 'opening_stock' : 'purchase',
                        'purchase_transaction_id' => $transaction?->id,
                        'debt_id' => $debt?->id,
                        'status' => UnitStatus::InStock->value,
                    ])->save();

                    $this->move($product, $unit, $opening ? 'adjustment_in' : 'purchase', 1, $unit->cost(), $date, [
                        'transaction_id' => $transaction?->id,
                    ]);

                    $units[] = $unit;
                }

                $quantity = count($units);
            } else {
                $onHand = (int) $product->quantity_on_hand;
                $newAverage = Money::div(
                    Money::add(Money::mul($product->average_cost, $onHand), $totalCost),
                    $onHand + $quantity
                );

                $product->forceFill([
                    'quantity_on_hand' => $onHand + $quantity,
                    'average_cost' => $newAverage,
                ])->save();

                $this->move($product, null, $opening ? 'adjustment_in' : 'purchase', $quantity,
                    Money::div($totalCost, $quantity), $date, ['transaction_id' => $transaction?->id]);
            }

            $this->audit->record($product, 'stock_purchased', null, [
                'quantity' => $quantity,
                'total_cost' => $totalCost,
                'opening_stock' => $opening,
                'on_credit' => $onCredit,
                'transaction_id' => $transaction?->id,
                'debt_id' => $debt?->id,
            ]);

            return [
                'transaction' => $transaction,
                'debt' => $debt,
                'units' => $units,
                'quantity_added' => $quantity,
                'total_cost' => $totalCost,
            ];
        });
    }

    /**
     * Removes lost, damaged or stolen stock. The cost leaves inventory and is counted as a loss
     * in profit (a write-off), so it can never silently disappear.
     */
    public function writeOff(array $input): array
    {
        $this->requireUser();

        return DB::transaction(function () use ($input) {
            $date = self::date($input['write_off_date'] ?? null, 'write_off_date');
            $reason = trim((string) ($input['reason'] ?? ''));

            if (! empty($input['inventory_unit_id'])) {
                $unit = InventoryUnit::query()->lockForUpdate()->find($input['inventory_unit_id'])
                    ?? throw self::invalid('inventory_unit_id', 'Unit not found.');

                if (! in_array($unit->status, [UnitStatus::InStock, UnitStatus::Reserved], true)) {
                    throw self::invalid('inventory_unit_id', 'Only units in stock can be written off (this one is '.$unit->status->value.').');
                }

                $product = Product::query()->lockForUpdate()->withTrashed()->findOrFail($unit->product_id);
                $unit->forceFill(['status' => UnitStatus::WrittenOff->value])->save();
                $this->move($product, $unit, 'write_off', -1, $unit->cost(), $date, [], $reason);
                $this->audit->record($unit, 'written_off', null, ['reason' => $reason, 'cost' => $unit->cost()]);

                return ['quantity' => 1, 'cost' => $unit->cost()];
            }

            $product = Product::query()->lockForUpdate()->find($input['product_id'] ?? 0)
                ?? throw self::invalid('product_id', 'Choose a unit or a product.');

            if ($product->tracking !== Tracking::Quantity) {
                throw self::invalid('product_id', 'Only counted (quantity) products are written off by quantity. Choose the phone (unit) instead.');
            }

            $quantity = (int) ($input['quantity'] ?? 0);

            if ($quantity < 1 || $quantity > $product->quantity_on_hand) {
                throw self::invalid('quantity', 'Quantity must be between 1 and the stock on hand ('.$product->quantity_on_hand.').');
            }

            $product->forceFill(['quantity_on_hand' => $product->quantity_on_hand - $quantity])->save();
            $this->move($product, null, 'write_off', -$quantity, $product->average_cost, $date, [], $reason);
            $this->audit->record($product, 'written_off', null, ['quantity' => $quantity, 'reason' => $reason]);

            return ['quantity' => $quantity, 'cost' => Money::mul($product->average_cost, $quantity)];
        });
    }

    /** Takes the item out of stock for a sale. The cost snapshot on the item is already set. */
    public function sell(Product $product, ?InventoryUnit $unit, SaleItem $item, Sale $sale, Carbon $date): void
    {
        $links = ['sale_id' => $sale->id, 'sale_item_id' => $item->id];

        if ($product->tracking === Tracking::Serialized) {
            $unit->forceFill(['status' => UnitStatus::Sold->value])->save();
            $this->move($product, $unit, 'sale', -1, $item->unit_cost_snapshot, $date, $links);
        } elseif ($product->tracking === Tracking::Quantity) {
            $product->forceFill(['quantity_on_hand' => $product->quantity_on_hand - $item->quantity])->save();
            $this->move($product, null, 'sale', -$item->quantity, $item->unit_cost_snapshot, $date, $links);
        }
    }

    /** Puts returned or cancelled goods back into stock at the cost they were sold at. */
    public function restock(Product $product, ?InventoryUnit $unit, SaleItem $item, int $quantity, Carbon $date, Sale $sale, ?SaleReturn $return = null): void
    {
        $links = ['sale_id' => $sale->id, 'sale_item_id' => $item->id, 'sale_return_id' => $return?->id];

        if ($product->tracking === Tracking::Serialized) {
            $unit->forceFill(['status' => UnitStatus::InStock->value])->save();
            $this->move($product, $unit, 'sale_return', 1, $item->unit_cost_snapshot, $date, $links);
        } elseif ($product->tracking === Tracking::Quantity) {
            $onHand = (int) $product->quantity_on_hand;

            $product->forceFill([
                'quantity_on_hand' => $onHand + $quantity,
                'average_cost' => Money::div(
                    Money::add(Money::mul($product->average_cost, $onHand), Money::mul($item->unit_cost_snapshot, $quantity)),
                    $onHand + $quantity
                ),
            ])->save();

            $this->move($product, null, 'sale_return', $quantity, $item->unit_cost_snapshot, $date, $links);
        }
    }

    /**
     * A returned phone that cannot be resold. Its cost stays in cost of goods sold, so the unit
     * is only retired here (no write-off movement, which would count the loss twice).
     */
    public function retire(?InventoryUnit $unit): void
    {
        $unit?->forceFill(['status' => UnitStatus::WrittenOff->value])->save();
    }

    /** @return list<array<string, mixed>> */
    private function validatedUnitRows(array $units): array
    {
        if ($units === []) {
            throw self::invalid('units', 'Add at least one phone.');
        }

        $rows = [];
        $seen = ['imei' => [], 'serial_number' => []];

        foreach (array_values($units) as $i => $unit) {
            $row = [];

            foreach (['imei', 'serial_number'] as $key) {
                $value = isset($unit[$key]) && trim((string) $unit[$key]) !== '' ? trim((string) $unit[$key]) : null;

                if ($value !== null) {
                    if (in_array($value, $seen[$key], true)) {
                        throw self::invalid("units.{$i}.{$key}", 'This '.str_replace('_', ' ', $key).' appears twice in the purchase.');
                    }

                    $existing = InventoryUnit::query()->where($key, $value)->first();

                    if ($existing !== null) {
                        throw self::invalid("units.{$i}.{$key}", sprintf(
                            'This %s is already recorded (status: %s). The same phone cannot be added twice.',
                            str_replace('_', ' ', $key),
                            $existing->status->value
                        ));
                    }

                    $seen[$key][] = $value;
                }

                $row[$key] = $value;
            }

            $row['storage'] = $unit['storage'] ?? null;
            $row['color'] = $unit['color'] ?? null;
            $row['item_condition'] = $unit['item_condition'] ?? 'used';
            $row['notes'] = $unit['notes'] ?? null;
            $row['price'] = Money::fromInput($unit['purchase_price'] ?? throw self::invalid("units.{$i}.purchase_price", 'Enter the purchase price.'));
            $row['extra'] = Money::fromInput($unit['additional_costs'] ?? '0');
            $rows[] = $row;
        }

        return $rows;
    }

    private function move(Product $product, ?InventoryUnit $unit, string $type, int $quantity, string $unitCost, Carbon $date, array $links = [], ?string $notes = null): void
    {
        $movement = new InventoryMovement(array_merge([
            'product_id' => $product->id,
            'inventory_unit_id' => $unit?->id,
            'movement_type' => $type,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'movement_date' => $date->toDateString(),
            'notes' => $notes,
        ], $links));
        $movement->user_id = Auth::id();
        $movement->save();
    }

    private function requireUser(): void
    {
        if (Auth::id() === null) {
            throw new LogicException('InventoryService must run for a logged-in user.');
        }
    }

    public static function date(mixed $value, string $field): Carbon
    {
        if ($value === null || $value === '') {
            throw self::invalid($field, 'The date is required.');
        }

        $date = Carbon::parse((string) $value)->startOfDay();

        if ($date->gt(Carbon::today())) {
            throw self::invalid($field, 'The date cannot be in the future.');
        }

        return $date;
    }

    private static function invalid(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => [$message]]);
    }
}

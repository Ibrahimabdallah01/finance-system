<?php

namespace App\Services;

use App\Enums\Scope;
use App\Enums\Tracking;
use App\Enums\TransactionType;
use App\Enums\UnitStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\InventoryUnit;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Support\Facades\Auth as AuthFacade;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Sales, customer payments, refunds, returns, cancellations and direct selling costs.
 * Revenue is recognised once, at the sale. Payments are cash events only.
 */
final class SalesService
{
    public const RELATIONS = ['customer', 'items.product', 'items.unit', 'returns', 'transactions'];

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly InventoryService $inventory,
        private readonly AuditLogger $audit,
    ) {
    }

    public function create(array $data): Sale
    {
        $this->requireUser();

        return DB::transaction(function () use ($data) {
            $date = InventoryService::date($data['sale_date'] ?? null, 'sale_date');
            $customerId = $data['customer_id'] ?? null;

            if ($customerId !== null && ! Customer::query()->whereKey($customerId)->exists()) {
                throw self::invalid('customer_id', 'Customer not found.');
            }

            $lines = [];
            $subtotal = '0.00';
            $directCost = '0.00';
            $usedUnits = [];
            $reserved = [];

            foreach (array_values($data['items'] ?? []) as $i => $item) {
                $product = Product::query()->lockForUpdate()->find($item['product_id'] ?? 0)
                    ?? throw self::invalid("items.{$i}.product_id", 'Product not found.');

                if (! $product->is_active) {
                    throw self::invalid("items.{$i}.product_id", 'This product is inactive.');
                }

                $price = Money::fromInput($item['unit_price'] ?? throw self::invalid("items.{$i}.unit_price", 'Enter the selling price.'));
                $quantity = max(1, (int) ($item['quantity'] ?? 1));
                $unit = null;

                if ($product->tracking === Tracking::Serialized) {
                    $unit = InventoryUnit::query()->lockForUpdate()->find($item['inventory_unit_id'] ?? 0)
                        ?? throw self::invalid("items.{$i}.inventory_unit_id", 'Choose the phone (unit) being sold.');

                    if ($unit->product_id !== $product->id) {
                        throw self::invalid("items.{$i}.inventory_unit_id", 'This unit belongs to a different product.');
                    }

                    if (in_array($unit->id, $usedUnits, true)) {
                        throw self::invalid("items.{$i}.inventory_unit_id", 'This unit appears twice in the sale.');
                    }

                    if ($unit->status !== UnitStatus::InStock) {
                        throw self::invalid("items.{$i}.inventory_unit_id", 'This unit cannot be sold (status: '.$unit->status->value.').');
                    }

                    $usedUnits[] = $unit->id;
                    $quantity = 1;
                    $cost = $unit->cost();
                } elseif ($product->tracking === Tracking::Quantity) {
                    $available = $product->quantity_on_hand - ($reserved[$product->id] ?? 0);

                    if ($quantity > $available) {
                        throw self::invalid("items.{$i}.quantity", "Only {$available} in stock.");
                    }

                    $reserved[$product->id] = ($reserved[$product->id] ?? 0) + $quantity;
                    $cost = $product->average_cost;
                } else {
                    $cost = Money::fromInput($item['unit_cost'] ?? '0');
                    $directCost = Money::add($directCost, Money::mul($cost, $quantity));
                }

                $lines[] = ['product' => $product, 'unit' => $unit, 'quantity' => $quantity, 'price' => $price, 'cost' => $cost];
                $subtotal = Money::add($subtotal, Money::mul($price, $quantity));
            }

            if ($lines === []) {
                throw self::invalid('items', 'Add at least one item.');
            }

            $discount = Money::fromInput($data['discount_amount'] ?? '0');

            if (Money::compare($discount, $subtotal) > 0) {
                throw self::invalid('discount_amount', 'The discount cannot be more than the sale subtotal.');
            }

            $total = Money::sub($subtotal, $discount);
            $payments = [];
            $paid = '0.00';

            foreach (array_values($data['payments'] ?? []) as $i => $payment) {
                $amount = Money::fromInput($payment['amount'] ?? '0');

                if (Money::compare($amount, '0') <= 0) {
                    throw self::invalid("payments.{$i}.amount", 'The payment must be greater than zero.');
                }

                $paid = Money::add($paid, $amount);
                $payments[] = [$payment, $amount];
            }

            if (Money::compare($paid, $total) > 0) {
                throw self::invalid('payments', 'The payments are more than the sale total ('.Money::format($total).').');
            }

            if (Money::compare($paid, $total) < 0 && $customerId === null) {
                throw self::invalid('customer_id', 'Choose a customer for a sale that is not fully paid.');
            }

            if (Money::compare($directCost, '0') > 0 && empty($data['cost_account_id'])) {
                throw self::invalid('cost_account_id', 'Choose the account that paid for the directly sold items.');
            }

            $sale = new Sale([
                'customer_id' => $customerId,
                'sale_date' => $date->toDateString(),
                'discount_amount' => $discount,
                'total_amount' => $total,
                'due_date' => $data['due_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $sale->forceFill(['user_id' => AuthFacade::id(), 'status' => 'completed', 'sale_number' => $this->nextNumber()])->save();

            foreach ($lines as $line) {
                $item = new SaleItem([
                    'product_id' => $line['product']->id,
                    'inventory_unit_id' => $line['unit']?->id,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['price'],
                    'unit_cost_snapshot' => $line['cost'],
                ]);
                $item->sale_id = $sale->id;
                $item->save();

                $this->inventory->sell($line['product'], $line['unit'], $item, $sale, $date);
            }

            if (Money::compare($directCost, '0') > 0) {
                // The goods were bought and sold at once: cash out now, cost of goods sold through the sale.
                $this->ledger->post([
                    'type' => TransactionType::InventoryPurchase,
                    'account_id' => $data['cost_account_id'],
                    'amount' => $directCost,
                    'transaction_date' => $date->toDateString(),
                    'sale_id' => $sale->id,
                    'description' => 'Cost of directly sold items - '.$sale->sale_number,
                ]);
            }

            foreach ($payments as [$payment, $amount]) {
                $this->postPayment($sale, $payment, $amount, $date->toDateString());
            }

            $this->audit->record($sale, 'created', null, ['sale_number' => $sale->sale_number, 'total' => $total, 'paid' => $paid]);

            return $sale->load(self::RELATIONS);
        });
    }

    public function addPayment(Sale $sale, array $data): Transaction
    {
        $this->requireUser();

        return DB::transaction(function () use ($sale, $data) {
            $sale = $this->lockedSale($sale);
            $this->assertCompleted($sale);

            $amount = Money::fromInput($data['amount']);
            $outstanding = SaleCalculator::figures($sale)['outstanding_balance'];

            if (Money::compare($amount, $outstanding) > 0) {
                throw self::invalid('amount', 'The payment is more than the outstanding balance ('.Money::format($outstanding).').');
            }

            $date = $this->activityDate($data['transaction_date'] ?? null, $sale);

            return $this->postPayment($sale, $data, $amount, $date);
        });
    }

    public function refund(Sale $sale, array $data): Transaction
    {
        $this->requireUser();

        return DB::transaction(function () use ($sale, $data) {
            $sale = $this->lockedSale($sale);
            $this->assertCompleted($sale);

            $amount = Money::fromInput($data['amount']);
            $paid = SaleCalculator::figures($sale)['amount_paid'];

            if (Money::compare($amount, $paid) > 0) {
                throw self::invalid('amount', 'You cannot refund more than the customer has paid ('.Money::format($paid).').');
            }

            return $this->ledger->post([
                'type' => TransactionType::CustomerRefund,
                'account_id' => $data['account_id'],
                'amount' => $amount,
                'transaction_date' => $this->activityDate($data['transaction_date'] ?? null, $sale),
                'sale_id' => $sale->id,
                'payment_method' => $data['payment_method'] ?? null,
                'reference' => $data['reference'] ?? null,
                'description' => 'Refund for '.$sale->sale_number,
                'recipient' => $sale->customer?->name,
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    /** A delivery, repair or other direct selling cost: a business expense attributed to this sale. */
    public function recordCost(Sale $sale, array $data): Transaction
    {
        $this->requireUser();

        return DB::transaction(function () use ($sale, $data) {
            $sale = $this->lockedSale($sale);
            $this->assertCompleted($sale);

            if (Account::query()->find($data['account_id'] ?? 0)?->scope !== Scope::Business) {
                throw self::invalid('account_id', 'Direct selling costs are business expenses: use a business account.');
            }

            return $this->ledger->post([
                'type' => TransactionType::Expense,
                'account_id' => $data['account_id'],
                'category_id' => $data['category_id'],
                'amount' => $data['amount'],
                'transaction_date' => InventoryService::date($data['transaction_date'] ?? null, 'transaction_date')->toDateString(),
                'sale_id' => $sale->id,
                'payment_method' => $data['payment_method'] ?? null,
                'description' => $data['description'],
                'recipient' => $data['recipient'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    /** Reverses revenue (and cost, when the goods go back on the shelf). A refund of cash is separate. */
    public function recordReturn(Sale $sale, array $data): SaleReturn
    {
        $this->requireUser();

        return DB::transaction(function () use ($sale, $data) {
            $sale = $this->lockedSale($sale);
            $this->assertCompleted($sale);

            $item = $sale->items->firstWhere('id', (int) ($data['sale_item_id'] ?? 0))
                ?? throw self::invalid('sale_item_id', 'That item is not part of this sale.');

            $quantity = (int) ($data['quantity'] ?? 1);
            $remaining = $item->quantity - $item->returned_quantity;

            if ($quantity < 1 || $quantity > $remaining) {
                throw self::invalid('quantity', "You can return between 1 and {$remaining} of this item.");
            }

            $subtotal = Money::sum($sale->items->map(fn ($i) => Money::mul($i->unit_price, $i->quantity)));

            $amount = isset($data['return_amount'])
                ? Money::fromInput($data['return_amount'])
                : (Money::compare($subtotal, '0') === 0
                    ? '0.00'
                    : Money::div(Money::mul(Money::mul($item->unit_price, $quantity), $sale->total_amount), $subtotal));

            $alreadyReturned = Money::sum($sale->returns->pluck('return_amount'));

            if (Money::compare(Money::add($alreadyReturned, $amount), $sale->total_amount) > 0) {
                throw self::invalid('return_amount', 'The returned amount is more than the sale total.');
            }

            $direct = $item->product->tracking === Tracking::Direct;
            $restock = (bool) ($data['restock'] ?? ! $direct);

            if ($direct && $restock) {
                throw self::invalid('restock', 'Directly sold items are not stocked, so they cannot be put back into stock.');
            }

            $returnDate = $this->activityDate($data['return_date'] ?? null, $sale, 'return_date');

            $return = new SaleReturn([
                'sale_item_id' => $item->id,
                'quantity' => $quantity,
                'return_date' => $returnDate,
                'return_amount' => $amount,
                'restock' => $restock,
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $return->forceFill(['user_id' => AuthFacade::id(), 'sale_id' => $sale->id])->save();

            $item->forceFill(['returned_quantity' => $item->returned_quantity + $quantity])->save();

            $product = Product::query()->lockForUpdate()->withTrashed()->findOrFail($item->product_id);
            $day = InventoryService::date($returnDate, 'return_date');

            if ($restock) {
                $this->inventory->restock($product, $item->unit, $item, $quantity, $day, $sale, $return);
            } else {
                $this->inventory->retire($item->unit);
            }

            $this->audit->record($sale, 'returned', null, ['item_id' => $item->id, 'quantity' => $quantity, 'amount' => $amount, 'restock' => $restock]);

            return $return;
        });
    }

    /** A cancelled sale disappears from revenue and cost, and its goods go back into stock. */
    public function cancel(Sale $sale, string $reason): Sale
    {
        $this->requireUser();

        return DB::transaction(function () use ($sale, $reason) {
            $sale = $this->lockedSale($sale);
            $this->assertCompleted($sale);

            if ($sale->returns->isNotEmpty()) {
                throw self::invalid('sale', 'This sale has returns and cannot be cancelled.');
            }

            if ($sale->items->contains(fn ($item) => $item->product->tracking === Tracking::Direct)) {
                throw self::invalid('sale', 'A sale with directly sold items cannot be cancelled. Record a return and a refund instead.');
            }

            if (Money::compare(SaleCalculator::figures($sale)['amount_paid'], '0') !== 0) {
                throw self::invalid('sale', 'The customer has paid money on this sale. Refund or void the payments first.');
            }

            $day = InventoryService::date(now()->toDateString(), 'sale');

            foreach ($sale->items as $item) {
                $product = Product::query()->lockForUpdate()->withTrashed()->findOrFail($item->product_id);
                $this->inventory->restock($product, $item->unit, $item, $item->quantity, $day, $sale);
            }

            $sale->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
            $this->audit->record($sale, 'cancelled', null, ['reason' => $reason]);

            return $sale->load(self::RELATIONS);
        });
    }

    private function postPayment(Sale $sale, array $data, string $amount, string $date): Transaction
    {
        return $this->ledger->post([
            'type' => TransactionType::CustomerPayment,
            'account_id' => $data['account_id'] ?? null,
            'amount' => $amount,
            'transaction_date' => $date,
            'sale_id' => $sale->id,
            'payment_method' => $data['payment_method'] ?? null,
            'reference' => $data['reference'] ?? null,
            'description' => 'Payment for '.$sale->sale_number,
            'recipient' => $sale->customer_id !== null ? Customer::query()->find($sale->customer_id)?->name : null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    private function lockedSale(Sale $sale): Sale
    {
        return Sale::query()->lockForUpdate()->findOrFail($sale->getKey())
            ->load(['customer', 'items.product', 'items.unit', 'returns', 'transactions']);
    }

    private function assertCompleted(Sale $sale): void
    {
        if ($sale->status !== 'completed') {
            throw self::invalid('sale', 'This sale is cancelled.');
        }
    }

    /** A payment, refund or return cannot be dated before the sale or in the future. */
    private function activityDate(mixed $value, Sale $sale, string $field = 'transaction_date'): string
    {
        $date = InventoryService::date($value, $field);

        if ($date->lt($sale->sale_date)) {
            throw self::invalid($field, 'The date cannot be before the sale date ('.$sale->sale_date->toDateString().').');
        }

        return $date->toDateString();
    }

    private function nextNumber(): string
    {
        $last = Sale::query()->orderByDesc('id')->value('sale_number');
        $next = $last ? ((int) preg_replace('/\D/', '', $last)) + 1 : 1;

        return sprintf('S-%05d', $next);
    }

    private function requireUser(): void
    {
        if (AuthFacade::id() === null) {
            throw new LogicException('SalesService must run for a logged-in user.');
        }
    }

    private static function invalid(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => [$message]]);
    }
}

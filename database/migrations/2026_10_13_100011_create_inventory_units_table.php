<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per individually tracked phone or device.
        Schema::create('inventory_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('imei', 20)->nullable();
            $table->string('serial_number', 64)->nullable();
            $table->string('storage', 30)->nullable();
            $table->string('color', 50)->nullable();
            $table->enum('item_condition', ['new', 'like_new', 'used', 'refurbished', 'for_parts'])->default('used');
            $table->enum('acquisition_source', ['purchase', 'opening_stock', 'trade_in'])->default('purchase');
            $table->date('purchase_date');
            $table->decimal('purchase_price', 15, 2);
            $table->decimal('additional_costs', 15, 2)->default(0); // cost basis = price + additional costs
            // Cash purchase -> transaction of type inventory_purchase. Purchase on credit -> debt.
            // Opening stock / trade-in have neither.
            $table->foreignId('purchase_transaction_id')->nullable()
                ->constrained('transactions')->restrictOnDelete();
            $table->foreignId('debt_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('status', ['in_stock', 'reserved', 'sold', 'returned_to_supplier', 'written_off'])
                ->default('in_stock');
            $table->text('notes')->nullable();
            $table->timestamps();

            // NULLs are allowed many times, so phones without an IMEI still work.
            $table->unique(['user_id', 'imei']);
            $table->unique(['user_id', 'serial_number']);
            $table->index(['user_id', 'status']);
        });

        DB::statement('ALTER TABLE inventory_units ADD CONSTRAINT chk_inventory_units_costs CHECK (purchase_price >= 0 AND additional_costs >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_units');
    }
};

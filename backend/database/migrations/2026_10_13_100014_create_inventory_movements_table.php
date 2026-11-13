<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('movement_type', [
                'purchase', 'sale', 'sale_return', 'adjustment_in',
                'adjustment_out', 'write_off', 'supplier_return',
            ]);
            $table->integer('quantity'); // signed: + stock in, - stock out
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->date('movement_date');
            $table->foreignId('sale_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('sale_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('sale_return_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'movement_date']);
            $table->index(['user_id', 'movement_type']);
        });

        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT chk_inventory_movements_quantity CHECK (quantity <> 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};

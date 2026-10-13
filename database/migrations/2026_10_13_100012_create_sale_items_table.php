<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            // Not unique on purpose: a unit may be sold again after a cancelled sale or return.
            // Double-selling is blocked by inventory_units.status plus row locking in the service.
            $table->foreignId('inventory_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 15, 2);
            // Cost per unit frozen at the moment of sale. COGS = quantity * unit_cost_snapshot,
            // so later cost changes never rewrite past profit.
            $table->decimal('unit_cost_snapshot', 15, 2)->default(0);
            $table->unsignedInteger('returned_quantity')->default(0);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT chk_sale_items_values CHECK (quantity >= 1 AND unit_price >= 0 AND unit_cost_snapshot >= 0 AND returned_quantity <= quantity)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};

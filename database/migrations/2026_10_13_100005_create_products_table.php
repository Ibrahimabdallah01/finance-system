<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->enum('product_type', ['phone', 'accessory', 'other'])->default('phone');
            // serialized = each unit tracked by IMEI/serial (rows in inventory_units)
            // quantity   = counted stock with weighted-average cost
            // direct     = sold directly, no stock kept
            $table->enum('tracking', ['serialized', 'quantity', 'direct']);
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('sku', 60)->nullable();
            $table->decimal('default_selling_price', 15, 2)->nullable();
            $table->unsignedInteger('reorder_level')->nullable(); // low-stock warning, quantity items only
            // CACHE columns for tracking = quantity. Source of truth is inventory_movements;
            // they are updated in the same DB transaction and verified by tests.
            $table->integer('quantity_on_hand')->default(0);
            $table->decimal('average_cost', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'sku']);
            $table->index(['user_id', 'tracking', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reverses revenue (and COGS when restocked). The cash refund, if any, is a separate
        // customer_refund transaction linked by sale_id, because a refund can be partial,
        // delayed, or netted against an unpaid balance.
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->date('return_date');
            $table->decimal('return_amount', 15, 2); // revenue reversed
            $table->boolean('restock')->default(true); // false = damaged, cost stays as a loss
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'return_date']);
        });

        DB::statement('ALTER TABLE sale_returns ADD CONSTRAINT chk_sale_returns_values CHECK (quantity >= 1 AND return_amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_returns');
    }
};

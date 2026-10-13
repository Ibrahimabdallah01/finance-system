<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('sale_number', 30);
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete(); // null = walk-in
            $table->date('sale_date');
            $table->enum('status', ['completed', 'cancelled'])->default('completed');
            $table->decimal('discount_amount', 15, 2)->default(0);
            // Agreed price = sum of sale_items (quantity * unit_price) - discount_amount.
            // This is the revenue recognised at sale_date, whatever has been paid so far.
            $table->decimal('total_amount', 15, 2);
            $table->date('due_date')->nullable(); // when an unpaid balance is expected
            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'sale_number']);
            $table->index(['user_id', 'sale_date']);
            $table->index(['user_id', 'status']);
        });

        DB::statement('ALTER TABLE sales ADD CONSTRAINT chk_sales_amounts CHECK (total_amount >= 0 AND discount_amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};

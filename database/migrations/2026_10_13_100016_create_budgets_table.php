<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Actual spending is computed from posted 'expense' transactions only, so transfers,
        // inventory purchases, loan principal and owner withdrawals are excluded automatically.
        // category_id NULL = overall budget for the scope. A parent category includes its children.
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->enum('scope', ['personal', 'business']);
            $table->foreignId('category_id')->nullable()
                ->constrained('transaction_categories')->restrictOnDelete();
            $table->string('name');
            $table->enum('period', ['daily', 'weekly', 'monthly', 'yearly']);
            $table->decimal('amount', 15, 2);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'scope', 'is_active']);
        });

        DB::statement('ALTER TABLE budgets ADD CONSTRAINT chk_budgets_amount CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};

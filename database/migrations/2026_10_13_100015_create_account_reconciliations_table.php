<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Snapshot of a manual count. Stored values are historical facts, not recomputed.
        Schema::create('account_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->date('reconciled_on');
            $table->decimal('recorded_balance', 15, 2); // what the system said
            $table->decimal('actual_balance', 15, 2);   // what the user counted
            $table->decimal('difference', 15, 2);       // actual - recorded
            $table->foreignId('adjustment_transaction_id')->nullable()
                ->constrained('transactions')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'reconciled_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_reconciliations');
    }
};

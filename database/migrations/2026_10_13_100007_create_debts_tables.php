<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Loans and supplier credit. Customer balances on sales are NOT stored here:
        // they are derived from sales minus customer_payment transactions.
        Schema::create('debts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->enum('scope', ['personal', 'business']);
            $table->enum('direction', ['receivable', 'payable']); // receivable = owed to me
            $table->enum('source', ['loan', 'supplier_credit', 'other']);
            $table->string('party_name');
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('original_amount', 15, 2);
            $table->date('issued_on');
            $table->date('due_date')->nullable();
            $table->enum('status', ['open', 'settled', 'written_off'])->default('open');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'direction', 'status']);
            $table->index(['user_id', 'due_date']);
        });

        DB::statement('ALTER TABLE debts ADD CONSTRAINT chk_debts_amount CHECK (original_amount > 0)');

        // Planned repayment schedule. Actual repayments are transactions with debt_id set.
        Schema::create('debt_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debt_id')->constrained()->cascadeOnDelete();
            $table->date('due_date');
            $table->decimal('amount_due', 15, 2);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['debt_id', 'due_date']);
        });

        DB::statement('ALTER TABLE debt_installments ADD CONSTRAINT chk_debt_installments_amount CHECK (amount_due > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_installments');
        Schema::dropIfExists('debts');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per financial EVENT. The amount is always positive; the direction of
        // cash movement lives in ledger_entries. Amounts are stored here once and never
        // copied into payments/repayments tables.
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // For owner_withdrawal / owner_contribution: scope of the SOURCE account.
            $table->enum('scope', ['personal', 'business']);
            $table->enum('type', [
                'income',              // personal income, or non-sales business income
                'expense',             // operating / personal expense (incl. loan interest)
                'transfer',            // between own accounts, same scope
                'owner_withdrawal',    // business account -> personal account
                'owner_contribution',  // personal account -> business account
                'inventory_purchase',  // stock bought: cash out, asset up, NOT an expense
                'customer_payment',    // cash received against a sale
                'customer_refund',     // cash returned to a customer
                'loan_received',       // liability up, not income
                'loan_given',          // receivable up, not an expense
                'debt_repayment_in',   // money received on a receivable
                'debt_repayment_out',  // principal repaid on a payable
                'adjustment',          // reconciliation difference, not income/expense
            ]);
            $table->enum('status', ['posted', 'voided'])->default('posted');
            $table->date('transaction_date');
            $table->decimal('amount', 15, 2);
            $table->foreignId('category_id')->nullable()
                ->constrained('transaction_categories')->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('debt_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('payment_method', ['cash', 'mobile_money', 'bank', 'other'])->nullable();
            $table->string('description')->nullable();
            $table->string('recipient')->nullable(); // payer or payee
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'scope', 'transaction_date']);
            $table->index(['user_id', 'type', 'transaction_date']);
            $table->index(['user_id', 'status']);
        });

        DB::statement('ALTER TABLE transactions ADD CONSTRAINT chk_transactions_amount CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};

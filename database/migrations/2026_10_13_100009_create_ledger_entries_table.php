<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Signed money movement per account: + in, - out. Account balance =
        // opening_balance + SUM(amount) over entries whose transaction is 'posted'.
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('entry_date'); // copy of transactions.transaction_date for fast balance queries
            $table->timestamps();

            $table->index(['account_id', 'entry_date']);
        });

        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT chk_ledger_entries_amount CHECK (amount <> 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};

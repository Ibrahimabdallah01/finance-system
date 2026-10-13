<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->enum('goal_type', [
                'business_capital', 'stock_purchase', 'emergency',
                'equipment', 'rent', 'education', 'other',
            ])->default('other');
            $table->decimal('target_amount', 15, 2);
            $table->date('target_date')->nullable();
            $table->foreignId('account_id')->nullable()->constrained()->restrictOnDelete(); // where the money is kept
            $table->enum('status', ['active', 'achieved', 'cancelled'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        DB::statement('ALTER TABLE savings_goals ADD CONSTRAINT chk_savings_goals_target CHECK (target_amount > 0)');

        // Saved amount = SUM(amount). Positive = contribution, negative = withdrawal.
        // transaction_id points at the transfer that moved the money (nullable when money
        // already sat in the savings account and is only being earmarked for the goal).
        Schema::create('goal_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('savings_goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('contribution_date');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['savings_goal_id', 'contribution_date']);
        });

        DB::statement('ALTER TABLE goal_contributions ADD CONSTRAINT chk_goal_contributions_amount CHECK (amount <> 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_contributions');
        Schema::dropIfExists('savings_goals');
    }
};

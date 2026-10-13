<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->enum('scope', ['personal', 'business']);
            $table->string('name');
            $table->enum('type', ['cash', 'bank', 'mobile_money', 'savings', 'other']);
            $table->string('provider')->nullable(); // e.g. M-Pesa, Airtel Money, Mixx by Yas, bank name
            // Signed on purpose: an overdrawn account may start negative.
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->date('opening_balance_date');
            $table->boolean('is_active')->default(true); // deactivate instead of deleting
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'scope', 'name']);
            $table->index(['user_id', 'scope', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};

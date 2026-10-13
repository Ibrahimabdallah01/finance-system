<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()
                ->constrained('transaction_categories')->restrictOnDelete();
            $table->enum('scope', ['personal', 'business']);
            $table->enum('kind', ['income', 'expense']);
            $table->string('name');
            $table->boolean('is_system')->default(false); // seeded defaults; still editable
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // Name uniqueness among siblings is enforced in the Form Request
            // (a unique index cannot cover a NULL parent_id in MySQL).
            $table->index(['user_id', 'scope', 'kind', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_categories');
    }
};

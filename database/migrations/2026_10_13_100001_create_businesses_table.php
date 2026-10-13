<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->char('currency', 3)->default('TZS');
            // Date from which the books are trusted; opening balances are dated here.
            $table->date('financial_start_date')->nullable();
            $table->timestamps();

            // Version 1: one business per owner. Drop this unique index to allow several later.
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};

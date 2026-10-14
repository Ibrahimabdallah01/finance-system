<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ReconciliationController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\TransferController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

// No public registration: the single owner is created with `php artisan finance:create-owner`.
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

// Every endpoint below requires a login and only ever sees the logged-in owner's rows.
Route::middleware('auth:sanctum')->group(function () {
    // Accounts and statements
    Route::get('accounts/{account}/ledger', [AccountController::class, 'ledger']);
    Route::apiResource('accounts', AccountController::class);

    // Reconciliation
    Route::get('accounts/{account}/reconciliations', [ReconciliationController::class, 'index']);
    Route::post('accounts/{account}/reconciliations', [ReconciliationController::class, 'store']);

    // Transactions (all types) and transfers
    Route::get('transactions', [TransactionController::class, 'index']);
    Route::get('transactions/{transaction}', [TransactionController::class, 'show']);
    Route::post('transactions/{transaction}/void', [TransactionController::class, 'void']);

    Route::post('transfers', [TransferController::class, 'store']);
    Route::put('transfers/{transaction}', [TransferController::class, 'update']);
});

<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DebtController;
use App\Http\Controllers\Api\DebtReportController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\IncomeExpenseController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProfitReportController;
use App\Http\Controllers\Api\ReceiptController;
use App\Http\Controllers\Api\ReconciliationController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SavingsGoalController;
use App\Http\Controllers\Api\SupplierController;
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

    // Categories
    Route::apiResource('categories', CategoryController::class)->except(['show']);

    // Income and expenses
    Route::post('income', [IncomeExpenseController::class, 'storeIncome']);
    Route::put('income/{transaction}', [IncomeExpenseController::class, 'updateIncome']);
    Route::post('expenses', [IncomeExpenseController::class, 'storeExpense']);
    Route::put('expenses/{transaction}', [IncomeExpenseController::class, 'updateExpense']);

    // Transactions (all types), history and receipts
    Route::get('transactions', [TransactionController::class, 'index']);
    Route::get('transactions/{transaction}', [TransactionController::class, 'show']);
    Route::post('transactions/{transaction}/void', [TransactionController::class, 'void']);
    Route::get('transactions/{transaction}/history', [TransactionController::class, 'history']);
    Route::get('transactions/{transaction}/receipts', [ReceiptController::class, 'index']);
    Route::post('transactions/{transaction}/receipts', [ReceiptController::class, 'store']);
    Route::get('receipts/{receipt}/download', [ReceiptController::class, 'download']);
    Route::delete('receipts/{receipt}', [ReceiptController::class, 'destroy']);

    // Suppliers, customers, products
    Route::apiResource('suppliers', SupplierController::class);
    Route::apiResource('customers', CustomerController::class);
    Route::apiResource('products', ProductController::class)->except(['destroy']);

    // Inventory
    Route::post('inventory/purchases', [InventoryController::class, 'purchase']);
    Route::post('inventory/write-offs', [InventoryController::class, 'writeOff']);
    Route::get('inventory/summary', [InventoryController::class, 'summary']);
    Route::get('inventory/units', [InventoryController::class, 'units']);
    Route::get('inventory/units/{unit}', [InventoryController::class, 'showUnit']);
    Route::put('inventory/units/{unit}', [InventoryController::class, 'updateUnit']);

    // Sales
    Route::get('sales', [SaleController::class, 'index']);
    Route::post('sales', [SaleController::class, 'store']);
    Route::get('sales/{sale}', [SaleController::class, 'show']);
    Route::post('sales/{sale}/cancel', [SaleController::class, 'cancel']);
    Route::post('sales/{sale}/payments', [SaleController::class, 'payment']);
    Route::post('sales/{sale}/refunds', [SaleController::class, 'refund']);
    Route::post('sales/{sale}/returns', [SaleController::class, 'saleReturn']);
    Route::post('sales/{sale}/costs', [SaleController::class, 'cost']);

    // Budgets
    Route::get('budgets/{budget}/history', [BudgetController::class, 'history']);
    Route::apiResource('budgets', BudgetController::class);

    // Savings goals
    Route::apiResource('savings-goals', SavingsGoalController::class)->parameters(['savings-goals' => 'goal']);
    Route::post('savings-goals/{goal}/contributions', [SavingsGoalController::class, 'contribute']);
    Route::post('savings-goals/{goal}/withdrawals', [SavingsGoalController::class, 'withdraw']);

    // Debts, receivables and payables
    Route::get('debts/summary', [DebtReportController::class, 'summary']);
    Route::get('receivables', [DebtReportController::class, 'receivables']);
    Route::get('debts', [DebtController::class, 'index']);
    Route::post('debts', [DebtController::class, 'store']);
    Route::get('debts/{debt}', [DebtController::class, 'show']);
    Route::post('debts/{debt}/repayments', [DebtController::class, 'repay']);
    Route::post('debts/{debt}/write-off', [DebtController::class, 'writeOff']);
    Route::put('debts/{debt}/schedule', [DebtController::class, 'schedule']);

    // Dashboard
    Route::get('dashboard', DashboardController::class);

    // Reports
    Route::get('reports/profit-loss', ProfitReportController::class);

    // Transfers between own accounts
    Route::post('transfers', [TransferController::class, 'store']);
    Route::put('transfers/{transaction}', [TransferController::class, 'update']);
});

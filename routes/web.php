<?php

use App\Http\Controllers\LoginController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\BarcodeController;
use App\Http\Controllers\UserManagerController;
use App\Http\Controllers\BakongKhqrController;
use App\Http\Controllers\InventoryItemController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\TypeController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\ShopController;

// Public auth routes
Route::get('/', [LoginController::class, 'showLoginForm']);
Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [LoginController::class, 'login'])->name('login.submit');
Route::match(['get', 'post'], '/logout', [LoginController::class, 'logout'])->name('logout');

// Protected routes — require login
Route::middleware('auth')->group(function () {

    // Dashboard / POS
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/pos', [PosController::class, 'index'])->name('pos.index');
    Route::post('/pos/checkout', [PosController::class, 'checkout'])->name('pos.checkout');
    Route::get('/pos/receipt', [PosController::class, 'receipt'])->name('pos.receipt');
    Route::post('/pos/khqr/generate', [BakongKhqrController::class, 'khqrGenerate'])->name('pos.khqr.generate');
    Route::post('/pos/khqr/check-payment', [BakongKhqrController::class, 'checkPayment'])->name('pos.khqr.check');
    Route::get('/pos/display', function () {
        return view('pos.customer-display');
    })->name('pos.display');

    // Orders — custom routes MUST come before the resource
    Route::delete('orders/bulk-destroy', [OrderController::class, 'bulkDestroy'])->name('orders.bulkDestroy');
    Route::get('/orders/{order}/invoice_partial', [OrderController::class, 'invoicePartial'])->name('orders.invoicePartial');
    Route::get('/orders/invoice_combined', [OrderController::class, 'invoiceCombined'])->name('orders.invoiceCombined');
    Route::get('/orders/invoice_combined/pdf', [OrderController::class, 'invoiceCombinedPdf'])->name('orders.invoiceCombinedPdf');
    Route::resource('orders', OrderController::class)->names('orders');

    Route::get('/barcodes', [BarcodeController::class, 'index'])->name('barcodes.index');

    // Types / Units
    Route::resource('types', TypeController::class);
    Route::delete('units-bulk-destroy', [UnitController::class, 'bulkDestroy'])->name('units.bulk-destroy');
    Route::resource('units', UnitController::class)
        ->parameters(['units' => 'id'])
        ->names('units');

    // Products — custom routes MUST come before the resource
    Route::post('products/import', [ProductController::class, 'import'])->name('products.import');
    Route::get('products/export', [ProductController::class, 'export'])->name('products.export');
    Route::delete('products/bulk-destroy', [ProductController::class, 'bulkDestroy'])->name('products.bulkDestroy');
    Route::resource('products', ProductController::class)->names('products');

    // Categories — bulk-destroy MUST come before the resource
    Route::delete('/categories/bulk-destroy', [CategoryController::class, 'bulkDestroy'])
        ->name('categories.bulkDestroy');
    Route::resource('categories', CategoryController::class)->names('categories');

    Route::resource('customers', CustomerController::class)->names('customers');
    Route::resource('inventorys', InventoryController::class)->names('inventorys');
    Route::resource('inventory', InventoryController::class);

    Route::delete('/inventory-items/bulk-destroy', [InventoryItemController::class, 'bulkDestroy'])
        ->name('inventory-items.bulkDestroy');
    Route::resource('inventory-items', InventoryItemController::class);

    // Payments — custom routes MUST come before the resource
    Route::get('/payments/search', [PaymentController::class, 'search'])->name('payments.search');
    Route::get('/payments/payment/{order}', [PaymentController::class, 'payment'])->name('payments.payment');
    Route::post('/payments/store', [PaymentController::class, 'store'])->name('payments.store');
    Route::resource('payments', PaymentController::class)
        ->names('payments')
        ->except(['store']);

    // Roles / Users
    Route::resource('userroles', RoleController::class)->parameters([
        'userroles' => 'role'
    ]);
    Route::resource('usermanagers', UserManagerController::class);

    // Reports
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/generate', fn () => redirect()->route('reports.index'));
    Route::post('reports/generate', [ReportController::class, 'generate'])->name('reports.generate');
    Route::get('reports/export', [ReportController::class, 'export'])->name('reports.export');

    // Transactions
    Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::post('/transactions/telegram-report', [TransactionController::class, 'sendTelegram'])
        ->name('transactions.telegram');

    // Backup
    Route::get('/backup', [BackupController::class, 'index'])->name('backup.index');
    Route::post('/backup', [BackupController::class, 'store'])->name('backup.store');
    Route::get('/backup/download/{file}', [BackupController::class, 'download'])->name('backup.download');
    Route::delete('/backup/clear', [BackupController::class, 'clear'])->name('backup.clear');
    Route::delete('/backup/delete', [BackupController::class, 'deleteSelected'])->name('backup.delete');

    // Shop
    Route::get('/shop', [ShopController::class, 'index'])->name('shop.index');
    Route::get('/shop/{id}', [ShopController::class, 'show'])->name('shop.show');
});

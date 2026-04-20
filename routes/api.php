<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Health check (outside v1 prefix for monitoring)
Route::get('/health', [\App\Http\Controllers\HealthController::class]);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\TransactionController;
use App\Http\Controllers\Admin\PurchaseController;
use App\Http\Controllers\Admin\PromotionController;
use App\Http\Controllers\Admin\VoucherController;
use App\Http\Controllers\Admin\ComboController;
use App\Http\Controllers\Admin\StatisticController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\ProductController as PublicProductController;

Route::prefix('v1')->group(function () {

    // Auth routes with rate limiting
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    // Route bảo vệ bằng token
    Route::middleware('auth:api')->get('/me', function (Request $request) {
        return response()->json([
            'success' => true,
            'data' => [
                'user' => $request->user(),
            ],
        ]);
    });
    Route::middleware('auth:api')->put('/auth/profile', [AuthController::class, 'updateProfile']);

    // Cart & Checkout
    Route::get('/cart', [CartController::class, 'index']);
    Route::post('/cart/add', [CartController::class, 'add']);
    Route::post('/cart/update', [CartController::class, 'update']);
    Route::post('/cart/remove', [CartController::class, 'remove']);
    Route::post('/checkout', [OrderController::class, 'checkout']);
    Route::middleware('auth:api')->get('/orders/user', [OrderController::class, 'userOrders']);
    Route::post('/payment/process', [PaymentController::class, 'process']);

    // Wishlist & Voucher
    Route::middleware('auth:api')->get('/wishlists', [\App\Http\Controllers\WishlistController::class, 'index']);
    Route::middleware('auth:api')->post('/wishlists/toggle', [\App\Http\Controllers\WishlistController::class, 'toggle']);
    Route::post('/vouchers/validate', [\App\Http\Controllers\VoucherController::class, 'validateVoucher']);

    // User Addresses
    Route::middleware('auth:api')->get('/user/addresses', [\App\Http\Controllers\UserAddressController::class, 'index']);
    Route::middleware('auth:api')->post('/user/addresses', [\App\Http\Controllers\UserAddressController::class, 'store']);
    Route::middleware('auth:api')->put('/user/addresses/{id}', [\App\Http\Controllers\UserAddressController::class, 'update']);
    Route::middleware('auth:api')->delete('/user/addresses/{id}', [\App\Http\Controllers\UserAddressController::class, 'destroy']);
    Route::middleware('auth:api')->put('/user/addresses/{id}/default', [\App\Http\Controllers\UserAddressController::class, 'setDefault']);

    // Shipping & GHN Endpoints
    Route::get('/shipping/provinces', [\App\Http\Controllers\ShippingController::class, 'getProvinces']);
    Route::get('/shipping/districts', [\App\Http\Controllers\ShippingController::class, 'getDistricts']);
    Route::get('/shipping/wards', [\App\Http\Controllers\ShippingController::class, 'getWards']);
    Route::post('/shipping/calculate', [\App\Http\Controllers\ShippingController::class, 'calculateFee']);

    Route::get('/products', [PublicProductController::class, 'index']);
    Route::get('/products/{id}', [PublicProductController::class, 'show']);

    // Public categories (no auth required - used by product filters)
    Route::get('/categories', [CategoryController::class, 'index']);

    Route::prefix('admin')->middleware(['auth:api', 'admin'])->group(function () {
        Route::apiResource('users', \App\Http\Controllers\Admin\UserController::class);
        Route::apiResource('products', ProductController::class);
        Route::apiResource('categories', CategoryController::class);
        Route::apiResource('orders', AdminOrderController::class);

        // Warehouse Routes
        Route::get('warehouses', [\App\Http\Controllers\Admin\WarehouseController::class, 'index']);
        Route::get('inventory-history', [\App\Http\Controllers\Admin\WarehouseController::class, 'history']);

        // New Pancake API Routes
        Route::apiResource('customers', CustomerController::class);
        Route::apiResource('transactions', TransactionController::class)->only(['index', 'store']);
        Route::apiResource('purchases', PurchaseController::class)->only(['index', 'store', 'update']);
        Route::apiResource('promotions', PromotionController::class)->only(['index']);
        Route::apiResource('vouchers', VoucherController::class)->only(['index']);
        Route::apiResource('combos', ComboController::class)->only(['index']);

        Route::get('statistics/sales', [StatisticController::class, 'sales']);
        Route::get('statistics/inventory', [StatisticController::class, 'inventory']);
    });

});

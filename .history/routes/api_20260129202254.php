<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusinessController;

use App\Http\Controllers\Api\CommonController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\CityController;

use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductVariantController;
use App\Http\Controllers\Api\VariantPriceController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\UploadController;

use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\DeliveryOrderController;
use App\Http\Controllers\Api\DashboardController;

use App\Http\Controllers\Api\UserOnboardController;
use App\Http\Controllers\Api\UsersOnboardController;
use App\Http\Controllers\Api\UserController;

use App\Http\Controllers\Api\DeliveryManDocumentController;
use App\Http\Controllers\Api\WalletController;

use App\Http\Controllers\Reports\DealerOrderReport;
use App\Http\Controllers\Api\UserAddressController;

use App\Http\Controllers\NotificationController;
use App\Http\Controllers\CourierJobController;
use App\Http\Controllers\Api\CashReportController;

// Partner
use App\Http\Controllers\Api\AuthPartnerController;
use App\Http\Controllers\Api\PartnerOrderController;
use App\Http\Controllers\Api\PartnerProductController;
use App\Http\Controllers\Api\PartnerVariantController;
use App\Http\Controllers\Api\PartnerCustomerController;
use App\Http\Controllers\Api\PartnerCatalogController;
use App\Http\Controllers\Api\PartnerClientController;

Route::prefix('v1')->group(function () {

    // ===== AUTH (Public) =====
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
    Route::post('mobilelogin', [UserController::class, 'login']);
    Route::post('register', [UserController::class, 'register']);

    // ===== COMMON / MAPS =====
    Route::get('place-autocomplete-api', [CommonController::class, 'placeAutoComplete']);
    Route::get('place-detail-api', [CommonController::class, 'placeDetail']);
    Route::get('distance-matrix-api', [CommonController::class, 'distanceMatrix']);
    Route::get('combined-distance-matrix-api', [CommonController::class, 'combinedDistanceAndRoute']);

    // ===== LOCATION =====
    Route::get('countries', [CountryController::class, 'countries']);
    Route::get('cities', [CityController::class, 'cities']); // ?country_id=
    Route::get('country-list', [CountryController::class, 'getList']);
    Route::get('country-detail', [CountryController::class, 'getDetail']);
    Route::get('city-list', [CityController::class, 'getList']);
    Route::get('city-detail', [CityController::class, 'getDetail']);

    // ===== CATALOG =====
    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{product}', [ProductController::class, 'show']);
    Route::get('products/{product}/variants', [ProductController::class, 'variants']);
    Route::get('products/{product}/prices', [ProductController::class, 'prices']);
    Route::get('products/{product}/variantsuser', [ProductController::class, 'variantsUser']);

    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('categorilist', [CategoryController::class, 'catlist']);

    Route::post('upload', [UploadController::class, 'store']);

    Route::post('products', [ProductController::class, 'store']);
    Route::post('productsfull', [ProductController::class, 'storefull']);
    Route::put('products/{product}', [ProductController::class, 'update']);

    // Variants / Prices (tek kaynak)
    Route::post('products/{product}/variants', [ProductVariantController::class, 'store']);
    Route::put('variants/{variant}', [ProductVariantController::class, 'update']);

    Route::post('variants/{variant}/prices', [VariantPriceController::class, 'store']);
    Route::put('prices/{price}', [VariantPriceController::class, 'update']);
    Route::post('user-product-prices/upsert', [VariantPriceController::class, 'upsert']);

    // ===== DASHBOARD =====
    Route::get('summary', [DashboardController::class, 'summary']);
    Route::get('recent-orders', [DashboardController::class, 'recent']);
    Route::get('sum-by-restaurant', [DashboardController::class, 'summaryByRestaurant']);
    Route::get('sum-restaurant', [DashboardController::class, 'summaryForRestaurant']);
    Route::get('top-products-restaurant', [DashboardController::class, 'topProductsForRestaurant']);

    // ===== ORDERS =====
    Route::post('orders', [OrderController::class, 'store']);
    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
    Route::get('orders/{key}/items-enriched', [OrderController::class, 'itemsEnrichedByKey']);
    Route::post('orders/items-enriched/by-email', [OrderController::class, 'itemsEnrichedByEmail']);
    Route::post('previous-orders', [OrderController::class, 'previousOrdersOfRestaurant']);
    Route::post('orderdetail', [OrderController::class, 'orderDetailHaldeki']);
    Route::post('orderdetail-tedarik', [OrderController::class, 'orderDetailTedarik']);
    Route::post('orders/{order_number}/status', [OrderController::class, 'setStatusByNumber']);
    Route::post('order-update-status', [OrderController::class, 'orderUpdateStatus']);
    Route::post('orders/siparisduzenle', [OrderController::class, 'siparisduzenle']);

    // Dealer/Vendor/Supplier (mevcut isimleri koru)
    Route::post('dealer-orders', [OrderController::class, 'dealer']);
    Route::post('dealer-order-detail', [OrderController::class, 'orderDetailDealer']);
    Route::post('dealer-order-update-status', [OrderController::class, 'dealerUpdateStatus']);

    Route::post('vendor-orders', [OrderController::class, 'vendor']);
    Route::post('vendor-order-detail', [OrderController::class, 'vendorDetailDealer']);
    Route::post('vendor-order-update-status', [OrderController::class, 'vendorUpdateStatus']);

    Route::get('orders/supplier', [OrderController::class, 'supplier']);
    Route::post('supplier', [OrderController::class, 'supplierOrders']);
    Route::post('supplier-order-update-status', [OrderController::class, 'supplierUpdateStatus']);

    // ===== DELIVERY ORDERS =====
    Route::get('delivery-orders', [DeliveryOrderController::class, 'index']);
    Route::post('delivery-orders', [DeliveryOrderController::class, 'store']);
    Route::get('delivery-orders/{deliveryOrder}', [DeliveryOrderController::class, 'show']);
    Route::match(['put','patch'], 'delivery-orders/{deliveryOrder}', [DeliveryOrderController::class, 'update']);
    Route::delete('delivery-orders/{deliveryOrder}', [DeliveryOrderController::class, 'destroy']);

    // Esnaf liste + handoff
    Route::get('order-list-esnaf', [DeliveryOrderController::class, 'getListEsnaf']);
    Route::post('handoff-to-couriers', [OrderController::class, 'handoffToCouriers']);
    Route::post('dealer-delivery-handoff-multiple', [DeliveryOrderController::class, 'handoffMultiple']);

    // Legacy alias’lar (kalsın)
    Route::get('order-list', [DeliveryOrderController::class, 'getList']);
    Route::get('order-detail', [DeliveryOrderController::class, 'getDetail']);
    Route::post('order-save', [DeliveryOrderController::class, 'store']);
    Route::post('order-update/{id}', [DeliveryOrderController::class, 'updateDelivery']);
    Route::post('order-delete/{id}', [DeliveryOrderController::class, 'destroy']);

    // ===== ONBOARD =====
    Route::post('couriers', [UserOnboardController::class,'storeCourier']);
    Route::get('couriers', [UserOnboardController::class, 'index']);
    Route::get('couriers/{id}', [UserOnboardController::class, 'CourierShow']);
    Route::put('couriers/{id}', [UserOnboardController::class, 'CourieUpdate']);

    Route::post('clients', [UserOnboardController::class,'storeClient']);
    Route::get('clients', [UserOnboardController::class,'index']);
    Route::get('clients/{id}', [UserOnboardController::class, 'show']);
    Route::put('clients/{id}', [UserOnboardController::class, 'update']);

    Route::post('users', [UsersOnboardController::class,'storeClient']);
    Route::get('users', [UsersOnboardController::class,'index']);
    Route::get('users/{id}', [UsersOnboardController::class, 'show']);
    Route::put('users/{id}', [UsersOnboardController::class, 'update']);

    // ===== CART =====
    Route::get('cart', [CartController::class, 'show']);
    Route::post('cart/add', [CartController::class, 'add']);
    Route::post('cart/remove', [CartController::class, 'remove']);
    Route::post('cart/update', [CartController::class, 'update']);

    // ===== WALLET / DOCS =====
    Route::get('wallet/balance/{userId}', [WalletController::class, 'balance']);

    Route::get('delivery-man-document-list', [DeliveryManDocumentController::class, 'index']);
    Route::post('multiple-delete-deliveryman-document', [DeliveryManDocumentController::class, 'multipleDeleteRecords']);
    Route::post('delivery-man-document-save', [DeliveryManDocumentController::class, 'store']);
    Route::post('delivery-man-document-delete/{id}', [DeliveryManDocumentController::class, 'destroy']);
    Route::post('delivery-man-document-action', [DeliveryManDocumentController::class, 'action']);
    Route::get('delivery-man-docs/{userId}', [DeliveryManDocumentController::class, 'list']);

    // Misc
    Route::get('test-push', [NotificationController::class, 'testPush']);
    Route::get('send-job', [CourierJobController::class, 'sendJobToCouriers']);
    Route::get('cash', [CashReportController::class, 'index']);

    Route::apiResource('useraddresses', UserAddressController::class);

    // ===== AUTH (Protected) =====
    Route::middleware('auth:sanctum')->group(function () {

        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('change-password-client', [UserController::class, 'changePasswordclient']);
        Route::get('me/businesses', [BusinessController::class, 'myBusinesses']);

        Route::get('auth/me', [UserController::class, 'profile']);
        Route::get('profile', [UserController::class, 'profile']);
        Route::post('profile/update', [UserController::class, 'updateProfile']);
        Route::post('change-password', [UserController::class, 'changePassword']);
        Route::post('profile/documents', [UserController::class, 'uploadProfileDocument']);
        Route::get('profile/{id}', [UserController::class, 'show']);

        Route::post('clients/{id}/change-password', [UserOnboardController::class, 'changePassword']);

        Route::prefix('reports')->group(function () {
            Route::get('cash', [DealerOrderReport::class, 'cash']);
            Route::get('businesses', [DealerOrderReport::class, 'business']);
            Route::get('couriers', [DealerOrderReport::class, 'courier']);
            Route::get('orders', [DealerOrderReport::class, 'orders']);
            Route::get('me/businesses', [DealerOrderReport::class, 'myBusinesses']);
        });
    });
});

// ===== PARTNER API =====
Route::prefix('partner/v1')->group(function () {

    Route::post('auth/token', [AuthPartnerController::class, 'token']);

    // public bırakılmış
    Route::apiResource('partner-clients', PartnerClientController::class);
    Route::get('partner-clients/{id}/orders', [PartnerClientController::class, 'show2']);

    Route::middleware('partner.auth')->group(function () {

        Route::post('/partner/v1/catalog/build-price-list', [PartnerCatalogController::class, 'buildPriceList']);
 
        Route::post('catalog/batch', [PartnerCatalogController::class, 'batchUpsert']);
         Route::get('catalog', [PartnerCatalogController::class, 'index']); // <-- EKLE

        Route::get('orders/status', [PartnerOrderController::class, 'statusByPartnerOrderId']);
        Route::post('orders', [PartnerOrderController::class, 'storePartnerOrder']);
        Route::get('orders', [PartnerOrderController::class, 'index']);
        Route::get('orders/{id}', [PartnerOrderController::class, 'show']);
        Route::post('orders/{id}/status', [PartnerOrderController::class, 'updateStatus']);

        Route::get('products', [PartnerProductController::class, 'index']);
        Route::post('products', [PartnerProductController::class, 'store']);
        Route::get('products/{id}', [PartnerProductController::class, 'show']);

        Route::get('variants', [PartnerVariantController::class, 'index']);
        Route::get('variants/{id}', [PartnerVariantController::class, 'show']);
        Route::post('products/{productId}/variants', [PartnerVariantController::class, 'store']);

        Route::get('customers', [PartnerCustomerController::class, 'index']);
        Route::get('customers/{id}', [PartnerCustomerController::class, 'show']);

        Route::post('prices/upsert', [VariantPriceController::class, 'upsert']);
        Route::post('variants/{variant}/prices', [VariantPriceController::class, 'store']);
        Route::put('prices/{price}', [VariantPriceController::class, 'update']);
    });
});

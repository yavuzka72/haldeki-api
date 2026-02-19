<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Auth & Profile
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\BusinessController;

// Core
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductVariantController;
use App\Http\Controllers\Api\VariantPriceController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\DeliveryOrderController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\UserOnboardController;
use App\Http\Controllers\Api\UsersOnboardController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\CommonController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\CityController;
use App\Http\Controllers\Api\DistrictController;
use App\Http\Controllers\Api\WalletController;

// (Senin kodunda kullanılan ama import edilmeyenler)
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\DeliveryManDocumentController;


use App\Http\Controllers\NotificationController;
use App\Http\Controllers\CourierJobController;
use App\Http\Controllers\Api\CashReportController;

// Reports (Bayi kısıtlı)

use App\Http\Controllers\Reports\DealerOrderReport;
use App\Http\Controllers\Api\UserAddressController;
use App\Http\Controllers\Api\ProductDashboardController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| Not: /v1 altında PUBLIC ve AUTH grupları var.
| Rapor uçları auth:sanctum altında ve users.dealer_id ile scope edilir.
*/

 
Route::prefix('v1')->group(function () {

    /* =========================
     *  AUTH (Public)
     * ========================= */
 //   Route::post('/register',        [AuthController::class, 'register']);
    Route::post('/login',           [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password',  [AuthController::class, 'resetPassword']);
    Route::post('/mobilelogin',     [UserController::class, 'login']);

    /* =========================
     *  PUBLIC UÇLAR
     * ========================= */

    // Common (Maps & distance)
    Route::get('place-autocomplete-api',        [CommonController::class, 'placeAutoComplete']);
    Route::get('place-detail-api',              [CommonController::class, 'placeDetail']);
    Route::get('distance-matrix-api',           [CommonController::class, 'distanceMatrix']);
    Route::get('combined-distance-matrix-api',  [CommonController::class, 'combinedDistanceAndRoute']);

    // Katalog & Ürünler
    Route::get('/products',                          [ProductController::class, 'index']);
    Route::get('/products/{product}',                [ProductController::class, 'show']);
    Route::get('/products/{product}/variants',       [ProductController::class, 'variants']);
    Route::get('/products/{product}/prices',         [ProductController::class, 'prices']);
    Route::get('/categories',                        [CategoryController::class, 'index']);
    Route::get('/categorilist',                      [CategoryController::class, 'catlist']);

    // Lokasyon listeleri
    Route::get('/countries',     [CountryController::class, 'countries']);
    Route::get('/cities',        [CityController::class, 'cities']); // ?country_id=
    Route::get('country-list',   [CountryController::class, 'getList']);
    Route::get('country-detail', [CountryController::class, 'getDetail']);
    Route::get('city-list',      [CityController::class, 'getList']);
    Route::get('city-detail',    [CityController::class, 'getDetail']);

    // Dashboard (public read)
    Route::get('/summary',                    [DashboardController::class, 'summary']); // ?email=...
    Route::get('/recent-orders',              [DashboardController::class, 'recent']);
    Route::get('/sum-by-restaurant',          [DashboardController::class, 'summaryByRestaurant']);
    Route::get('/sum-restaurant',             [DashboardController::class, 'summaryForRestaurant']);
    Route::get('top-products-restaurant',     [DashboardController::class, 'topProductsForRestaurant']);

    // Sipariş – public create & read (senin akışına göre public bırakılmış)
    Route::post('/orders',                                 [OrderController::class, 'store']);
    Route::get ('/orders',                                 [OrderController::class, 'index']);
    Route::get ('/orders/{order}',                         [OrderController::class, 'show']);
    Route::get ('/orders/{key}/items-enriched',            [OrderController::class, 'itemsEnrichedByKey']);
    Route::post('/orders/items-enriched/by-email',         [OrderController::class, 'itemsEnrichedByEmail']);
    Route::post('/previous-orders',                        [OrderController::class, 'previousOrdersOfRestaurant']);
    Route::post('/orderdetail',                            [OrderController::class, 'orderDetailHaldeki']);
    Route::post('/orderdetail-tedarik',                    [OrderController::class, 'orderDetailTedarik']);
    Route::post('/orders/{order_number}/status',           [OrderController::class, 'setStatusByNumber']);
    Route::post('order-update-status', [OrderController::class, 'orderUpdateStatus']);
 
        Route::post('/orders/siparisduzenle', [OrderController::class, 'siparisduzenle']);
    
    // Dealer (Bayi) sipariş listeleri (publicte bırakmışsın — istersen auth’a taşıyabilirim)
    Route::post('/dealer-orders',                  [OrderController::class, 'dealer']);
    Route::post('/dealer-order-detail',            [OrderController::class, 'orderDetailDealer']);
    Route::post('/dealer-order-update-status',     [OrderController::class, 'dealerUpdateStatus']);


  Route::post('/vendor-orders',                  [OrderController::class, 'vendor']);
    Route::post('/vendor-order-detail',            [OrderController::class, 'vendorDetailDealer']);
    Route::post('/vendor-order-update-status',     [OrderController::class, 'vendorUpdateStatus']);


    // Supplier (Tedarikçi) sipariş uçları
    Route::get ('/orders/supplier',                [OrdersController::class, 'supplier']);
    Route::post('/supplier',                       [OrderController::class, 'supplierOrders']);
    Route::post('/supplier-order-update-status',   [OrderController::class, 'supplierUpdateStatus']);

    // Delivery Orders (CRUD)
    Route::get   ('/delivery-orders',                      [DeliveryOrderController::class, 'index']);
    Route::post  ('/delivery-orders',                      [DeliveryOrderController::class, 'store']);
    Route::get   ('/delivery-orders/{deliveryOrder}',      [DeliveryOrderController::class, 'show']);
    Route::match (['put','patch'],'/delivery-orders/{deliveryOrder}', [DeliveryOrderController::class, 'update']);
    Route::delete('/delivery-orders/{deliveryOrder}',      [DeliveryOrderController::class, 'destroy']);

    // Esnaf liste
    Route::get('order-list-esnaf', [DeliveryOrderController::class, 'getListEsnaf']);
        Route::post('handoff-to-couriers', [OrderController::class, 'handoffToCouriers']);

        Route::post('dealer-delivery-handoff-multiple', [DeliveryOrderController::class, 'handoffMultiple']);



  Route::get ('/order-list',    [DeliveryOrderController::class, 'getList']);     // DEPRECATED alias
    Route::get ('/order-detail',  [DeliveryOrderController::class, 'getDetail']);   // DEPRECATED alias
    Route::post('/order-save',    [DeliveryOrderController::class, 'store']);       // DEPRECATED alias
    Route::post('/order-update/{id}', [DeliveryOrderController::class, 'updateDelivery']); // DEPRECATED alias
    Route::post('/order-delete/{id}', [DeliveryOrderController::class, 'destroy']);       // DEPRECATED alias

    // Ürün — eski/özel uçlar
    Route::post('/products/by-product', [ProductController::class, 'productById']);     // DEPRECATED alias
    Route::post('/products/by-user',    [ProductController::class, 'productsByUser']);  // DEPRECATED alias
    Route::post('/user/products',       [ProductController::class, 'productsOfUser']);  // DEPRECATED alias

    // Variant’ların kullanıcıya göre listesi (mevcut /products/{product}/variants’ın türevi)
    Route::get('/products/{product}/variantsuser', [ProductController::class, 'variantsUser']); // Özel kullanım

    // (İhtiyacın varsa) ProductController içindeki addVariant için alias
    // Modern tercih: POST /v1/products/{product}/variants  -> ProductVariantController@store
    Route::post('/products/{product}/variants', [ProductController::class, 'addVariant']); // DEPRECATED alias
    
    
    // Upload & ürün ekleme
    Route::post('/upload',         [UploadController::class, 'store']);
    Route::post('/products',       [ProductController::class, 'store']);
    Route::post('/productsfull',   [ProductController::class, 'storefull']);
    Route::put ('/products/{product}', [ProductController::class, 'update']);

    // Variant & Price
    Route::post('/products/{product}/variants',    [ProductVariantController::class, 'store']);
    Route::put ('/variants/{variant}',             [ProductVariantController::class, 'update']);
    Route::post('/variants/{variant}/prices',      [VariantPriceController::class, 'store']);
    Route::put ('/prices/{price}',                 [VariantPriceController::class, 'update']);
    Route::post('/user-product-prices/upsert',     [VariantPriceController::class, 'upsert']);

    // Kullanıcı Onboarding (Kurye / İşletme)
    Route::post('/couriers',        [UserOnboardController::class,'storeCourier']);
    Route::get ('/couriers',        [UserOnboardController::class, 'index']);
    Route::get ('/couriers/{id}',   [UserOnboardController::class, 'CourierShow']);
    Route::put ('/couriers/{id}',   [UserOnboardController::class, 'CourieUpdate']);

    Route::post('/clients',         [UserOnboardController::class,'storeClient']);
    Route::get ('/clients',         [UserOnboardController::class,'index']);
    Route::get ('/clients/{id}',    [UserOnboardController::class, 'show']);
    Route::put ('/clients/{id}',    [UserOnboardController::class, 'update']);

    Route::post('/users',         [UsersOnboardController::class,'storeClient']);
    Route::get ('/users',         [UsersOnboardController::class,'index']);
    Route::get ('/users/{id}',    [UsersOnboardController::class, 'show']);
    Route::put ('/users/{id}',    [UsersOnboardController::class, 'update']);
 
    // Cart
    Route::get ('/cart',        [CartController::class, 'show']);
    Route::post('/cart/add',    [CartController::class, 'add']);
    Route::post('/cart/remove', [CartController::class, 'remove']);
    Route::post('/cart/update', [CartController::class, 'update']);

    // Wallet & Payment
    /*
    Route::post('/payment-save', [PaymentController::class, 'paymentSave']);
    Route::get ('/payment-list', [PaymentController::class, 'getList']);
    Route::post('save-wallet',   [WalletController::class, 'saveWallet']);
    Route::get ('wallet-list',   [WalletController::class, 'getList']);

*/
    Route::post('paymentgateway-save', [PaymentGatewayController::class, 'store' ] );
    
    Route::post('payment-save', [PaymentController::class, 'paymentSave' ] );
    Route::get('payment-list', [ aymentController::class, 'getList' ] );

    Route::post('save-wallet', [WalletController::class, 'saveWallet'] );
    Route::get('wallet-list', [WalletController::class, 'getList'] );
    
    
    Route::get('wallet/balance/{userId}', [App\Http\Controllers\Api\WalletController::class, 'balance']);


    Route::get('delivery-man-document-list', [DeliveryManDocumentController::class, 'index' ] );
    Route::post('multiple-delete-deliveryman-document', [DeliveryManDocumentController::class, 'multipleDeleteRecords' ] );
    Route::post('delivery-man-document-save', [DeliveryManDocumentController::class, 'store' ] );
    Route::post('delivery-man-document-delete/{id}', [DeliveryManDocumentController::class, 'destroy' ] );
    Route::post('delivery-man-document-action', [DeliveryManDocumentController::class, 'action' ] );
    
    Route::get('delivery-man-docs/{userId}', [DeliveryManDocumentController::class, 'list']);

    
    
    
    // App Setting
    Route::post('update-appsetting', [UserController::class,'updateAppSetting']);
    Route::get ('get-appsetting',    [UserController::class,'getAppSetting']);

    // Courier işleri
    Route::get ('/courier/jobs',               [DeliveryOrderController::class, 'listOpenJobs']);
    Route::post('/courier/jobs/{id}/claim',    [DeliveryOrderController::class, 'claimJob']);
    Route::post('/courier/jobs/{id}/unclaim',  [DeliveryOrderController::class, 'unclaimJob']);

    // User status & detail
    Route::post('update-user-status', [UserController::class, 'updateUserStatus']);
    Route::get ('user-detail',        [UserController::class, 'userDetail']);


 


    /* =========================
     *  AUTH (Protected)
     * ========================= */
    Route::middleware('auth:sanctum')->group(function () {



   Route::post('change-password-client', [UserController::class, 'changePasswordclient']);
   
        // Auth
        Route::post('/logout', [AuthController::class, 'logout']);

        // Profil & Me
        Route::get('/me',                [ProfileController::class, 'show']);
        Route::get('/me/businesses',     [BusinessController::class, 'myBusinesses']); // sadece bayinin işletmeleri


    Route::middleware('auth:sanctum')->group(function () {

        // 1) Kullanıcının kendi şifresi
        Route::post('/me/change-password', [ProfileController::class, 'changePassword']);

        // 2) Admin/panel: belirli bir client’ın şifresi
        Route::post('/clients/{id}/change-password', [UserOnboardController::class, 'changePassword']);
        // istersen PUT/PATCH de yapabilirsin:
        // Route::match(['put','patch'], '/clients/{id}/change-password', [UserOnboardController::class, 'changePassword']);




                // CLIENT PROFILE (alias)
                Route::get('/auth/me', [UserController::class, 'profile']);


            Route::get('/profile', [UserController::class, 'profile']);
        // Profil güncelle
            Route::post('profile/update', [UserController::class, 'updateProfile']);

            // Şifre değiştir
            Route::post('change-password', [UserController::class, 'changePassword']);

            // Belge yükleme
            Route::post('profile/documents', [UserController::class, 'uploadProfileDocument']);


              Route::get('/profile/{id}', [UserController::class, 'show']);

    });
    
    
        /*
        |--------------------------------------------------------------------------
        | R A P O R L A R  (Bayi kendi verisini görür — users.dealer_id ile scope)
        | Filtreler opsiyonel: ?business_id=&status=&start=&end=
        |--------------------------------------------------------------------------
        | Not: Kâr formülü tüm raporlarda: Bayi Kârı = Toplam × 0.15
        */
        
        Route::prefix('v1')->group(function () {

    /* =========================
     *  PUBLIC — Legacy/Özel Alias’lar
     *  (Modern karşılıkları: /delivery-orders CRUD vb.)
     * ========================= */

    // Delivery Orders — eski alias’lar (modern: GET /v1/delivery-orders)
  
      Route::post('handoff-to-couriers', [OrderController::class, 'handoffToCouriers']);

    /* =========================
     *  PROTECTED — Legacy/İşlem Ucu
     * ========================= */
   
});


      
   Route::prefix('reports')->group(function () {
            Route::get('cash',        [DealerOrderReport::class, 'cash']);       // Kasa Raporu
            Route::get('businesses',  [DealerOrderReport::class, 'business']);   // İşletme Raporu
            Route::get('couriers',    [DealerOrderReport::class, 'courier']);    // Kurye Raporu (delivery_orders)
            Route::get('orders',      [DealerOrderReport::class, 'orders']);    
            
     
             Route::get('me/businesses',      [DealerOrderReport::class, 'myBusinesses']);
        });
 
  
  
        // (İstersen hassas uçları da buraya taşıyabiliriz:
        //  dealer-orders, supplier-order-update-status vs.)
    });
// Controller’da index/show/store/update/destroy hazırsa:
Route::apiResource('useraddresses', \App\Http\Controllers\Api\UserAddressController::class);

Route::get('/test-push', [NotificationController::class, 'testPush']);
Route::get('/send-job', [CourierJobController::class, 'sendJobToCouriers']);



Route::get('/cash', [CashReportController::class, 'index']);
    Route::get('productdashboard/products', [ProductDashboardController::class, 'products']);
    Route::get('clientdashboard/clients', [ProductDashboardController::class, 'clients']);
Route::post('/register', [UserController::class, 'register']);




});

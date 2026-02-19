<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\ProductVariant;

 
 
 use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;


class PartnerOrderController extends Controller
{

public function show(Request $request, $id)
{
    $partner = $request->attributes->get('partner');
    if (!$partner) {
        return response()->json(['success' => false, 'message' => 'Partner not found'], 401);
    }

    $order = \App\Models\deliveryOrder::where('reseller_id', $partner->id)
        ->where('id', (int)$id)
        ->first();

    if (!$order) {
        return response()->json(['success' => false, 'message' => 'Order not found'], 404);
    }

    return response()->json([
        'success' => true,
        'data' => $order,
    ]);
}


 public function storePartnerOrder(Request $request)
    {
        // PartnerAuth middleware set ediyor
        $partner = $request->attributes->get('partner');

        if (!$partner) {
            return response()->json([
                'success' => false,
                'message' => 'Partner not found in request'
            ], 401);
        }

        $data = $request->validate([
            'partner_order_id' => ['required','string','max:80'],
            'dealer_id'        => ['required','integer','min:1'],

            'parcel_type'      => ['required','string','max:100'],
            'total_amount'     => ['required','numeric','min:0'],

            'name'             => ['required','string','max:191'],
            'phone'            => ['required','string','max:191'],
            'shipping_address' => ['required','string','max:500'],

            'dropoff_lat'      => ['nullable','numeric'],
            'dropoff_lng'      => ['nullable','numeric'],

            'note'             => ['nullable','string'],
            'city'             => ['nullable','string','max:191'],
            'district'         => ['nullable','string','max:191'],

            'auto_assign'      => ['nullable','boolean'],
            'courier_id'       => ['nullable','integer','min:1'],

            // SKU bazlı item listesi
            'items'            => ['required','array','min:1'],
            'items.*.sku'      => ['required','string','max:120'],
            'items.*.seller_id'=> ['required','integer','min:1'],
            'items.*.qty'      => ['required','numeric','min:0.0001'],
            'items.*.price'    => ['required','numeric','min:0'],
        ]);

        Log::debug('[PARTNER_ORDER] incoming', [
            'partner_id' => $partner->id,
            'partner_order_id' => $data['partner_order_id'],
            'dealer_id' => $data['dealer_id'],
            'items_count' => count($data['items'] ?? []),
        ]);

        // ✅ Idempotency: aynı partner + aynı partner_order_id tekrar gelirse aynı order dön
        $existing = Order::where('partner_client_id', $partner->id)
            ->where('partner_order_id', $data['partner_order_id'])
            ->first();

        if ($existing) {
            Log::debug('[PARTNER_ORDER] already_exists', [
                'order_id' => $existing->id,
                'partner_id' => $partner->id,
                'partner_order_id' => $data['partner_order_id'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Order already exists',
                'order_id'=> $existing->id,
                'partner_order_id' => $existing->partner_order_id,
            ], 200);
        }

        $courierId  = (int)($data['courier_id'] ?? 0);
        $autoAssign = array_key_exists('auto_assign', $data) ? (bool)$data['auto_assign'] : true;
        if ($courierId > 0) $autoAssign = false;

        try {
            return DB::transaction(function () use ($data, $partner, $autoAssign, $courierId) {

                Log::debug('[PARTNER_ORDER] tx_start', [
                    'partner_id' => $partner->id,
                    'partner_order_id' => $data['partner_order_id'],
                ]);

                // 1) SKU -> ProductVariant çöz (tek sorgu)
                $skus = collect($data['items'])->pluck('sku')->unique()->values()->all();

         /*       $variants = ProductVariant::where('partner_client_id', $partner->id)
                    ->whereIn('sku', $skus)
                    ->get(['id','sku','partner_client_id'])
                    ->keyBy('sku');
    */
                    $variants = ProductVariant::whereIn('sku', $skus)
    ->get(['id','sku'])   // partner_client_id artık gerekli değil
    ->keyBy('sku');



                $missing = [];
                foreach ($skus as $sku) {
                    if (!isset($variants[$sku])) $missing[] = $sku;
                }

                if (!empty($missing)) {
                    Log::warning('[PARTNER_ORDER] missing_sku', [
                        'partner_id' => $partner->id,
                        'missing' => $missing,
                    ]);

                    throw ValidationException::withMessages([
                        'items' => ['Unknown sku(s) for this partner: ' . implode(', ', $missing)]
                    ]);
                }

                // 2) Order create
                $order = new Order();

                /**
                 * ✅ KRİTİK: user_id / buyer_id eğer users FK ise partner_id yazmak patlatır.
                 * Bu yüzden güvenli şekilde NULL bırakıyoruz.
                 * (Sen zaten "users kullanılmayacak" demiştin.)
                 */
                if (in_array('user_id', $order->getFillable()) || \Schema::hasColumn('orders', 'user_id')) {
                    $order->user_id = null;
                }
                if (in_array('buyer_id', $order->getFillable()) || \Schema::hasColumn('orders', 'buyer_id')) {
                    $order->buyer_id = null;
                }

                $order->dealer_id   = (int)$data['dealer_id'];
                $order->reseller_id = 1; // kendi kuralın neyse

                $order->ad_soyad         = $data['name'];
                $order->phone            = $data['phone'];
                $order->note             = $data['note'] ?? null;
                $order->shipping_address = $data['shipping_address'];

                $order->parcel_type  = $data['parcel_type'];
                $order->total_amount = (float)$data['total_amount'];

                $order->latitude  = $data['dropoff_lat'] ?? null;
                $order->longitude = $data['dropoff_lng'] ?? null;

                $order->city     = $data['city'] ?? null;
                $order->district = $data['district'] ?? null;

                // statuslar
                $order->status          = 'pending';
                $order->supplier_status = 'delivered';
                $order->dealer_status   = 'pending';
                $order->delivery_status = 0;
                $order->payment_status  = 'pending';
                $order->user_id = $partner->id;

                // partner alanları
                $order->partner_client_id = $partner->id;
                $order->partner_order_id  = $data['partner_order_id'];
                  

                // Partner payload’ı sakla (debug için)
                if (\Schema::hasColumn('orders', 'partner_meta')) {
                    $order->partner_meta = json_encode($data, JSON_UNESCAPED_UNICODE);
                }

                Log::debug('[PARTNER_ORDER] before_order_save');
                $order->save();
                Log::debug('[PARTNER_ORDER] after_order_save', ['order_id' => $order->id]);

                // 3) Order items create
                foreach ($data['items'] as $it) {
                    $variantId = (int) $variants[$it['sku']]->id;
                    $qty   = (float)$it['qty'];
                    $price = (float)$it['price'];

                    // Eğer OrderItem fillable değilse, create patlayabilir. Bu yüzden new+save güvenli.
                    $item = new OrderItem();
                    $item->order_id           = $order->id;
                    $item->product_variant_id = $variantId;
              //      $item->supplier_id          = (int)$it['seller_id'];
                    $item->quantity           = $qty;
                    $item->unit_price         = $price;
                    $item->total_price        = $qty * $price;
                    $item->status             = 'pending';
                    $item->dealer_status      = 'pending';

                    $item->save();
                }

                Log::debug('[PARTNER_ORDER] items_saved', [
                    'order_id' => $order->id,
                    'count' => count($data['items']),
                ]);

                // 4) Importer çağır (hata olsa bile order kaydı kalsın)
                try {
                    app(\App\Services\DeliveryOrderImporter::class)
                        ->importByOrderSingle($order, [
                            'client_id'        => (int)($order->dealer_id ?? 0),
                            'auto_assign'      => $autoAssign,
                            'courier_id'       => $courierId > 0 ? $courierId : null,
                            'delivery_man_id'  => $courierId > 0 ? $courierId : null,
                            'status'           => 'active',
                            'order_id'         => $order->id,
                            'order_number'     => $order->order_number ?? null,
                            'reason'           => $order->note,

                        ]);
                } catch (\Throwable $e) {
                    Log::error('DeliveryOrder import failed (storePartnerOrder)', [
                        'order_id' => $order->id,
                        'error'    => $e->getMessage(),
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Partner order created',
                    'order_id' => $order->id,
                    'partner_order_id' => $order->partner_order_id,
                    'auto_assign' => $autoAssign,
                    'courier_id'  => $courierId ?: null,
                ], 201);
            });
        } catch (ValidationException $e) {
            // 422
            throw $e;
        } catch (\Throwable $e) {
            Log::error('[PARTNER_ORDER] failed', [
                'partner_id' => $partner->id,
                'partner_order_id' => $data['partner_order_id'] ?? null,
                'err' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Order create failed',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

public function storePartnerOrder1(Request $request)
{
    $partner = $request->attributes->get('partner'); // PartnerAuth middleware set ediyor

    if (!$partner) {
        return response()->json(['success' => false, 'message' => 'Partner not found in request'], 401);
    }

    $data = $request->validate([
        'partner_order_id' => ['required','string','max:80'],
        'dealer_id'        => ['required','integer','min:1'],

        'parcel_type'      => ['required','string','max:100'],
        'total_amount'     => ['required','numeric','min:0'],

        'name'             => ['required','string','max:191'],
        'phone'            => ['required','string','max:191'],
        'shipping_address' => ['required','string','max:500'],

        'dropoff_lat'      => ['nullable','numeric'],
        'dropoff_lng'      => ['nullable','numeric'],

        'note'             => ['nullable','string'],
        'city'             => ['nullable','string','max:191'],
        'district'         => ['nullable','string','max:191'],

        'auto_assign'      => ['nullable','boolean'],
        'courier_id'       => ['nullable','integer','min:1'],

        // ✅ SKU bazlı
        'items'            => ['required','array','min:1'],
        'items.*.sku'      => ['required','string','max:120'],
        'items.*.seller_id'=> ['required','integer','min:1'],
        'items.*.qty'      => ['required','numeric','min:0.0001'],
        'items.*.price'    => ['required','numeric','min:0'],
    ]);

    // idempotency: aynı partner + aynı partner_order_id tekrar gelirse aynı order dön
    $existing = Order::where('partner_client_id', $partner->id)
        ->where('partner_order_id', $data['partner_order_id'])
        ->first();

    if ($existing) {
        return response()->json([
            'success' => true,
            'message' => 'Order already exists',
            'order_id'=> $existing->id,
            'partner_order_id' => $existing->partner_order_id,
        ], 200);
    }

    $courierId  = (int)($data['courier_id'] ?? 0);
    $autoAssign = array_key_exists('auto_assign', $data) ? (bool)$data['auto_assign'] : true;
    if ($courierId > 0) $autoAssign = false;

    return DB::transaction(function () use ($data, $partner, $autoAssign, $courierId) {

        // 1) SKU -> ProductVariant çöz (tek sorguda toplayalım)
        $skus = collect($data['items'])->pluck('sku')->unique()->values()->all();

     /*   $variants = ProductVariant::where('partner_client_id', $partner->id)
            ->whereIn('sku', $skus)
            ->get(['id','sku','partner_client_id'])
            ->keyBy('sku');
*/
        $variants = ProductVariant::whereIn('sku', $skus)
            ->get(['id','sku'])   // partner_client_id artık gerekli değil
            ->keyBy('sku');

        // Eksik SKU var mı?
        $missing = [];
        foreach ($skus as $sku) {
            if (!isset($variants[$sku])) $missing[] = $sku;
        }
        if (!empty($missing)) {
            throw ValidationException::withMessages([
                'items' => ['Unknown sku(s) for this partner: ' . implode(', ', $missing)]
            ]);
        }

        // 2) Order create
        $order = new Order();

        // ❗ DİKKAT: user_id / buyer_id partner id olabilir mi?
        // Eğer order.user_id gerçek "users" tablosuna FK ise burada partner id yazmak yanlış olur.
        // Şimdilik senin istediğin gibi bıraktım ama gerekirse kaldırırız.
        $order->user_id  = $partner->id;
        $order->buyer_id = $partner->id;

        $order->dealer_id   =  (int)$data['dealer_id'];
        $order->reseller_id = 1; // proje kuralın neyse

        $order->ad_soyad         = $data['name'];
        $order->phone            = $data['phone'];
        $order->note             = $data['note'] ?? null;
        $order->shipping_address = $data['shipping_address'];

        $order->parcel_type  = $data['parcel_type'];

        // total_amount: istersen item’lardan da hesaplayıp doğrulayabiliriz
        $order->total_amount = (float)$data['total_amount'];

        $order->latitude  = $data['dropoff_lat'] ?? null;
        $order->longitude = $data['dropoff_lng'] ?? null;

        $order->city     = $data['city'] ?? null;
        $order->district = $data['district'] ?? null;

        $order->status          = 'pending';
        $order->supplier_status = 'delivered';
        $order->dealer_status   = 'pending';
        $order->delivery_status = 0;
        $order->payment_status  = 'pending';

        // partner alanları
        $order->partner_client_id = $partner->id;
        $order->partner_order_id  = $data['partner_order_id'];

        // Partner payload’ı saklamak istersen:
        $order->partner_meta = json_encode($data, JSON_UNESCAPED_UNICODE);

       $order->save();
 


        // 3) Order items create (SKU -> product_variant_id)
        foreach ($data['items'] as $it) {
            $variantId = (int) $variants[$it['sku']]->id;

            $qty   = (float)$it['qty'];
            $price = (float)$it['price'];

            OrderItem::create([
                'order_id'           => $order->id,
                'product_variant_id' => $variantId,     // ✅ burada artık internal id var
                'seller_id'          => (int)$it['seller_id'],
                'quantity'           => $qty,           // int değil, numeric daha doğru
                'unit_price'         => $price,
                'total_price'        => $qty * $price,
                'status'             => 'pending',
                'dealer_status'      => 'pending',
            ]);
        }

        // 4) Importer çağır
        try {
            app(\App\Services\DeliveryOrderImporter::class)
                ->importByOrderSingle($order, [
                    'client_id'        => (int)($order->dealer_id ?? 0),
                    'auto_assign'      => $autoAssign,
                    'courier_id'       => $courierId > 0 ? $courierId : null,
                    'delivery_man_id'  => $courierId > 0 ? $courierId : null,
                    'status'           => 'active',
                    'order_id'         => $order->id,
                    'order_number'     => $order->order_number,
                    'reason'           => $order->note,
                ]);
        } catch (\Throwable $e) {
            \Log::error('DeliveryOrder import failed (storePartnerOrder)', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Partner order created',
            'order_id' => $order->id,
            'partner_order_id' => $order->partner_order_id,
            'auto_assign' => $autoAssign,
            'courier_id'  => $courierId ?: null,
        ], 201);
    });
}
public function storePartnerOrder2(Request $request)
{
    $partner = $request->attributes->get('partner'); // middleware set ediyor varsayalım

    $data = $request->validate([
        'partner_order_id' => ['required','string','max:80'],
        'dealer_id'        => ['required','integer','min:1'],

        'parcel_type'      => ['required','string','max:100'],
        'total_amount'     => ['required','numeric','min:1'],

        'name'             => ['required','string','max:191'],
        'phone'            => ['required','string','max:191'],
        'shipping_address' => ['required','string','max:191'],

        'dropoff_lat'      => ['nullable','numeric'],
        'dropoff_lng'      => ['nullable','numeric'],

        'note'             => ['nullable','string'],
        'city'             => ['nullable','string','max:191'],
        'district'         => ['nullable','string','max:191'],

        'auto_assign'      => ['nullable','boolean'],
        'courier_id'       => ['nullable','integer','min:1'],

        // 🔥 BURASI ÖNEMLİ: validate’e eklemezsen payload’dan düşer
        'items'                    => ['required','array','min:1'],
        'items.*.product_variant_id'=> ['required','integer','min:1'],
        'items.*.seller_id'         => ['required','integer','min:1'],
        'items.*.qty'               => ['required','numeric','min:1'],
        'items.*.price'             => ['required','numeric','min:0'],
    ]);

    // idempotency (aynı partner + aynı partner_order_id tekrar gelirse aynı order dön)
    $existing = Order::where('partner_client_id', $partner->id)
        ->where('partner_order_id', $data['partner_order_id'])
        ->first();

    if ($existing) {
        return response()->json([
            'success' => true,
            'message' => 'Order already exists',
            'order_id'=> $existing->id,
            'partner_order_id' => $existing->partner_order_id,
        ], 200);
    }

    $courierId  = (int)($data['courier_id'] ?? 0);
    $autoAssign = array_key_exists('auto_assign', $data) ? (bool)$data['auto_assign'] : true;
    if ($courierId > 0) $autoAssign = false;

    return DB::transaction(function () use ($data, $partner, $autoAssign, $courierId) {

        // Order create
        $order = new Order();

        // ✅ SENİN İSTEĞİN: user_id = partner id (istersen)
        $order->user_id  = $partner->id;
        $order->buyer_id = $partner->id;

        $order->dealer_id   = (int)$data['dealer_id'];
        $order->reseller_id = 1; // proje kuralın neyse

        $order->ad_soyad          = $data['name'];
        $order->phone             = $data['phone'];
        $order->note              = $data['note'] ?? null;
        $order->shipping_address  = $data['shipping_address'];

        $order->parcel_type  = $data['parcel_type'];
        $order->total_amount = (float)$data['total_amount'];

        $order->latitude  = $data['dropoff_lat'] ?? null;
        $order->longitude = $data['dropoff_lng'] ?? null;

        $order->city     = $data['city'] ?? null;
        $order->district = $data['district'] ?? null;

        $order->status          = 'pending';
        $order->supplier_status = 'delivered';
        $order->dealer_status   = 'pending';
        $order->delivery_status = 0;
        $order->payment_status  = 'pending';

        // partner alanları
        $order->partner_client_id = $partner->id;
        $order->partner_order_id  = $data['partner_order_id'];

        // 🔥 Array->string hatası yememek için JSON encode et
        $order->partner_meta = json_encode($data, JSON_UNESCAPED_UNICODE);

        $order->save();

        // Order items create
        foreach ($data['items'] as $it) {
            OrderItem::create([
                'order_id'           => $order->id,
                'product_variant_id' => (int)$it['product_variant_id'],
                'seller_id'          => (int)$it['seller_id'],
                'quantity'           => (int)$it['qty'],
                'unit_price'         => (float)$it['price'],
                'total_price'        => (float)$it['qty'] * (float)$it['price'],
                'status'             => 'pending',
                'dealer_status'      => 'pending',
            ]);
        }

        // burada istersen DeliveryOrderImporter çağırırsın (storeCourierSingle gibi)
        // app(DeliveryOrderImporter::class)->importByOrderSingle(...)
    try {
            app(\App\Services\DeliveryOrderImporter::class)
                ->importByOrderSingle($order, [
                    'client_id'        => (int)($order->dealer_id ?? 0),
                    'auto_assign'      => $autoAssign,
                    'courier_id'       => $courierId > 0 ? $courierId : null,
                    'delivery_man_id'  => $courierId > 0 ? $courierId : null,
                    'status'           => 'active',
                    'order_id'         => $order->id,
                    'order_number'     => $order->order_number,
                    'reason'           => $order->note,
                ]);
        } catch (\Throwable $e) {
            \Log::error('DeliveryOrder import failed (storePartnerOrder)', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }
        return response()->json([
            'success' => true,
            'message' => 'Partner order created',
            'order_id' => $order->id,
            'partner_order_id' => $order->partner_order_id,
            'auto_assign' => $autoAssign,
            'courier_id'  => $courierId ?: null,
        ], 201);
    });
}

public function statusByPartnerOrderId(Request $request)
{
    $partner = $request->attributes->get('partner');
    if (!$partner) {
        return response()->json(['success' => false, 'message' => 'Partner not found'], 401);
    }

    $data = $request->validate([
        'partner_order_id' => ['required','string','max:80'],
    ]);

    $partnerOrderId = $data['partner_order_id'];

    $order = \App\Models\DeliveryOrder::where('reseller_id', $partner->id)
        ->where('parent_order_id', $partnerOrderId)   // ✅ burası kritik
        ->first();

    if (!$order) {
        return response()->json([
            'success' => false,
            'message' => 'Order not found',
            'partner_order_id' => $partnerOrderId,
        ], 404);
    }

    return response()->json([
        'success' => true,
        'partner_order_id' => $order->partner_order_id,
        'order_id' => $order->id,
        'status' => $order->status,
        'status_at' => optional($order->updated_at)->toISOString(),
    ]);
}


}

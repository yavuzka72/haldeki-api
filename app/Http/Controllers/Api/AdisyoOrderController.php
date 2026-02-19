<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

use App\Models\AdisyoOrder;
use App\Models\Restaurant;
use App\Jobs\FetchAdisyoOrdersJob;

use App\Models\User; // ← KURYE ADI İÇİN



class AdisyoOrderController extends Controller
{
    /**
     * GET /api/adisyo-orders
     * ?adisyoid=...&usertype=client
     */
    public function index(Request $request)
    {
        $adisyoid = $request->query('adisyoid');
        $usertype = $request->query('usertype');

        if ($usertype === 'client' && !empty($adisyoid)) {
            // MySQL JSON kolonundan filtre
            $orders = AdisyoOrder::where('order_json->restaurantKey', $adisyoid)
                ->latest()
                ->take(50)
                ->get();

            return response()->json([
                'success' => true,
                'data'    => $orders,
            ]);
        }

        $orders = AdisyoOrder::latest()->take(50)->get();

        return response()->json([
            'success' => true,
            'data'    => $orders,
        ]);
    }
 public function orderDetail(Request $request)
    {
        $orderid = $request->query('orderid');
           $adisyoid = $request->query('adisyoid');
        $usertype = $request->query('usertype');

        if ($usertype === 'client' && !empty($adisyoid)) {
   
 
            // MySQL JSON kolonundan filtre
            $orders = AdisyoOrder::where('order_json->id', $orderid)
             
                ->get();

            return response()->json([
                'success' => true,
                'data'    => $orders,
            ]);
      
        }
        $orders = AdisyoOrder::latest()->take(1)->get();

        return response()->json([
            'success' => true,
            'data'    => $orders,
        ]);
    }
    /**
     * POST /api/adisyo-orders/fetch
     * Adisyo'dan sipariÅŸleri Ã§ekmeyi kuyruÄŸa atar.
     */
    public function fetch(Request $request)
    {
        Log::info("ðŸŽ¯ FetchAdisyoOrdersJob dispatch ediliyor...");

        // TÃ¼m restoranlar iÃ§in tek job tetikleniyorsa:
        FetchAdisyoOrdersJob::dispatch();

        return response()->json([
            'success' => true,
            'message' => 'SipariÅŸ Ã§ekme iÅŸlemi kuyrukta baÅŸlatÄ±ldÄ±.',
        ]);
    }

    /**
     * POST /api/adisyo-orders/action
     * Body: { id, type: restore|forcedelete|courier_assigned|claim|unclaim, courier_id?, status?, key?, value? }
     *
     * Kurye atamasÄ± order_claim_data tablosu ile yÃ¶netilir:
     * id | order_id | courier_id | key | value | deleted_at | created_at | updated_at
     */
    public function action2(Request $request)
    {
        $data = $request->validate([
            'id'         => ['required', 'integer'],
            'type'       => ['required', Rule::in(['restore', 'forcedelete', 'courier_assigned', 'claim', 'unclaim'])],
            'courier_id' => ['nullable', 'integer'],
            'status'     => ['nullable', 'string'],
            'key'        => ['nullable', 'string'],
            'value'      => ['nullable', 'string'],
        ]);

        // Soft deletes desteÄŸi varsa withTrashed kullan
     //   $order = AdisyoOrder::withTrashed()->find($data['id']);
    $order = AdisyoOrder::where('customer_json->id',$request->id)->first();
if (!$order) {
    
       DB::table('order_claim_data')->updateOrInsert(
            ['order_id' => $request->id],
            [
                'courier_id' =>  $request->delivery_man_id,
                'key'        => 'courier_assigned',
                'value'      => 'assigned',
                'updated_at' => now(),
                'created_at' => now(), // insert’te kullanılır, update’te etkisiz
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Kurye atandı.',
        ]);
        
        
    return response()->json([
        'success' => false,
        'message' =>  $data 
    ], 404);
}

       
       
if (!$order) {
    return response()->json(['success'=>false,'message'=>'SipariÅŸ bulunamadÄ±.'],404);
}
        
        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => __('message.not_found_entry', ['name' => __('message.order')]) ?? 'SipariÅŸ bulunamadÄ±.',
            ], 404);
        }

        // 1) Restore
    
        // Fallback
        return response()->json([
            'success' => true,
            'message' => 'Ä°ÅŸlem tamamlandÄ±.',
        ]);
    }
 public function action3(Request $request)
    {
        $data = $request->validate([
            'id'               => ['required', 'integer'],
            'type'             => ['required', Rule::in(['restore','forcedelete','courier_assigned','claim','unclaim'])],
            'courier_id'       => ['nullable','integer'],
            'delivery_man_id'  => ['nullable','integer'],
            'status'           => ['nullable','string'],
            'key'              => ['nullable','string'],
            'value'            => ['nullable','string'],
            // 'courier_name' İSTEMCİDEN GELSE BİLE DB’DEN ÜSTELERİZ
        ]);

        $orderId   = (int) $data['id'];
        $courierId = isset($data['delivery_man_id'])
            ? (int) $data['delivery_man_id']
            : (isset($data['courier_id']) ? (int) $data['courier_id'] : 0);

        // courier ataması gerektiren tiplerde courierId zorunlu
        if (in_array($data['type'], ['courier_assigned','claim']) && $courierId <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Geçerli bir courier_id (veya delivery_man_id) gerekli.',
            ], 422);
        }

        // === Kurye adını users tablosundan al
        $courierName = null;
        if ($courierId > 0) {
            // Eğer kolon adınız farklıysa 'name' yerine değiştirin (örn. full_name)
            $courierName = User::query()->where('id', $courierId)->value('name');
            if (in_array($data['type'], ['courier_assigned','claim']) && !$courierName) {
                // Kurye bulunamadı → 404
                return response()->json([
                    'success' => false,
                    'message' => 'Belirtilen courier_id için kullanıcı bulunamadı.',
                ], 404);
            }
        }

        // Order'ı farklı muhtemel alanlara göre ara (JSON id, customer_json id, pk)
        $order = AdisyoOrder::where('order_json->id', $orderId)
            ->orWhere('customer_json->id', $orderId)
            ->orWhere('id', $orderId)
            ->first();

        // === Order yoksa: claim tablosu işaretle ve dön
        if (!$order) {
            // value’yu status/key’ten türet
            $claimValue = $data['value'] ?? ($data['status'] ?? 'assigned');

            // İstersen kurye adını da ayrı bir key olarak saklayabiliriz
            DB::table('order_claim_data')->updateOrInsert(
                ['order_id' => $orderId],
                [
                    'courier_id' => $courierId,
                    'key'        => $data['key']   ?? 'courier_assigned',
                    'value'      => $claimValue,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            // Opsiyonel: ayrı bir alanın yoksa, ikinci bir satırla courier_name’i not düşebilirsin
            if ($courierName) {
                DB::table('order_claim_data')->updateOrInsert(
                    ['order_id' => $orderId, 'key' => 'courier_name'],
                    [
                        'courier_id' => $courierId,
                        'value'      => $courierName,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Kurye atandı (claim tablosu). Order sistemde yoktu.',
                'courier' => ['id' => $courierId, 'name' => $courierName],
            ]);
        }

        // === Order bulundu: order_json'u güvenli şekilde array'e dönüştür
        $jsonRaw = $order->order_json ?? [];
        if ($jsonRaw instanceof \stdClass)        $json = (array) $jsonRaw;
        elseif (is_string($jsonRaw))              $json = json_decode($jsonRaw, true) ?? [];
        elseif (is_array($jsonRaw))               $json = $jsonRaw;
        else                                      $json = [];

        // Kurye bilgileri → DB’den gelen isimle YAZ
        if ($courierId > 0) {
            $json['courierId']        = $courierId;
            if ($courierName) {
                $json['deliveryUserName'] = $courierName; // ← kullanıcı adı DB’den
            }
        }

        // İsteğe bağlı alanlar
        if (!empty($data['status'])) {
            $json['status'] = $data['status'];
        }
        if (!empty($data['key'])) {
            $json[$data['key']] = $data['value'] ?? ($json[$data['key']] ?? null);
        }

        $order->order_json = $json; // cast varsa array atanabilir
        $order->save();

        return response()->json([
            'success' => true,
            'message' => 'Kurye bilgileri order_json içinde güncellendi.',
            'data'    => $json,
            'courier' => ['id' => $courierId, 'name' => $courierName],
        ]);
    }
    
    public function action(Request $request)
{
    $data = $request->validate([
        'id'               => ['required', 'integer'],
        'type'             => ['required', Rule::in(['restore','forcedelete','courier_assigned','claim','unclaim'])],
        'courier_id'       => ['nullable','integer'],
        'delivery_man_id'  => ['nullable','integer'],
        'status'           => ['nullable','string'],
        'key'              => ['nullable','string'],
        'value'            => ['nullable','string'],
    ]);

    $orderId   = (int) $data['id'];
    $courierId = isset($data['delivery_man_id'])
        ? (int) $data['delivery_man_id']
        : (isset($data['courier_id']) ? (int) $data['courier_id'] : 0);

    $claimKey   = $data['key']   ?? 'courier_assigned';
    $claimValue = $data['value'] ?? ($data['status'] ?? 'assigned');

    // courier ataması gereken tiplerde courierId zorunlu
    if (in_array($data['type'], ['courier_assigned','claim']) && $courierId <= 0) {
        return response()->json([
            'success' => false,
            'message' => 'Geçerli bir courier_id (veya delivery_man_id) gerekli.',
        ], 422);
    }

    // Kurye adını users tablosundan çek
    $courierName = $courierId > 0 ? User::where('id', $courierId)->value('name') : null;

    DB::transaction(function () use ($orderId, $courierId, $claimKey, $claimValue, $courierName) {

        // 1) order_claim_data: HER ZAMAN upsert
        // (order_id + key eşsiz olsun istersen index ekle: UNIQUE(order_id, key))
        DB::table('order_claim_data')->updateOrInsert(
            ['order_id' => $orderId, 'key' => $claimKey],
            [
                'courier_id' => $courierId,
                'value'      => $claimValue,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        // Kurye adını ayrı bir key olarak da saklamak istersen:
        if ($courierName) {
            DB::table('order_claim_data')->updateOrInsert(
                ['order_id' => $orderId, 'key' => 'courier_name'],
                [
                    'courier_id' => $courierId,
                    'value'      => $courierName,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        // 2) Order varsa order_json'u güncelle
        $order = AdisyoOrder::where('order_json->id', $orderId)
            ->orWhere('customer_json->id', $orderId)
            ->orWhere('id', $orderId)
            ->first();

        if ($order) {
            $jsonRaw = $order->order_json ?? [];
            if ($jsonRaw instanceof \stdClass)        $json = (array) $jsonRaw;
            elseif (is_string($jsonRaw))              $json = json_decode($jsonRaw, true) ?? [];
            elseif (is_array($jsonRaw))               $json = $jsonRaw;
            else                                      $json = [];

            if ($courierId > 0) {
                $json['courierId']        = $courierId;
                if ($courierName) $json['deliveryUserName'] = $courierName;
            }
            // İstersen status'ü de JSON'a yansıt:
            if (!empty($claimValue)) $json['status'] = $claimValue;

            $order->order_json = $json; // (model cast varsa array atanabilir)
            $order->save();
        }
    });

    return response()->json([
        'success' => true,
        'message' => 'Kurye ataması işlendi (claim ve order güncellendi).',
        'data'    => [
            'order_id'     => $orderId,
            'courier_id'   => $courierId,
            'courier_name' => $courierName,
            'key'          => $claimKey,
            'value'        => $claimValue,
        ],
    ]);
}
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryOrder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class OpsMapController extends Controller
{
    /**
     * Flutter: POST AppConfig.dealerOrdersPath
     * data: { email: dealerEmail }
     */
    public function dealerDeliveryOrders(Request $request)
    {
        $data = $request->validate([
            'email' => ['required','email'],
            'status' => ['nullable','string'],
            'q' => ['nullable','string'],
            'page' => ['nullable','integer'],
            'per_page' => ['nullable','integer'],
        ]);

        $dealer = User::query()
            ->where('email', $data['email'])
            ->first();

        if (!$dealer) {
            return response()->json([
                'ok' => false,
                'message' => 'Dealer bulunamadı',
            ], 404);
        }

        $perPage = (int)($data['per_page'] ?? 200);

        // ✅ Bu query mantığı:
        // - delivery_orders.reseller_id dealer id ise direkt yakala
        // - ayrıca client_id üzerinden users.dealer_id ile de yakala (sen storeClient’de dealer_id tutuyorsun)
        $q = DeliveryOrder::query()
            ->with(['delivery_man:id,name,latitude,longitude,location_lat,location_lng'])
            ->where(function($qq) use ($dealer) {
                $qq->where('reseller_id', $dealer->id)
                   ->orWhereHas('client', function($qc) use ($dealer) {
                       $qc->where('dealer_id', $dealer->id);
                   });
            });

        // opsiyonel scope'lar (modelde var) :contentReference[oaicite:1]{index=1}
        if (!empty($data['status'])) {
            $q->status($data['status']);
        }
        if (!empty($data['q'])) {
            $q->search($data['q']); // modelde JSON_EXTRACT araması var :contentReference[oaicite:2]{index=2}
        }

        $q->orderByDesc('id');

        $p = $q->paginate($perPage);

        // ✅ Debug log (isteğe bağlı)
        Log::info('[OPS dealerDeliveryOrders]', [
            'dealer_id' => $dealer->id,
            'email' => $dealer->email,
            'count' => $p->total(),
        ]);

        // Flutter _extractOrderList: orders / delivery_orders / items / results / data destekliyor :contentReference[oaicite:3]{index=3}
        return response()->json([
            'ok' => true,
            'orders' => $p->items(),
            'meta' => [
                'page' => $p->currentPage(),
                'per_page' => $p->perPage(),
                'total' => $p->total(),
                'last_page' => $p->lastPage(),
            ],
        ]);
    }

    // GET fallback (Flutter’da eski dashboard bunu dener)
    public function dealerDeliveryOrdersGet(Request $request)
    {
        $email = $request->query('email');
        if (!$email) {
            // auth user dealer varsayımı
            $email = optional(auth('sanctum')->user())->email;
        }
        $req = new Request(array_merge($request->all(), ['email' => $email]));
        return $this->dealerDeliveryOrders($req);
    }

    /**
     * Flutter: GET AppConfig.activeCouriersPath
     * opsiyonel: ?dealer_id=xx veya ?email=dealerEmail
     */
    public function liveCouriers(Request $request)
    {
        $data = $request->validate([
            'dealer_id' => ['nullable','integer'],
            'email' => ['nullable','email'],
        ]);

        $dealerId = $data['dealer_id'] ?? null;

        if (!$dealerId && !empty($data['email'])) {
            $dealer = User::query()->where('email', $data['email'])->first();
            $dealerId = $dealer?->id;
        }

        $q = User::query()
            ->select([
                'id','name','username','user_type',
                'is_active','can_take_orders',
                'dealer_id',
                'latitude','longitude','location_lat','location_lng',
                'updated_at',
            ])
            ->where('user_type', 'delivery_man')
            ->where('is_active', 1);

        // istersen sadece sipariş alabilenler
        $q->where('can_take_orders', 1);

        if ($dealerId) {
            // Kurye kayıtlarında dealer_id tutuyorsan buradan filtrele
            $q->where('dealer_id', $dealerId);
        }

        // konum olmayanları alma (haritada saçmalamasın)
        $q->where(function($qq){
            $qq->whereNotNull('latitude')->whereNotNull('longitude')
               ->orWhere(function($q2){
                   $q2->whereNotNull('location_lat')->whereNotNull('location_lng');
               });
        });

        $rows = $q->orderByDesc('updated_at')->limit(500)->get();

        // Flutter CourierLive.tryParse latitude/location_lat vb okuyor :contentReference[oaicite:4]{index=4}
        return response()->json([
            'ok' => true,
            'couriers' => $rows,
        ]);
    }

    /**
     * POST /delivery-orders/{id}/assign
     * body: { delivery_man_id: 12 }
     */
    public function assignCourier(Request $request, DeliveryOrder $deliveryOrder)
    {
        $data = $request->validate([
            'delivery_man_id' => ['required','integer',
                Rule::exists('users','id')->where(function($q){
                    $q->where('user_type','delivery_man');
                })
            ],
        ]);

        $courier = User::query()
            ->select('id','name','user_type','is_active','can_take_orders')
            ->findOrFail((int)$data['delivery_man_id']);

        if ((int)$courier->is_active !== 1 || (int)$courier->can_take_orders !== 1) {
            return response()->json([
                'ok' => false,
                'message' => 'Kurye aktif değil veya sipariş alamıyor.',
            ], 422);
        }

        $oldCourierId = $deliveryOrder->delivery_man_id;

        $deliveryOrder->delivery_man_id = $courier->id;

        // status boşsa/created ise assigned’a çek
        $s = strtolower((string) $deliveryOrder->status);
        if ($s === '' || $s === 'create' || $s === 'draft' || $s === 'pending') {
            $deliveryOrder->status = 'courier_assigned';
        }

        $deliveryOrder->save();

        Log::info('[OPS assignCourier]', [
            'delivery_order_id' => $deliveryOrder->id,
            'from_delivery_man_id' => $oldCourierId,
            'to_delivery_man_id' => $courier->id,
            'by' => optional(auth('sanctum')->user())->id,
        ]);

        return response()->json([
            'ok' => true,
            'delivery_order' => $deliveryOrder->fresh(['delivery_man:id,name']),
        ]);
    }
}

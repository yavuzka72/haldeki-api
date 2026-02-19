<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HaldekiOrder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HaldekiImportController extends Controller
{
    /**
     * POST /api/haldeki/import-one
     * Body: { "email": "...", "order_number": "ORD-XXXX", "client_id"?: 1 }
     *
     * 1) Haldeki API’den siparişi çeker
     * 2) haldeki_orders tablosuna yazar/günceller
     * 3) orders tablosuna normalize edip gömer (upsert)
     */
    public function importOne(Request $request)
    {
        $payload = $request->validate([
            'email'        => ['required','string'],
            'order_number' => ['required','string'],
            'client_id'    => ['nullable','integer'],
        ]);

        // 1) Haldeki API’yi çağır
        $apiResp = $this->fetchHaldekiOrderDetail(
            $payload['email'],
            $payload['order_number']
        );

        if (!$apiResp) {
            return response()->json([
                'success' => false,
                'message' => 'Haldeki API’den sipariş bulunamadı.',
            ], 404);
        }

        // Haldeki tarafı tek nesne döner (senin örneğine göre) — gene de dizi gelirse ilkini al
        $data  = is_array($apiResp) && Arr::isAssoc($apiResp) ? $apiResp
                : (is_array($apiResp) ? (Arr::first($apiResp) ?? []) : []);

        // 2) Cache tabloya yaz (haldeki_orders)
        $haldekiOrder = HaldekiOrder::updateOrCreate(
            ['order_number' => $payload['order_number']],
            [
                'email'           => $payload['email'],
                'user_id'         => Arr::get($data, 'user.id'),
                'created_by_name' => Arr::get($data, 'created_by_name') ?? Arr::get($data, 'user.name'),
                'status'          => Arr::get($data, 'status'),
                'payment_status'  => Arr::get($data, 'payment_status'),
                'total_amount'    => (float) Arr::get($data, 'total_amount', 0),
                'order_json'      => $data,
                'product_json'    => Arr::get($data, 'items', []),
            ]
        );

        // 3) orders tablosuna map + upsert
        $clientId = (int) ($payload['client_id'] ?? 1);
        $ordersRow = $this->mapHaldekiToOrdersRow($haldekiOrder, $clientId);

        DB::table('orders')->upsert(
            [$ordersRow],
            ['parent_order_id'],
            array_diff(array_keys($ordersRow), ['parent_order_id','created_at'])
        );

        return response()->json([
            'success' => true,
            'message' => 'Haldeki siparişi kaydedildi/yenilendi.',
            'data'    => [
                'haldeki_order' => $haldekiOrder->fresh(),
                'orders_row'    => $ordersRow,
            ],
        ]);
    }

    /* ===================== API İstek ===================== */

    private function fetchHaldekiOrderDetail(string $email, string $orderNumber): ?array
    {
        // İsteğin belirttiğin endpoint & body:
        // POST https://haldeki.com/public/api/v1/orderdetail
        // { "email": "...", "order_number": "..." }
        try {
            $resp = Http::timeout(20)->acceptJson()->post(
                'https://haldeki.com/public/api/v1/orderdetail',
                [
                    'email'        => $email,
                    'order_number' => $orderNumber,
                ]
            );

            if (!$resp->successful()) {
                Log::warning('Haldeki API non-200', [
                    'status' => $resp->status(),
                    'body'   => $resp->body(),
                ]);
                return null;
            }

            // Haldeki örneğinde kök JSON diziydi; bazen tek obje olabilir.
            $json = $resp->json();

            // Beklenen anahtarlar yoksa null dön
            if (!$json) {
                return null;
            }

            // Örnek datana göre JSON en üstte dizi. Güvenli tarafta kal:
            if (is_array($json) && !Arr::isAssoc($json)) {
                // [ {order...} ] gibi
                return $json;
            }

            // {order...} gibi tek obje
            return $json;
        } catch (\Throwable $e) {
            Log::error('Haldeki API error', ['e' => $e->getMessage()]);
            return null;
        }
    }

    /* ===================== Helpers ===================== */

    private function nullIfBlank($v): ?string
    {
        if ($v === null) return null;
        $s = is_string($v) ? trim($v) : $v;
        return ($s === '' || $s === null) ? null : (string)$s;
    }

    private function toSqlDateTime($iso): ?string
    {
        $iso = $this->nullIfBlank($iso);
        if (!$iso) return null;
        try { return Carbon::parse($iso)->format('Y-m-d H:i:s'); }
        catch (\Throwable $e) { return null; }
    }

    private function mapStatus(?string $s): string
    {
        // Haldeki: pending / processing / completed / canceled ... gibi
        // Orders tablosunun beklediği statülere çevir (senin düzene göre uyarladım)
        $map = [
            'pending'    => 'create',
            'processing' => 'active',
            'completed'  => 'completed',
            'canceled'   => 'cancelled',
            'cancelled'  => 'cancelled',
            null         => 'create',
            ''           => 'create',
        ];
        return $map[$s] ?? 'create';
    }

    private function printAddressFromHaldeki(array $j): ?string
    {
        // Haldeki örneğinde tek satır shipping_address var
        return $this->nullIfBlank(Arr::get($j, 'shipping_address'));
    }

    /**
     * Haldeki ham kaydını orders tablosuna uyumlu satıra çevirir.
     * - parent_order_id: haldeki_orders.id (stabil int)
     * - pickup_point: restoran adı ya da "Haldeki Depo"
     * - delivery_point: shipping_address + telefon
     * - reason: ürünleri JSON string (items)
     * - extra_charges: kaynak/meta
     */
    private function mapHaldekiToOrdersRow(HaldekiOrder $h, int $clientId = 1): array
    {
        $j      = $h->order_json ?? [];
        $items  = $h->product_json ?? [];
        $name   = $this->nullIfBlank($h->created_by_name) ?? 'Haldeki';
        $phone  = $this->nullIfBlank(Arr::get($j, 'phone'));

        // pickup (restoran/depo bilgisi)
        $pickup = [
            'start_time'     => null,
            'end_time'       => null,
            'address'        => $name . ' Depo',
            'latitude'       => null,
            'longitude'      => null,
            'description'    => null,
            'contact_number' => null,
        ];

        // delivery (müşteri / gönderim adresi)
        $delivery = [
            'start_time'     => null,
            'end_time'       => null,
            'name'           => $name,
            'address'        => $this->printAddressFromHaldeki($j) ?? 'Bilinmiyor',
            'city'           => null,
            'district'       => null,
            'description'    => null,
            'latitude'       => null,
            'longitude'      => null,
            'contact_name'   => $name,
            'contact_phone'  => $phone,
            'reference'      => $h->order_number,
        ];

        // extra_charges meta
        $extraCharges = [
            'source'       => 'haldeki',
            'external_id'  => $h->order_number,
            'currency'     => 'TRY',
            'products_cnt' => is_array($items) ? count($items) : 0,
            'items'        => [
                ['key' => 'fixed_charges',        'value' => 0,  'value_type' => null],
                ['key' => 'per_distance_charges', 'value' => 0,  'value_type' => null],
                ['key' => 'per_weight_charges',   'value' => 0,  'value_type' => null],
            ],
        ];

        // Eğer Haldeki kullanıcısı sistemimizde eşleşiyorsa (isteğe bağlı)
        $courierId  = null;
        $courierFcm = null;
        if ($uid = Arr::get($j, 'user.id')) {
            $u = User::find($uid);
            if ($u) $courierFcm = $u->fcm_token ?? null;
        }

        return [
            'parent_order_id'                 => (int) $h->id, // stabil int
            'client_id'                       => $clientId,
         //   'client_name'                     => $name,
            'date'                            => $this->toSqlDateTime(Arr::get($j, 'created_at')) ?? now()->format('Y-m-d H:i:s'),

            'pickup_point'                    => json_encode($pickup, JSON_UNESCAPED_UNICODE),
            'delivery_point'                  => json_encode($delivery, JSON_UNESCAPED_UNICODE),

            'country_id'                      => null,
            'city_id'                         => null,
            'parcel_type'                     => 'DİĞER',
            'total_weight'                    => 1,
            'total_distance'                  => 0,

            'pickup_datetime'                 => null,
            'delivery_datetime'               => null,

            'payment_id'                      => null,
         //   'payment_type'                    => null,
           // 'payment_status'                  => $h->payment_status,
            'payment_collect_from'            => 'on_delivery',

            'delivery_man_id'                 => $courierId,
          //  'delivery_man_name'               => null,
            'deliveryman_fcm_token'           => $courierFcm,

            'fixed_charges'                   => 0,
            'weight_charge'                   => 0,
            'distance_charge'                 => 0,
            'extra_charges'                   => json_encode($extraCharges, JSON_UNESCAPED_UNICODE),

            // ÜRÜN LİSTESİ reason alanında JSON string olarak
            'reason'                          => !empty($items) ? json_encode($items, JSON_UNESCAPED_UNICODE) : null,

            'total_amount'                    => (float) ($h->total_amount ?? 0),
            'total_parcel'                    => 1,

            'status'                          => $this->mapStatus($h->status),

            'pickup_confirm_by_client'        => 0,
            'pickup_confirm_by_delivery_man'  => 0,

            'vehicle_id'                      => 3,
            'vehicle_data'                    => null,
            'auto_assign'                     => 0,
            'cancelled_delivery_man_ids'      => null,

            'order_photo'                     => null,
            'pick_photo'                      => null,
            'delivery_photo'                  => null,
            'customer_fcm_token'              => null,

            'created_at'                      => $this->toSqlDateTime(Arr::get($j, 'created_at')) ?? now(),
            'updated_at'                      => $this->toSqlDateTime(Arr::get($j, 'updated_at')) ?? now(),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\AdisyoOrder;
use App\Models\User;

class AdisyoImportController extends Controller
{
    /**
     * POST /api/adisyo-orders/import-one
     * Body: { ad_order_id: 313780401, client_id?: 1 }
     */
    public function importOne(Request $request)
    {
        $data = $request->validate([
            'ad_order_id' => ['required','integer'],
            'client_id'   => ['nullable','integer'],
        ]);

        $src = AdisyoOrder::where('order_json->id', (int)$data['ad_order_id'])->first();
        if (!$src) {
            return response()->json(['success'=>false, 'message'=>'Adisyo siparişi bulunamadı.'], 404);
        }

        $row = $this->mapAdisyoToOrdersRow($src, (int)($data['client_id'] ?? 1));

        DB::table('orders')->upsert(
            [$row],
            ['parent_order_id'],
            array_diff(array_keys($row), ['parent_order_id','created_at'])
        );

        return response()->json(['success'=>true, 'message'=>'Sipariş aktarıldı/yenilendi.', 'data'=>$row]);
    }

    /**
     * POST /api/adisyo-orders/import-latest
     * Body: { limit?: 50, client_id?: 1 }
     */
    public function importLatest(Request $request)
    {
        $limit    = (int) ($request->input('limit', 50));
        $clientId = (int) ($request->input('client_id', 1));

        $srcList = AdisyoOrder::latest()->take($limit)->get();
        if ($srcList->isEmpty()) {
            return response()->json(['success'=>true, 'message'=>'Aktarılacak kayıt yok.']);
        }

        $rows = [];
        foreach ($srcList as $src) {
            $rows[] = $this->mapAdisyoToOrdersRow($src, $clientId);
        }

        DB::table('orders')->upsert(
            $rows,
            ['parent_order_id'],
            array_diff(array_keys($rows[0]), ['parent_order_id','created_at'])
        );

        return response()->json(['success'=>true, 'message'=>count($rows).' kayıt aktarıldı/yenilendi.']);
    }

    /* ===================== Helpers ===================== */

    private function toArr($raw): array
    {
        if (is_array($raw)) return $raw;
        if ($raw instanceof \stdClass) return (array) $raw;
        if (is_string($raw)) {
            $d = json_decode($raw, true);
            return is_array($d) ? $d : [];
        }
        return [];
    }

    /** "" / " " / "\n" gibi boşlukları null yapar */
    private function nullIfBlank($v): ?string
    {
        if ($v === null) return null;
        $s = is_string($v) ? trim($v) : $v;
        if ($s === '' || $s === null) return null;
        return (string)$s;
    }

    private function toInt($v): ?int
    {
        if ($v === null) return null;
        if (is_int($v)) return $v;
        if (is_numeric($v)) return (int)$v;
        return null;
    }

    private function toFloatOrNull($v): ?float
    {
        if ($v === null) return null;
        if (is_float($v)) return $v;
        if (is_numeric($v)) return (float)$v;
        return null;
    }

    private function joinNonEmpty(array $parts, string $sep = ', '): ?string
    {
        $p = array_values(array_filter(array_map(function($v){
            $v = $this->nullIfBlank($v);
            return $v === null ? null : $v;
        }, $parts)));
        return empty($p) ? null : implode($sep, $p);
    }

    /** deliveryAddress blokundan okunabilir adres üretir. */
    private function buildAddressFromDeliveryAddress(array $da): ?string
    {
        return $this->joinNonEmpty([
            $da['address'] ?? null,
            $da['description'] ?? null,
            $da['district'] ?? null,
            $da['city'] ?? null,
        ]);
    }

    private function buildFullName(?string $name, ?string $surname): ?string
    {
        $name = $this->nullIfBlank($name);
        $surname = $this->nullIfBlank($surname);
        return $this->joinNonEmpty([$name, $surname], ' ');
    }

    private function mapAdisyoStatus(?string $s): string
    {
        $map = [
            'courier_assigned' => 'create',
            'Sipariş Alındı'   => 'create',
            'Hazırlanıyor'     => 'create',
            'Yolda'            => 'on_delivery',
            'Teslim Edildi'    => 'delivered',
            'İptal'            => 'canceled',
            null               => 'pending',
            ''                 => 'pending',
        ];
        return $map[$s] ?? 'pending';
    }

    private function toSqlDateTime($iso): ?string
    {
        $iso = $this->nullIfBlank($iso);
        if (!$iso) return null;
        try { return Carbon::parse($iso)->format('Y-m-d H:i:s'); }
        catch (\Throwable $e) { return null; }
    }

    /** Pickup JSON’u boşsa restoran bilgisinden dolu bir pickup üretir */
    private function buildPickupFallback(array $j): array
    {
        $name = $this->nullIfBlank($j['integrationRestaurantName'] ?? null)
            ?? $this->nullIfBlank($j['restaurantName'] ?? null)
            ?? ($this->nullIfBlank($j['restaurantKey'] ?? null) ? ('Adisyo Restoran #'.$j['restaurantKey']) : null);

        $addr = $name ?: 'Bilinmiyor';
        return [
            'start_time'     => null,
            'end_time'       => null,
            'address'        => $addr,
            'latitude'       => null,
            'longitude'      => null,
            'description'    => $this->nullIfBlank($j['orderNote'] ?? null),
            'contact_number' => null,
        ];
    }

    /** Delivery JSON’u boşsa müşteri/kanal alanlarından oluşturur */
    private function buildDeliveryFallback(array $j): array
    {
        $cust = $this->toArr($j['customer'] ?? []);
        $name = $this->buildFullName($cust['customerName'] ?? null, $cust['customerSurname'] ?? null);
        $addr = $this->joinNonEmpty([
            $cust['address'] ?? null,
            $cust['addressDescription'] ?? null,
            $cust['region'] ?? null,
            $cust['city'] ?? null,
        ]);
        return [
            'start_time'      => null,
            'end_time'        => null,
            'name'            => $name ?: null,
            'address'         => $addr ?: 'Bilinmiyor',
            'city'            => $this->nullIfBlank($cust['city'] ?? null),
            'district'        => $this->nullIfBlank($cust['region'] ?? null),
            'description'     => $this->nullIfBlank($cust['addressDescription'] ?? null),
            'latitude'        => null,
            'longitude'       => null,
            'contact_name'    => $name ?: null,
            'contact_phone'   => $this->nullIfBlank($cust['customerPhone'] ?? ($cust['customerPhone2'] ?? null)),
            'reference'       => $this->nullIfBlank($j['addressId'] ?? null),
        ];
    }

    /**
     * Adisyo sipariş satırını orders tablosuna uyumlu hale getirir.
     * - pickup_point: order_json.pickup_point DOLU değilse fallback oluşturulur
     * - delivery_point: order_json.customer_json.deliveryAddress yoksa fallback oluşturulur
     * - reason: ürün listesi JSON (order_json.products -> products_json kolon fallback)
     * - extra_charges: meta + items[5]
     */
    private function mapAdisyoToOrdersRow(AdisyoOrder $src, int $clientId = 1): array
    {
        $j = $this->toArr($src->order_json);

        try {
            // ---- Adisyo JSON parçaları ----
            $custJson    = $this->toArr($j['customer_json'] ?? []);      // varsa
            $pickupJson0 = $this->toArr($j['pickup_point'] ?? []);       // varsa

            // ÜRÜNLER: önce order_json.products, yoksa AdisyoOrder.products_json
            $productsFromJson = $this->toArr($j['products'] ?? []);
            $productsFromCol  = $this->toArr($src->products_json ?? []);
            $products         = !empty($productsFromJson) ? $productsFromJson : $productsFromCol;

            // ---------- PICKUP ----------
            $pickup = [
                'start_time'     => $this->nullIfBlank($pickupJson0['start_time'] ?? null),
                'end_time'       => $this->nullIfBlank($pickupJson0['end_time'] ?? null),
                'address'        => $this->nullIfBlank($pickupJson0['address'] ?? null),
                'latitude'       => $this->toFloatOrNull($pickupJson0['latitude'] ?? null),
                'longitude'      => $this->toFloatOrNull($pickupJson0['longitude'] ?? null),
                'description'    => $this->nullIfBlank($pickupJson0['description'] ?? null),
                'contact_number' => $this->nullIfBlank($pickupJson0['contact_number'] ?? null),
            ];
            // Eğer pickup tamamen boşsa fallback
            $allPickupEmpty = !array_filter($pickup, fn($v) => $v !== null);
            if ($allPickupEmpty) {
                $pickup = $this->buildPickupFallback($j);
            }

            // ---------- DELIVERY ----------
            $deliveryAdr = $this->toArr($custJson['deliveryAddress'] ?? []);
            $location    = $this->toArr($custJson['location'] ?? []);

            $customerName  = $this->nullIfBlank($custJson['name'] ?? null);
            $customerPhone = $this->nullIfBlank($custJson['clientPhoneNumber'] ?? ($custJson['contactPhoneNumber'] ?? null));

            $delivery = [
                'start_time'      => null,
                'end_time'        => null,
                'name'            => $customerName ?: null,
                'address'         => $this->buildAddressFromDeliveryAddress($deliveryAdr),
                'address_raw'     => $deliveryAdr ?: null,
                'city'            => $this->nullIfBlank($deliveryAdr['city'] ?? null),
                'district'        => $this->nullIfBlank($deliveryAdr['district'] ?? null),
                'description'     => $this->nullIfBlank($deliveryAdr['description'] ?? null),
                'latitude'        => $this->toFloatOrNull($location['lat'] ?? null),
                'longitude'       => $this->toFloatOrNull($location['lon'] ?? null),
                'contact_name'    => $customerName ?: null,
                'contact_phone'   => $customerPhone,
                'reference'       => $this->nullIfBlank($deliveryAdr['id'] ?? ($j['addressId'] ?? null)),
            ];
            // Delivery adresi de boşsa fallback
            $isDeliveryEmpty = $this->nullIfBlank($delivery['address']) === null
                               && $delivery['latitude'] === null
                               && $delivery['longitude'] === null;
            if ($isDeliveryEmpty) {
                $delivery = $this->buildDeliveryFallback($j);
            }

            // ---------- EXTRA CHARGES ----------
            $extraCharges = [
                'source'       => 'adisyo',
                'external_id'  => $j['id'] ?? null,
                'currency'     => $j['currency'] ?? null,            // "TRY"
                'payment_name' => $j['paymentMethodName'] ?? null,   // "Nakit"
                'products_cnt' => count($products),
                'items' => [
                    ['key' => 'fixed_charges',        'value' => 45, 'value_type' => null],
                    ['key' => 'min_distance',         'value' => 1,  'value_type' => null],
                    ['key' => 'min_weight',           'value' => 1,  'value_type' => null],
                    ['key' => 'per_distance_charges', 'value' => 15, 'value_type' => null],
                    ['key' => 'per_weight_charges',   'value' => 10, 'value_type' => null],
                ],
            ];

            // ---------- Kurye ----------
            $courierId  = $this->toInt($j['courierId'] ?? null);
            $courier    = $courierId ? User::find($courierId) : null;
            $courierFcm = $courier->fcm_token ?? null;

            // ---------- Satır ----------
            $row = [
                'parent_order_id'                 => $this->toInt($j['id'] ?? null) ?? (int)($src->id ?? 0),
                'client_id'                       => $clientId,

                'pickup_point'                    => json_encode($pickup, JSON_UNESCAPED_UNICODE),
                'delivery_point'                  => json_encode($delivery, JSON_UNESCAPED_UNICODE),

                'country_id'                      => null,
                'city_id'                         => null,

                'parcel_type'                     => 'DİĞER',
                'total_weight'                    => 1,
                'total_distance'                  => 0,

                'date'                            => $this->toSqlDateTime($j['insertDate'] ?? null),
                'pickup_datetime'                 => null,
                'delivery_datetime'               => null,

                'payment_id'                      => null,

                // ÜRÜNLERİ reason alanına JSON olarak yaz
                'reason'                          => !empty($products) ? json_encode($products, JSON_UNESCAPED_UNICODE) : null,

                'status'                          => $this->mapAdisyoStatus($j['status'] ?? null),
                'payment_collect_from'            => (($j['paymentMethodName'] ?? '') === 'Nakit') ? 'on_delivery' : 'on_pickup',

                'delivery_man_id'                 => $courierId,
                'deliveryman_fcm_token'           => $courierFcm,

                'fixed_charges'                   => 0,
                'weight_charge'                   => 0,
                'distance_charge'                 => 0,
                'extra_charges'                   => json_encode($extraCharges, JSON_UNESCAPED_UNICODE),

                'total_amount'                    => (float)($j['orderTotal'] ?? 0),
                'pickup_confirm_by_client'        => 0,
                'pickup_confirm_by_delivery_man'  => 0,
                'total_parcel'                    => 1,

                'vehicle_id'                      => 3,
                'vehicle_data'                    => null,
                'auto_assign'                     => 0,
                'cancelled_delivery_man_ids'      => null,

                'order_photo'                     => null,
                'pick_photo'                      => null,
                'delivery_photo'                  => null,
                'customer_fcm_token'              => null,

                'created_at'                      => $this->toSqlDateTime($j['insertDate'] ?? null) ?? now(),
                'updated_at'                      => $this->toSqlDateTime($j['updateDate'] ?? null) ?? now(),
            ];

            return $row;
        } catch (\Throwable $e) {
            Log::error('AdisyoImport map error', [
                'adisyo_row_id' => $src->id ?? null,
                'err' => $e->getMessage(),
            ]);

            // Fallback: minimum alanlarla valid bir array döndür
            return [
                'parent_order_id'                 => (int)($j['id'] ?? ($src->id ?? 0)),
                'client_id'                       => $clientId,
                'pickup_point'                    => json_encode(['address' => 'Bilinmiyor'], JSON_UNESCAPED_UNICODE),
                'delivery_point'                  => json_encode(['address' => 'Bilinmiyor'], JSON_UNESCAPED_UNICODE),
                'status'                          => 'pending',
                'payment_collect_from'            => 'on_pickup',
                'total_amount'                    => (float)($j['orderTotal'] ?? 0),
                'created_at'                      => now(),
                'updated_at'                      => now(),
            ];
        }
    }
}

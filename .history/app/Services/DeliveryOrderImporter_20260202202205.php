<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PartnerClient;
use App\Models\DeliveryOrder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use App\Services\Push\OneSignalService;
use App\Jobs\PushOrderToEsnafExpressJob;



class DeliveryOrderImporter
{
    public function importByOrder22(Order $o, array $overrides = []): DeliveryOrder
    {
        $row = $this->mapOrderToDeliveryRow($o, (int)($overrides['client_id'] ?? 0));

  if (array_key_exists('country_id', $overrides)) $row['country_id'] = (int) $overrides['country_id'];
    if (array_key_exists('city_id',    $overrides)) $row['city_id']    = (int) $overrides['city_id'];



        if (!empty($overrides['payment_collect_from'])) $row['payment_collect_from'] = $overrides['payment_collect_from'];
        if (!empty($overrides['vehicle_id']))           $row['vehicle_id']           = (int)$overrides['vehicle_id'];
        if (array_key_exists('auto_assign', $overrides))$row['auto_assign']          = (bool)$overrides['auto_assign'];

        return DB::transaction(function () use ($row) {
            return DeliveryOrder::updateOrCreate(
                ['parent_order_id' => $row['parent_order_id']],
                Arr::except($row, ['parent_order_id'])
            );
        });
    }
    
       public function importByOrderorjin(Order $o, array $overrides = []): DeliveryOrder
    {
        $row = $this->mapOrderToDeliveryRow($o, (int)($overrides['client_id'] ?? 0));

        // 1) Order sahibini al (eager-loaded ise direkt, değilse minimal select ile çek)
        $owner = $o->relationLoaded('user')
            ? $o->user
            : $o->user()->select('id','country_id','city_id')->first();

        // 2) country_id / city_id öncelik sırası:
        //    (a) override içinde geldiyse -> onu kullan
        //    (b) yoksa users tablosundaki değerleri kullan
        //    (c) hiçbiri yoksa null bırak
        if (array_key_exists('country_id', $overrides)) {
            $row['country_id'] = $overrides['country_id'] !== null ? (int)$overrides['country_id'] : null;
        } else {
            $row['country_id'] = $owner?->country_id; // null kalabilir
        }

        if (array_key_exists('city_id', $overrides)) {
            $row['city_id'] = $overrides['city_id'] !== null ? (int)$overrides['city_id'] : null;
        } else {
            $row['city_id'] = $owner?->city_id; // null kalabilir
        }

        // Diğer override'lar (mevcut kodun)
        if (!empty($overrides['payment_collect_from'])) $row['payment_collect_from'] = $overrides['payment_collect_from'];
        if (!empty($overrides['vehicle_id']))           $row['vehicle_id']           = (int)$overrides['vehicle_id'];
        if (array_key_exists('auto_assign', $overrides))$row['auto_assign']          = (bool)$overrides['auto_assign'];

        return DB::transaction(function () use ($row) {
            return DeliveryOrder::updateOrCreate(
                ['parent_order_id' => $row['parent_order_id']],
                Arr::except($row, ['parent_order_id'])
            );
        });
    }



 public function importByOrder(Order $o, array $overrides = []): DeliveryOrder
{
    $row = $this->mapOrderToDeliveryRow($o, (int)($overrides['client_id'] ?? 0));

    $owner = $o->relationLoaded('user')
        ? $o->user
        : $o->user()->select('id','country_id','city_id')->first();

    $row['country_id'] = array_key_exists('country_id',$overrides) ? ($overrides['country_id'] !== null ? (int)$overrides['country_id'] : null) : $owner?->country_id;
    $row['city_id']    = array_key_exists('city_id',$overrides)    ? ($overrides['city_id']    !== null ? (int)$overrides['city_id']    : null) : $owner?->city_id;

    if (!empty($overrides['payment_collect_from'])) $row['payment_collect_from'] = $overrides['payment_collect_from'];
    if (!empty($overrides['vehicle_id']))           $row['vehicle_id']           = (int)$overrides['vehicle_id'];
    if (array_key_exists('auto_assign',$overrides)) $row['auto_assign']          = (bool)$overrides['auto_assign'];


         PushOrderToEsnafExpressJob::dispatch($o->id);
    // 1) Delivery’yi kaydet
    $delivery = DB::transaction(function () use ($row) {
        return DeliveryOrder::updateOrCreate(
            ['parent_order_id' => $row['parent_order_id']],
            Arr::except($row, ['parent_order_id'])
        );
    });

           /** 🚀 2.b) EsnafExpress’e sipariş gönder */
      


    // 2) Commit sonrası: client_id (dealer) kuryelerine push
    DB::afterCommit(function () use ($delivery) {
        if (!$delivery->client_id) return;

        $title   = 'Yeni Teslimat';
        $message = "Dealer {$delivery->client_id} için yeni teslimat #{$delivery->id}";
        $data    = [
            'delivery_id' => (int)$delivery->id,
            'deeplink'    => "haldeki://delivery/{$delivery->id}",
            'dealer_id'   => (int)$delivery->client_id,
        ];

        /** @var OneSignalService $push */
        $push = app(OneSignalService::class);
        $push->sendToDealerCouriers(
            dealerId: (int)$delivery->client_id, // client_id = dealer_id
            title:    $title,
            message:  $message,
            data:     $data
        );

 
    });

    return $delivery;
}
    private function mapOrderToDeliveryRow(Order $o, int $clientId = 0): array
    {
        $buyer = $o->relationLoaded('buyer') ? $o->buyer : ($o->buyer ?? null);
        $dealer= $o->relationLoaded('dealer')? $o->dealer: ($o->dealer ?? null);

        $buyerName  = optional($buyer)->name ?: 'Müşteri';
        $buyerPhone = $o->phone ?: optional($buyer)->phone;
        $buyerAddr  = $o->shipping_address ?: (optional($buyer)->address ?? '—');

        $dealerName = optional($dealer)->name ?: 'Depo';
        $dealerPhone= optional($dealer)->phone;
        $dealerAddr = optional($dealer)->address ?: ($dealerName.' Adres');
        
        $latitude  = optional($dealer)->latitude;
        $longitude  = optional($dealer)->longitude;
        
           
        $buyerlatitude  =  $o->latitude; // $ooptional($buyer)->latitude;
        $buyerlongitude  = $o->longitude; // optional($buyer)->longitude;
          /*
        $pickup = [
            'name'          => $dealerName,
            'address'       => $dealerAddr,
            'city'          => optional($dealer)->city,
            'district'      => optional($dealer)->district,
            'contact_name'  => $dealerName,
            'contact_phone' => $dealerPhone,
            'reference'     => $o->order_number,
            'latitude'=>  $latitude,
            'longitude'=>  $longitude,
        ];
*/

            $pc = $o->relationLoaded('partnerClient') ? $o->partnerClient : null;

            $pickupName  = $pc?->name ;
            $pickupAddr  = $pc?->address ;
            $pickupPhone = $pc?->phone ;

            $pickupLat   = $pc?->latitude ;
            $pickupLong  = $pc?->longitude;
            $pickup = [
                'name'          => $pickupName,
                'address'       => $pickupAddr,
                'city'          => $pc?->city ,
                'district'      => $pc?->district ,
                'contact_name'  => $pickupName,
                'contact_phone' => $pickupPhone,
                'reference'     => $o->order_number,
                'latitude'      => $pickupLat,
                'longitude'     => $pickupLong,
            ];

        $delivery = [
            'name'          => $buyerName,
            'address'       => $buyerAddr,
            'city'          => optional($buyer)->city,
            'district'      => optional($buyer)->district,
            'contact_name'  => $buyerName,
            'contact_phone' => $buyerPhone,
            'reference'     => $o->order_number,
            'latitude'=> $o->latitude, // $buyerlatitude,
            'longitude'=>  $o->longitude //$buyerlongitude,
        ];

        $items = [];
        foreach ($o->items ?? [] as $it) {
            $items[] = [
                'product_name'       => optional(optional($it->productVariant)->product)->name,
                'variant_name'       => optional($it->productVariant)->name,
                'product_variant_id' => $it->product_variant_id,
                'seller_id'          => $it->seller_id,
                'quantity'           => (int) $it->quantity,
                'unit_price'         => (float)($it->unit_price ?? 0),
                'total_price'        => (float)($it->total_price ?? ($it->quantity * ($it->unit_price ?? 0))),
            ];
        }

        $extra = [
            'source'      => 'orders',
            'order_id'    => (int)$o->id,
            'order_no'    => $o->order_number,
            'items_count' => count($items),
            'currency'    => 'TRY',
        ];

        $resolvedClientId = $clientId ?: (int)($o->dealer_id ?: $o->user_id);

        /*
        return [
            'parent_order_id'                => (int)$o->id,
            'client_id'                      => $resolvedClientId,
            'pickup_point'                   => json_encode($pickup,   JSON_UNESCAPED_UNICODE),
            'delivery_point'                 => json_encode($delivery, JSON_UNESCAPED_UNICODE),
            'parcel_type'                    => 'DİĞER',
            'total_weight'                   => 1,
            'total_distance'                 => 0,
            'date'                           => $o->created_at ?? now(),
            'payment_collect_from'           => 'on_delivery',
            'extra_charges'                  => json_encode($extra, JSON_UNESCAPED_UNICODE),
            'reason'                         => !empty($items) ? json_encode($items, JSON_UNESCAPED_UNICODE) : null,
            'total_amount'                   => (float)($o->total_amount ?? 0),
            'total_parcel'                   => 1,
            'status'                         => $this->mapOrderStatusToDelivery($o->status),
            'vehicle_id'                     => 3,
            'auto_assign'                    => 0,
            'created_at'                     => $o->created_at ?? now(),
            'updated_at'                     => now(),
        ];
        */

         return [
        'parent_order_id'        => (int)$o->id,
        'client_id'              => $resolvedClientId,
        'pickup_point'           => json_encode($pickup,   JSON_UNESCAPED_UNICODE),
        'delivery_point'         => json_encode($delivery, JSON_UNESCAPED_UNICODE),
        'parcel_type'            => 'DİĞER',
        'total_weight'           => 1,
        'total_distance'         => 0,
        'date'                   => $o->created_at ?? now(),
        'payment_collect_from'   => 'on_delivery',
        'extra_charges'          => json_encode($extra, JSON_UNESCAPED_UNICODE),
        'reason'                 => !empty($items) ? json_encode($items, JSON_UNESCAPED_UNICODE) : null,
        'total_amount'           => (float)($o->total_amount ?? 0),
        'total_parcel'           => 1,
        'status'                 =>  $this->mapOrderStatusToDelivery($o->status),
        'vehicle_id'             => 3,
        'auto_assign'            => 0,

        // 🔽 EKLEDİK: order_number'ı burada saklayacağız
        'customer_fcm_token'     => (string) $o->order_number,

        'created_at'             => $o->created_at ?? now(),
        'updated_at'             => now(),
    ];
    }

    private function mapOrderStatusToDelivery(?string $s): string
    {
        $s = strtolower((string)$s);
        return match ($s) {
            'pending','create','draft' => 'create',
            'confirmed','active','away','shipped','courier_assigned','courier_picked_up','courier_departed' => 'create',
            'completed','delivered' => 'completed',
            'cancelled','canceled'  => 'cancelled',
            default                  => 'create',
        };
    }
}

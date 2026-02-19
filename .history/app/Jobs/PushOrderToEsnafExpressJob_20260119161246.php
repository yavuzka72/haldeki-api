<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use RuntimeException;
use Throwable;

class PushOrderToEsnafExpressJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 8;
    public array $backoff = [10, 30, 60, 120, 300];

    public function __construct(public int $orderId) {}

    public function handle(): void
    {
        // Doc: BASE URL: https://api.esnafexpres.com/api  +  /partner/v1/orders
        $baseUrl = rtrim((string) config('services.esnafexpress.base_url', 'https://api.esnafexpres.com/api'), '/');
        $url = $baseUrl . '/partner/v1/orders';

        $dealerId = (int) config('services.esnafexpress.dealer_id', 24);

        // 🔥 customer ilişkisine hiç dokunma
        $order = Order::query()
            ->with(['items']) // Order modelindeki items() ilişkisi
            ->findOrFail($this->orderId);

        // ---- Order alanlarını güvenli şekilde çek ----
        $name  = $order->name  ?? $order->customer_name ?? $order->receiver_name ?? 'Müşteri';
        $phone = $order->phone ?? $order->customer_phone ?? $order->receiver_phone ?? '0000000000';

        $shippingAddress =
            $order->shipping_address
            ?? $order->address
            ?? $order->delivery_address
            ?? '';

        $city     = $order->city     ?? $order->delivery_city     ?? null;
        $district = $order->district ?? $order->delivery_district ?? null;

        $dropoffLat = $order->dropoff_lat ?? $order->latitude ?? $order->lat ?? null;
        $dropoffLng = $order->dropoff_lng ?? $order->longitude ?? $order->lng ?? null;

        $note = $order->note ?? $order->order_note ?? null;

        $totalAmount =
            $order->total_amount
            ?? $order->total
            ?? $order->grand_total
            ?? 0;

        // ---- Items mapping ----
        $items = [];

        foreach ($order->items as $it) {
            // Bu alanları senin OrderItem kolonlarına göre gerekirse değiştiririz
            $productCode = $it->product_code ?? $it->external_product_id ?? $it->product_external_id ?? null;
            $sku         = $it->sku ?? $it->variant_sku ?? null;

            $sellerId = $it->seller_id ?? $it->supplier_id ?? $it->store_id ?? 0;

            $qty   = (int) ($it->qty ?? $it->quantity ?? 1);
            $price = (float) ($it->price ?? $it->unit_price ?? 0);

            if (!$productCode || !$sku) {
                Log::warning('EsnafExpress push: item mapping missing', [
                    'order_id' => $order->id,
                    'item_id'  => $it->id ?? null,
                    'product_code' => $productCode,
                    'sku' => $sku,
                ]);
                continue;
            }

            $items[] = [
                'product_code' => (string) $productCode,
                'sku'          => (string) $sku,
                'seller_id'    => (int) $sellerId,
                'qty'          => $qty,
                'price'        => $price,
            ];
        }

        if (count($items) === 0) {
            throw new RuntimeException("EsnafExpress push: items boş. Order#{$order->id}");
        }

        $payload = [
            'partner_order_id' => (string) $order->id,               // ✅ istediğin gibi
            'dealer_id'        => $dealerId,                         // ✅ 24
            'parcel_type'      => (string) ($order->parcel_type ?? 'Yemek'),
            'total_amount'     => (float) $totalAmount,

            'name'             => (string) $name,
            'phone'            => (string) $phone,
            'shipping_address' => (string) $shippingAddress,

            'dropoff_lat'      => $dropoffLat !== null ? (float) $dropoffLat : null,
            'dropoff_lng'      => $dropoffLng !== null ? (float) $dropoffLng : null,

            'note'             => $note ? (string) $note : null,
            'city'             => $city ? (string) $city : null,
            'district'         => $district ? (string) $district : null,

            'auto_assign'      => true,
            'courier_id'       => null,

            'items'            => $items,
        ];

        // null değerleri payload’dan temizle (EsnafExpress tarafı null sevmezse)
        $payload = Arr::where($payload, fn($v) => $v !== null);

        $headers = [
            "X-Partner-Key"    => config("services.esnafexpress.partner_key", "p_s1qt1rhb2axrawxot4d8zn59"),
            "X-Partner-Secret" => config("services.esnafexpress.partner_secret", "BQGHos4WOY536Z8oGG6KFkSkH5C56TnEcB9GVq9YMm9ZDdJa"),
            "Authorization"    => "Bearer " . config("services.esnafexpress.token", "b92c6dd02526305db474f98036953e2eb13a1c09a89794d5770285e40517cd0b"),
            "Accept"           => "application/json",
        ];

        $res = Http::timeout(20)
            ->withHeaders($headers)
            ->post($url, $payload);

        if (!$res->successful()) {
            Log::error('EsnafExpress order push failed', [
                'order_id' => $order->id,
                'status'   => $res->status(),
                'body'     => $res->body(),
                'payload'  => $payload,
                'url'      => $url,
            ]);

            $res->throw(); // retry tetikler
        }

        Log::info('EsnafExpress order push success', [
            'order_id' => $order->id,
            'response' => $res->json(),
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('EsnafExpress push job failed permanently', [
            'order_id' => $this->orderId,
            'error'    => $e->getMessage(),
        ]);
    }
}

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
use Throwable;

class PushOrderToEsnafExpressJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 8;
    public array $backoff = [10, 30, 60, 120, 300];

    public function __construct(public int $orderId) {}

    public function handle(): void
    {
        $baseUrl = rtrim((string) config('services.esnafexpress.base_url'), '/');
        $dealerId = (int) config('services.esnafexpress.dealer_id', 24);

        // 🔥 Burada "customer" ilişkisine hiç bulaşmıyoruz (senin hatanı yapan yer burasıydı)
        // items varsayımı: Order modelinde items() ilişkisi var (OrderItem)
        $order = Order::query()
            ->with(['items']) // eğer product/variant ilişkilerin varsa birazdan ekleriz
            ->findOrFail($this->orderId);

        // ---- Order alanlarını güvenli şekilde çek (kolon adların farklıysa fallback çalışır) ----
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

        // ---- Items mapping (dokümana göre) ----
        // Beklenen: product_code (external_product_id) + sku + seller_id + qty + price
        $items = [];
        foreach (($order->items ?? []) as $it) {
            // Sende kolon isimleri farklı olabilir:
            $productCode = $it->product_code ?? $it->external_product_id ?? $it->product_external_id ?? null;
            $sku         = $it->sku ?? $it->variant_sku ?? null;

            $sellerId = $it->seller_id ?? $it->supplier_id ?? $it->store_id ?? 0;

            $qty   = (int) ($it->qty ?? $it->quantity ?? 1);
            $price = (float) ($it->price ?? $it->unit_price ?? 0);

            if (!$productCode || !$sku) {
                // Eksik mapping varsa job fail olmasın diye log + atla (istersen fail ettiririz)
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

        $payload = [
            'partner_order_id'  => (string) $order->id,   // ✅ senin istediğin
            'dealer_id'         => $dealerId,             // ✅ 24
            'parcel_type'       => (string) ($order->parcel_type ?? 'Yemek'),
            'total_amount'      => (float) $totalAmount,

            'name'              => (string) $name,
            'phone'             => (string) $phone,
            'shipping_address'  => (string) $shippingAddress,

            'dropoff_lat'       => $dropoffLat !== null ? (float) $dropoffLat : null,
            'dropoff_lng'       => $dropoffLng !== null ? (float) $dropoffLng : null,

            'note'              => $note ? (string) $note : null,
            'city'              => $city ? (string) $city : null,
            'district'          => $district ? (string) $district : null,

            'auto_assign'       => true,
            'courier_id'        => null,

            'items'             => $items,
        ];

        // boş item gönderme (422 yer)
        if (count($items) === 0) {
            throw new \RuntimeException("EsnafExpress push: items boş. Order#{$order->id}");
        }

        $headers = [
            "X-Partner-Key"    => (string) config("services.esnafexpress.partner_key"),
            "X-Partner-Secret" => (string) config("services.esnafexpress.partner_secret"),
            "Authorization"    => "Bearer " . (string) config("services.esnafexpress.token"),
            "Content-Type"     => "application/json",
            "Accept"           => "application/json",
        ];

        $url = $baseUrl . '/partner/v1/orders';

        $res = Http::timeout(25)
            ->withHeaders($headers)
            ->post($url, $payload);

        if (!$res->successful()) {
            Log::error('EsnafExpress order push failed', [
                'order_id' => $order->id,
                'status'   => $res->status(),
                'body'     => $res->body(),
                'payload'  => $payload,
            ]);

            // Queue retry mekanizması devreye girsin
            $res->throw();
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

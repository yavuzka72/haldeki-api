<?php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PushOrderToEsnafExpressJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public $tries = 8;          // retry
    public $backoff = [10, 30, 60, 120, 300];

    public function __construct(public int $orderId) {}

    public function handle(): void
    {
        $order = \App\Models\Order::with(['items.variant', 'customer', 'address'])->findOrFail($this->orderId);

        $payload = [
            "partner_order_id" => (string) ($order->order_number ?? $order->id),
            "dealer_id"        => (int) $order->dealer_id, // map gerekebilir
            "parcel_type"      => "Market",
            "total_amount"     => (float) $order->total_amount,

            "name"             => (string) $order->customer_name,
            "phone"            => (string) $order->customer_phone,
            "shipping_address" => (string) $order->shipping_address,

            "dropoff_lat"      => $order->dropoff_lat ? (float) $order->dropoff_lat : null,
            "dropoff_lng"      => $order->dropoff_lng ? (float) $order->dropoff_lng : null,

            "note"             => (string) ($order->note ?? ""),
            "city"             => (string) ($order->city ?? ""),
            "district"         => (string) ($order->district ?? ""),

            "auto_assign"      => true,
            "courier_id"       => null,

            "items" => $order->items->map(function ($it) {
                return [
                    "product_code" => (string) ($it->product_code ?? $it->variant?->product_code ?? ""),
                    "sku"          => (string) ($it->variant?->sku ?? $it->sku ?? ""),
                    "seller_id"    => (int) ($it->seller_id ?? 0),
                    "qty"          => (int) $it->qty,
                    "price"        => (float) $it->price,
                ];
            })->values()->all(),
        ];

        $r = Http::timeout(20)
            ->withHeaders([
                "X-Partner-Key"    => config("services.esnafexpress.partner_key"),
                "X-Partner-Secret" => config("services.esnafexpress.partner_secret"),
                "Authorization"    => "Bearer " . config("services.esnafexpress.token"),
                "Accept"           => "application/json",
            ])
            ->post(rtrim(config("services.esnafexpress.base_url"), "/") . "/api/partner/v1/orders", $payload);

        if (!$r->successful()) {
            Log::warning("EsnafExpress order push failed", [
                "order_id" => $order->id,
                "status"   => $r->status(),
                "body"     => $r->body(),
            ]);
            $r->throw(); // retry tetikler
        }

        // Başarılı cevap örneği dokümanda 201 + success true :contentReference[oaicite:5]{index=5}
    }
}

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
        $baseUrl  = rtrim((string) config('services.esnafexpress.base_url'), '/');
        $dealerId = (int) config('services.esnafexpress.dealer_id', 24);

        $url = $baseUrl . '/partner/v1/orders';

        $order = Order::query()
            ->with([
                'items.productVariant.product', // <-- product_code + sku buradan gelecek
                'user:id,country_id,city_id,name,phone', // varsa (opsiyonel)
            ])
            ->findOrFail($this->orderId);

        // --------- Order alanları (senin orders dump’ına göre) ----------
        $partnerOrderId = (string) ($order->order_number ?? $order->id);  // istersen sadece id yaparız

        $name  = (string) ($order->receiver_name ?? $order->name ?? optional($order->user)->name ?? 'Müşteri');
        $phone = (string) ($order->receiver_phone ?? $order->phone ?? optional($order->user)->phone ?? '0000000000');

        $shippingAddress = (string) ($order->shipping_address ?? '');

        $dropoffLat = $order->dropoff_lat ?? $order->latitude ?? $order->lat ?? null;
        $dropoffLng = $order->dropoff_lng ?? $order->longitude ?? $order->lng ?? null;

        $note = $order->note ?? null;

        $totalAmount = (float) ($order->total_amount ?? 0);

        // city/district sende yok görünüyor, address’ten ayıklamak istersen sonra ekleriz
        $city = $order->city ?? null;
        $district = $order->district ?? null;

        // --------- Items mapping (order_items dump’ına göre) ----------
        $items = [];
        foreach ($order->items as $it) {
            // order_items: quantity, unit_price, product_variant_id, seller_id, supplier_id var
            $qty   = (int) ($it->quantity ?? 0);
            $price = (float) ($it->unit_price ?? 0);

            $variant = $it->productVariant;
            $product = $variant?->product;

            // EsnafExpress beklediği:
            // product_code => products.external_product_id (Catalog batch ile giden)
            // sku          => product_variants.sku
            $productCode = $product?->external_product_id ?? $product?->product_code ?? null;
            $sku         = $variant?->sku ?? null;

            // seller_id: item’da yoksa order’dan fallback, o da yoksa 0
            $sellerId = (int) (
                $it->seller_id
                ?? $it->supplier_id
                ?? $order->seller_id
                ?? 0
            );

            if (!$productCode || !$sku) {
                Log::warning('EsnafExpress push: item mapping missing product_code/sku', [
                    'order_id' => $order->id,
                    'item_id'  => $it->id ?? null,
                    'product_variant_id' => $it->product_variant_id ?? null,
                    'product_code' => $productCode,
                    'sku' => $sku,
                ]);
                continue;
            }

            if ($qty <= 0) {
                Log::warning('EsnafExpress push: qty invalid', [
                    'order_id' => $order->id,
                    'item_id' => $it->id ?? null,
                    'qty' => $qty,
                ]);
                continue;
            }

            $items[] = [
                'product_code' => (string) $productCode,
                'sku'          => (string) $sku,
                'seller_id'    => $sellerId,
                'qty'          => $qty,
                'price'        => $price,
            ];
        }

        if (count($items) === 0) {
            throw new \RuntimeException("EsnafExpress push: items boş. Order#{$order->id} (mapping sonrası)");
        }

        $payload = [
            'partner_order_id' => $partnerOrderId,
            'dealer_id'        => $dealerId,
            'parcel_type'      => (string) ($order->parcel_type ?? 'Yemek'),
            'total_amount'     => $totalAmount,

            'name'             => $name,
            'phone'            => $phone,
            'shipping_address' => $shippingAddress,

            'dropoff_lat'      => $dropoffLat !== null ? (float) $dropoffLat : null,
            'dropoff_lng'      => $dropoffLng !== null ? (float) $dropoffLng : null,

            'note'             => $note ? (string) $note : null,
            'city'             => $city ? (string) $city : null,
            'district'         => $district ? (string) $district : null,

            'auto_assign'      => true,
            'courier_id'       => null,

            'items'            => $items,
        ];

    $headers = [ "X-Partner-Key" =>'p_s1qt1rhb2axrawxot4d8zn59', 
    "X-Partner-Secret" => 'BQGHos4WOY536Z8oGG6KFkSkH5C56TnEcB9GVq9YMm9ZDdJa',
     "Authorization" => "Bearer b92c6dd02526305db474f98036953e2eb13a1c09a89794d5770285e40517cd0b" , 
     "Content-Type" => "application/json", "Accept" => "application/json", ];

        $res = Http::timeout(30)
            ->withHeaders($headers)
            ->post($url, $payload);

        if (!$res->successful()) {
            Log::error('EsnafExpress order push failed', [
                'order_id' => $order->id,
                'status'   => $res->status(),
                'body'     => $res->body(),
                'payload'  => $payload,
            ]);
            $res->throw();
        }

        Log::info('EsnafExpress order push success', [
            'order_id'  => $order->id,
            'response'  => $res->json(),
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

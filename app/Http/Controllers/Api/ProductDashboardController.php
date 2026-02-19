<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductDashboardController extends Controller
{
    /**
     * PRODUCT DASHBOARD
     * - Top kartlar (toplam ürün, gelir, adet)
     * - Ürün bazlı satırlar (variant + satış istatistiği)
     */
  public function products()
    {
        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'order_items.product_variant_id')
            ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
            ->selectRaw('
                product_variants.id       AS variant_id,
                products.id               AS product_id,
                COALESCE(products.name, "")          AS product_name,
                COALESCE(product_variants.name, "")  AS variant_name,
                ""                         AS sku,
                AVG(order_items.unit_price)          AS avg_price,
                SUM(order_items.quantity)           AS total_qty,
                SUM(order_items.total_price)        AS total_revenue,
                0                                   AS stock,
                "in_stock"                          AS stock_status,
                MAX(orders.created_at)              AS last_order_at
            ')
            ->groupBy(
                'product_variants.id',
                'products.id',
                'products.name',
                'product_variants.name'
            )
            ->orderByDesc('total_revenue')
            ->limit(100)
            ->get();

        // Şu an Flutter tarafı zaten toplamlari kendi hesaplıyor,
        // o yüzden sadece listeyi döndürüyorum.
        return response()->json($rows);
    }
    /**
     * CLIENT DASHBOARD
     * - Top kartlar (toplam müşteri, toplam sipariş, ciro)
     * - Müşteri bazlı tablo
     */
public function clients2(Request $request)
{
    // Multi-tenant: Eğer siparişler "dealer_id" bazlı bağlıysa
    $dealerId = optional($request->user())->id;

    // ---- Orders + Users base ----
    $base = DB::table('orders')
        ->join('users as buyers', 'buyers.id', '=', 'orders.buyer_id')
        ->when($dealerId, fn ($q) => $q->where('orders.dealer_id', $dealerId));
        // NOT: orders.deleted_at yok → kaldırıldı

    // ---- Summary ----
    $totalClients = (clone $base)
        ->distinct('buyers.id')
        ->count('buyers.id');

    $totalRevenue = (clone $base)
        ->sum('orders.total_amount');

    $totalOrders = (clone $base)
        ->count('orders.id');

    // ---- Müşteri bazlı liste ----
    $clients = (clone $base)
        ->selectRaw('
            buyers.id                                        as client_id,
            COALESCE(buyers.name, buyers.name, "") as client_name,
            buyers.email                                    as email,
            buyers.phone                                    as phone,
            COUNT(DISTINCT orders.id)                       as order_count,
            SUM(orders.total_amount)                        as revenue,
            MIN(orders.created_at)                          as first_order_at,
            MAX(orders.created_at)                          as last_order_at
        ')
        ->groupBy(
            'buyers.id',
            'buyers.name',
            'buyers.name',
            'buyers.email',
            'buyers.phone'
        )
        ->orderByDesc('revenue')
        ->limit(100)
        ->get()
        ->map(function ($row) {
            return [
                'client_id'      => (int) $row->client_id,
                'client_name'    => $row->client_name,
                'email'          => $row->email,
                'phone'          => $row->phone,
                'order_count'    => (int) $row->order_count,
                'revenue'        => (float) $row->revenue,
                'first_order_at' => $row->first_order_at,
                'last_order_at'  => $row->last_order_at,
            ];
        })
        ->values();

    return response()->json([
        'summary' => [
            'total_clients' => (int) $totalClients,
            'total_revenue' => (float) $totalRevenue,
            'total_orders'  => (int) $totalOrders,
        ],
        'clients' => $clients,
    ]);
}
public function clients22(Request $request)
{
    $dealerId = optional($request->user())->id;

    $base = DB::table('orders')
        ->join('users as buyers', 'buyers.id', '=', 'orders.buyer_id')
        ->when($dealerId, fn ($q) => $q->where('orders.dealer_id', $dealerId));

    $totalClients = (clone $base)
        ->distinct('buyers.id')
        ->count('buyers.id');

    $totalRevenue = (clone $base)
        ->sum('orders.total_amount');

    $totalOrders = (clone $base)
        ->count('orders.id');

    $clients = (clone $base)
        ->selectRaw('
            buyers.id                         as client_id,
            COALESCE(buyers.name, buyers.name, "") as client_name,
            buyers.email                      as email,
            buyers.phone                      as phone,
            COUNT(DISTINCT orders.id)         as order_count,
            SUM(orders.total_amount)          as revenue,
            MIN(orders.created_at)            as first_order_at,
            MAX(orders.created_at)            as last_order_at
        ')
        ->groupBy(
            'buyers.id',
            'buyers.name',
            'buyers.name',
            'buyers.email',
            'buyers.phone'
        )
        ->orderByDesc('revenue')
        ->limit(100)
        ->get()
        ->map(function ($row) {
            return [
                'client_id'      => (int) $row->client_id,
                'client_name'    => $row->client_name,
                'email'          => $row->email,
                'phone'          => $row->phone,
                'order_count'    => (int) $row->order_count,
                'revenue'        => (float) $row->revenue,
                'first_order_at' => $row->first_order_at,
                'last_order_at'  => $row->last_order_at,
            ];
        })
        ->values();

    return response()->json([
        'summary' => [
            'total_clients' => (int) $totalClients,
            'total_revenue' => (float) $totalRevenue,
            'total_orders'  => (int) $totalOrders,
        ],
        'clients' => $clients,
    ]);
}
public function clientsTeadrik(Request $request)
{
    $dealerId = optional($request->user())->id;

    $baseItems = DB::table('order_items')
        ->join('orders', 'orders.id', '=', 'order_items.order_id')
        ->leftJoin('product_variants', 'product_variants.id', '=', 'order_items.product_variant_id')
        ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
        //->where('orders.buyer_id', '24')
        ->when($dealerId, fn ($q) => $q->where('orders.dealer_id', $dealerId));

    // Toplam ciro / miktar bu müşteri için
    $totalRevenue = (clone $baseItems)->sum('order_items.total_price');
    $totalQty     = (clone $baseItems)->sum('order_items.quantity');

    $products = (clone $baseItems)
        ->selectRaw('
            product_variants.id          as variant_id,
            products.id                  as product_id,
            COALESCE(products.name, "")  as product_name,
            COALESCE(product_variants.name, "") as variant_name,
            SUM(order_items.quantity)    as total_qty,
            SUM(order_items.total_price) as total_revenue,
            MAX(orders.created_at)       as last_order_at
        ')
        ->groupBy(
            'product_variants.id',
            'products.id',
            'products.name',
            'product_variants.name'
        )
        ->orderByDesc('total_revenue')
        ->get()
        ->map(function ($row) {
            $qty  = (float) $row->total_qty;
            $rev  = (float) $row->total_revenue;
            $avg  = $qty > 0 ? round($rev / $qty, 2) : null;

            return [
                  'isletme'    =>   $row->name,
                'product_id'    => (int) $row->product_id,
                'variant_id'    => (int) $row->variant_id,
                'product_name'  => $row->product_name,
                'variant_name'  => $row->variant_name,
                'total_qty'     => (int) $row->total_qty,
                'total_revenue' => $rev,
                'avg_price'     => $avg,
                'last_order_at' => $row->last_order_at,
            ];
        })
        ->values();

    return response()->json([
        'summary' => [
            'client_id'     =>'87',
            'total_qty'     => (int) $totalQty,
            'total_revenue' => (float) $totalRevenue,
        ],
        'products' => $products,
    ]);
}
public function clients(Request $request)
{
    $dealerId = optional($request->user())->id;

    $baseItems = DB::table('order_items')
        ->join('orders', 'orders.id', '=', 'order_items.order_id')
        ->join('users as users', 'users.id', '=', 'orders.user_id') // 👈 müşteri (işletme)
        ->leftJoin('product_variants', 'product_variants.id', '=', 'order_items.product_variant_id')
        ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
        ->when($dealerId, fn ($q) => $q->where('orders.dealer_id', $dealerId));

    // --- toplamlar ---
    $totalRevenue = (clone $baseItems)->sum('order_items.total_price');
    $totalQty     = (clone $baseItems)->sum('order_items.quantity');

    // --- ürünler + işletme bilgisi ---
    $products = (clone $baseItems)
        ->selectRaw('
            users.id                    as client_id,
            users.name                  as client_name,
            product_variants.id          as variant_id,
            products.id                  as product_id,
            COALESCE(products.name, "")  as product_name,
            COALESCE(product_variants.name, "") as variant_name,
            SUM(order_items.quantity)    as total_qty,
            SUM(order_items.total_price) as total_revenue,
            MAX(orders.created_at)       as last_order_at
        ')
        ->groupBy(
            'users.id',
            'users.name',
            'product_variants.id',
            'products.id',
            'products.name',
            'product_variants.name'
        )
        ->orderByDesc('total_revenue')
        ->get()
        ->map(function ($row) {
            $qty = (float) $row->total_qty;
            $rev = (float) $row->total_revenue;

            return [
                'client_id'     => (int) $row->client_id,
                'client_name'   => $row->client_name,
                'product_id'    => (int) $row->product_id,
                'variant_id'    => (int) $row->variant_id,
                'product_name'  => $row->product_name,
                'variant_name'  => $row->variant_name,
                'total_qty'     => (int) $row->total_qty,
                'total_revenue' => $rev,
                'avg_price'     => $qty > 0 ? round($rev / $qty, 2) : null,
                'last_order_at' => $row->last_order_at,
            ];
        })
        ->values();

    return response()->json([
        'summary' => [
            'client_count'   => $products->pluck('client_id')->unique()->count(),
            'total_qty'      => (int) $totalQty,
            'total_revenue'  => (float) $totalRevenue,
        ],
        'products' => $products,
    ]);
}

}

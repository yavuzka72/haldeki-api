<?php
// app/Http/Controllers/Api/DashboardController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class DashboardController extends Controller
{
    
    
public function topProductsForRestaurant(Request $request)
{
    $disk = config('filesystems.media_disk', 'public');

    $data = $request->validate([
        'email'   => 'required_without:user_id|email',
        'user_id' => 'required_without:email|integer|exists:users,id',
        'status'  => 'sometimes|string|in:pending,confirmed,cancelled,all',
        'limit'   => 'sometimes|integer|min:1|max:100',
    ]);

    $userId = $data['user_id'] ?? DB::table('users')->where('email', $data['email'])->value('id');
    if (!$userId) {
        return response()->json(['status' => false, 'message' => 'User not found'], 404);
    }

    $limit  = (int)($data['limit'] ?? 10);
    $status = strtolower((string)($data['status'] ?? 'all'));

    // Bu ifadeyi STRING olarak tut
    $lineTotalSql = 'COALESCE(oi.total_price, oi.unit_price * oi.quantity)';

    $q = DB::table('order_items as oi')
        ->join('orders as o', 'o.id', '=', 'oi.order_id')
        ->leftJoin('product_variants as pv', 'pv.id', '=', 'oi.product_variant_id')
        ->leftJoin('products as p', 'p.id', '=', 'pv.product_id')
        ->where('o.user_id', $userId);

    if (in_array($status, ['pending', 'confirmed', 'cancelled'], true)) {
        $q->where('o.status', $status);
    }

    $rows = $q->groupBy('p.id', 'p.name', 'p.image')
        ->selectRaw("
            p.id                                    as product_id,
            COALESCE(p.name, '—')                   as product_name,
            p.image                                 as image_path,
            SUM(oi.quantity)                        as total_qty,
            COUNT(DISTINCT oi.order_id)             as order_count,
            SUM($lineTotalSql)                      as total_amount,
            MAX(o.created_at)                       as last_order_at
        ")
        ->orderByDesc('total_qty')
        ->orderByDesc('total_amount')
        ->limit($limit)
        ->get();

    $list = $rows->map(function ($r) use ($disk) {
        $imageUrl = null;
        if (!empty($r->image_path)) {
            try { $imageUrl = \Storage::disk($disk)->url($r->image_path); }
            catch (\Throwable) { $imageUrl = url('storage/' . ltrim($r->image_path, '/')); }
        }
        return [
            'product_id'   => (int) $r->product_id,
            'product_name' => (string) $r->product_name,
            'image_url'    => $imageUrl,
            'total_qty'    => (int) $r->total_qty,
            'order_count'  => (int) $r->order_count,
            'total_amount' => (float) $r->total_amount,
            'last_order_at'=> $r->last_order_at,
        ];
    });

    return response()->json([
        'status' => true,
        'data'   => [
            'user_id' => $userId,
            'status'  => $status,
            'data'    => $list,
        ],
    ]);
}

public function summaryForRestaurant(Request $request)
{
    $data = $request->validate([
        'email'   => 'required_without:user_id|email',
        'user_id' => 'required_without:email|integer|exists:users,id',
        'status'  => 'sometimes|string|in:pending,confirmed,cancelled',
    ]);

    // Kullanıcıyı bul
    $userId = $data['user_id'] ?? DB::table('users')->where('email', $data['email'])->value('id');
    if (!$userId) {
        return response()->json(['status' => false, 'message' => 'User not found'], 404);
    }

    // Baz sorgu (tarih filtresi YOK)
    $base = DB::table('orders as o')->where('o.user_id', $userId);

    // Genel toplam (tüm statüler)
    $overall = (clone $base)->selectRaw("
        COUNT(*)                        AS order_count,
        SUM(COALESCE(o.total_amount,0)) AS total_amount
    ")->first();

    // Statü kırılımı (tüm statüler)
    $byStatusRow = (clone $base)->selectRaw("
        SUM(CASE WHEN o.status='pending'   THEN 1 ELSE 0 END)                   AS pending_count,
        SUM(CASE WHEN o.status='pending'   THEN COALESCE(o.total_amount,0) END) AS pending_amount,

        SUM(CASE WHEN o.status='confirmed' THEN 1 ELSE 0 END)                   AS confirmed_count,
        SUM(CASE WHEN o.status='confirmed' THEN COALESCE(o.total_amount,0) END) AS confirmed_amount,

        SUM(CASE WHEN o.status='cancelled' THEN 1 ELSE 0 END)                   AS cancelled_count,
        SUM(CASE WHEN o.status='cancelled' THEN COALESCE(o.total_amount,0) END) AS cancelled_amount
    ")->first();

    $byStatus = [
        'pending' => [
            'order_count'  => (int)($byStatusRow->pending_count ?? 0),
            'total_amount' => (float)($byStatusRow->pending_amount ?? 0),
        ],
        'confirmed' => [
            'order_count'  => (int)($byStatusRow->confirmed_count ?? 0),
            'total_amount' => (float)($byStatusRow->confirmed_amount ?? 0),
        ],
        'cancelled' => [
            'order_count'  => (int)($byStatusRow->cancelled_count ?? 0),
            'total_amount' => (float)($byStatusRow->cancelled_amount ?? 0),
        ],
    ];

    // ---- TREND: STATÜ BAZLI (günlük değil) ----
    // İstersen sadece tek bir statü için özet istenebilir (status param verildiyse)
    $trendQuery = clone $base;
    if (!empty($data['status'])) {
        $trendQuery->where('o.status', $data['status']);
    }

    // Statü bazlı toplamlar
    $statusAgg = $trendQuery->selectRaw("
            o.status,
            COUNT(*)                        AS order_count,
            SUM(COALESCE(o.total_amount,0)) AS total_amount
        ")
        ->groupBy('o.status')
        ->get();

    // Paylar için toplamlar (seçime göre – status varsa tek statü de olabilir)
    $sumOrders = (int) $statusAgg->sum('order_count');
    $sumAmount = (float) $statusAgg->sum('total_amount');

    // Frontend için uygun dizi (pie/donut/bars)
    $trend = $statusAgg->map(function ($r) use ($sumOrders, $sumAmount) {
        $oc = (int)   ($r->order_count ?? 0);
        $ta = (float) ($r->total_amount ?? 0.0);
        return [
            'status'       => (string) $r->status,
            'order_count'  => $oc,
            'total_amount' => $ta,
            'share_orders' => $sumOrders > 0 ? round($oc / $sumOrders, 4) : 0.0,
            'share_amount' => $sumAmount > 0 ? round($ta / $sumAmount, 4) : 0.0,
        ];
    })->values();

    // Geriye uyumluluk: “üstte gösterilecek” rakamlar (status verilmişse filtreden, yoksa overall)
    $topOrderCount  = !empty($data['status']) ? (int) $trend->sum('order_count')  : (int) ($overall->order_count ?? 0);
    $topTotalAmount = !empty($data['status']) ? (float)$trend->sum('total_amount') : (float)($overall->total_amount ?? 0);

    return response()->json([
        'status' => true,
        'data' => [
            'user_id'      => (int) $userId,

            // Üst kartlar için
            'order_count'  => $topOrderCount,
            'total_amount' => $topTotalAmount,

            // Statü bazlı “trend” (artık gün değil)
            'trend'        => $trend, // [{status, order_count, total_amount, share_*}]

            // Ayrıntılı statü özetleri
            'overall'      => [
                'order_count'  => (int)($overall->order_count ?? 0),
                'total_amount' => (float)($overall->total_amount ?? 0),
            ],
            'by_status'    => $byStatus,

            // Hangi filtre ile bakıldığı bilgisi
            'current_filter' => [
                'status'       => $data['status'] ?? 'all',
            ],
        ],
    ]);
}

public function summaryByRestaurant(Request $request)
{
    $data = $request->validate([
        'q'           => 'sometimes|string',      // isim/email/telefon araması
        'status'      => 'sometimes|string|in:pending,confirmed,cancelled',
        'date_from'   => 'sometimes|date',
        'date_to'     => 'sometimes|date',
        'reseller_id' => 'sometimes|integer',
        'only_guest'  => 'sometimes|boolean',     // default: true (sadece restoran sip.)
        'per_page'    => 'sometimes|integer|min:1|max:200',
        'sort'        => 'sometimes|string',
        'dir'         => 'sometimes|in:asc,desc',
    ]);

    $perPage = (int)($data['per_page'] ?? 50);
    $sort    = $data['sort'] ?? 'total_amount';
    $dir     = strtolower($data['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

    $allowedSort = [
        'total_amount','order_count','pending_count','confirmed_count','cancelled_count','last_order_at','restaurant_name'
    ];
    if (!in_array($sort, $allowedSort, true)) $sort = 'total_amount';

    // Ortak filtre
    $applyFilters = function ($q) use ($data) {
        if (!empty($data['status'])) {
            $q->where('o.status', $data['status']);
        }
        if (!empty($data['reseller_id'])) {
            $q->where('o.reseller_id', (int)$data['reseller_id']);
        }
        $onlyGuest = array_key_exists('only_guest', $data) ? (bool)$data['only_guest'] : true;
        if ($onlyGuest) {
            $q->where('o.is_guest_order', 1);
        }
        if (!empty($data['date_from'])) {
            $q->whereDate('o.created_at', '>=', $data['date_from']);
        }
        if (!empty($data['date_to'])) {
            $q->whereDate('o.created_at', '<=', $data['date_to']);
        }
        if (!empty($data['q'])) {
            $term = '%'.$data['q'].'%';
            $q->where(function ($w) use ($term) {
                $w->where('u.name', 'like', $term)
                  ->orWhere('u.email', 'like', $term)
                  ->orWhere('u.phone', 'like', $term)
                  ->orWhere('o.shipping_address', 'like', $term);
            });
        }
    };

    // Grup sorgusu: restoran (= user) bazında
    $q = DB::table('orders as o')
        ->join('users as u', 'u.id', '=', 'o.user_id');

    $applyFilters($q);

    $q->selectRaw("
        u.id                                as restaurant_user_id,
        COALESCE(u.name,  '—')              as restaurant_name,
        COALESCE(u.email, '—')              as restaurant_email,
        COALESCE(u.phone, '—')              as phone,
        MAX(o.shipping_address)             as address,
        COUNT(o.id)                         as order_count,
        SUM(COALESCE(o.total_amount,0))     as total_amount,
        SUM(CASE WHEN o.status='pending'   THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN o.status='confirmed' THEN 1 ELSE 0 END) as confirmed_count,
        SUM(CASE WHEN o.status='cancelled' THEN 1 ELSE 0 END) as cancelled_count,
        MAX(o.created_at)                   as last_order_at
    ")
    ->groupBy('u.id','u.name','u.email','u.phone');

    // Sıralama
    $q->orderBy($sort, $dir);

    $p = $q->paginate($perPage);

    // Genel (overall) toplamlar – aynı filtrelerle ama GRUPSIZ
    $overallQ = DB::table('orders as o')
        ->join('users as u','u.id','=','o.user_id');
    $applyFilters($overallQ);

    $overall = $overallQ->selectRaw("
        COUNT(o.id)                         as order_count,
        SUM(COALESCE(o.total_amount,0))     as total_amount,
        SUM(CASE WHEN o.status='pending'   THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN o.status='confirmed' THEN 1 ELSE 0 END) as confirmed_count,
        SUM(CASE WHEN o.status='cancelled' THEN 1 ELSE 0 END) as cancelled_count
    ")->first();

    return response()->json([
        'status' => true,
        'data'   => [
            'data' => $p->items(),
            'meta' => [
                'current_page' => $p->currentPage(),
                'per_page'     => $p->perPage(),
                'total'        => $p->total(),
                'last_page'    => $p->lastPage(),
                'sort'         => $sort,
                'dir'          => $dir,
            ],
            'overall' => [
                'order_count'     => (int)($overall->order_count ?? 0),
                'total_amount'    => (float)($overall->total_amount ?? 0),
                'pending_count'   => (int)($overall->pending_count ?? 0),
                'confirmed_count' => (int)($overall->confirmed_count ?? 0),
                'cancelled_count' => (int)($overall->cancelled_count ?? 0),
            ],
        ],
    ]);
}

    public function summary(Request $request)
    {
        $data = $request->validate(['email' => 'required|email']);
        $sellerId = DB::table('users')->where('email', $data['email'])->value('id');
        if (!$sellerId) {
            return response()->json(['status' => false, 'message' => 'User not found'], 404);
        }

        // Ürün sayısı (kullanıcıya atanmış aktif ürünler)
        $prodQ = DB::table('user_products as up')
            ->join('products as p', 'p.id', '=', 'up.product_id')
            ->where('up.user_id', $sellerId);

        if (Schema::hasColumn('user_products', 'active')) $prodQ->where('up.active', 1);
        if (Schema::hasColumn('products', 'active'))      $prodQ->where('p.active', 1);

        $productCount = $prodQ->distinct('p.id')->count('p.id');

        // ---- ÖZET İÇİN KAPSAM BELİRLE ----
        $hasItemsForSeller = DB::table('order_items')->where('seller_id', $sellerId)->exists();
        $hasOrdersForReseller = Schema::hasColumn('orders','reseller_id')
            ? DB::table('orders')->where('reseller_id', $sellerId)->exists()
            : false;

        // order_items + orders temel sorgusu
        $oiBase = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id');

        if ($hasItemsForSeller) {
            // Tercihen seller_id
            $oiBase->where('oi.seller_id', $sellerId);
        } elseif ($hasOrdersForReseller) {
            // Fallback: orders.reseller_id
            $oiBase->where('o.reseller_id', $sellerId);
        } else {
            // Hiçbiri yoksa sonuç doğal olarak 0 olur (ilişki kurulmamış demektir)
            return response()->json([
                'status' => true,
                'data' => [
                    'summary' => [
                        'total_sales'     => 0.0,
                        'order_count'     => 0,
                        'pending_count'   => 0,
                        'confirmed_count' => 0,
                        'cancelled_count' => 0,
                        'product_count'   => (int) $productCount,
                        'customer_count'  => 0,
                        'trend_30d'       => null,
                    ],
                ],
            ]);
        }

        $lineTotal = DB::raw('COALESCE(oi.total_price, oi.unit_price * oi.quantity)');

        // Sipariş adetleri (distinct order_id)
        $orderCount     = (clone $oiBase)->distinct('oi.order_id')->count('oi.order_id');
        $pendingCount   = (clone $oiBase)->where('o.status', 'pending')  ->distinct('oi.order_id')->count('oi.order_id');
        $confirmedCount = (clone $oiBase)->where('o.status', 'confirmed')->distinct('oi.order_id')->count('oi.order_id');
        $cancelledCount = (clone $oiBase)->where('o.status', 'cancelled')->distinct('oi.order_id')->count('oi.order_id');

        // Toplam satış (iptaller hariç)
        $totalSales = (clone $oiBase)->where('o.status', '!=', 'cancelled')->sum($lineTotal);

        // Trend (son 30g vs önceki 30g)
        $now = Carbon::now();
        $last30 = (clone $oiBase)
            ->where('o.status', '!=', 'cancelled')
            ->where('o.created_at', '>=', $now->copy()->subDays(30))
            ->sum($lineTotal);

        $prev30 = (clone $oiBase)
            ->where('o.status', '!=', 'cancelled')
            ->whereBetween('o.created_at', [$now->copy()->subDays(60), $now->copy()->subDays(30)])
            ->sum($lineTotal);

        $trend = $prev30 > 0 ? (($last30 - $prev30) / $prev30) : null;

        // Müşteri sayısı
        $customerCount = (clone $oiBase)->distinct('o.user_id')->count('o.user_id');

        return response()->json([
            'status' => true,
            'data' => [
                'summary' => [
                    'total_sales'     => (float) $totalSales,
                    'order_count'     => (int) $orderCount,
                    'pending_count'   => (int) $pendingCount,
                    'confirmed_count' => (int) $confirmedCount,
                    'cancelled_count' => (int) $cancelledCount,
                    'product_count'   => (int) $productCount,
                    'customer_count'  => (int) $customerCount,
                    'trend_30d'       => $trend,
                ],
            ],
        ]);
    }
}

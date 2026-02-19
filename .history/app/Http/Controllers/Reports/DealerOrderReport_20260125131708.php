<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DealerOrderReport extends Controller
{
    /**
     * GET /api/v1/reports/cash
     * Günlük kasa özeti: sipariş adedi, brüt toplam, bayi payı (0.15)
     */
    public function cash(Request $request)
    {
        $dealerId = Auth::id();

        // İsteğe bağlı filtreler
        $status = trim((string) $request->input('status', ''));
        $bizId  = $request->integer('business_id') ?: $request->integer('user_id'); // her iki parametreyi de destekle
        $start  = $request->input('start'); // 'YYYY-MM-DD'
        $end    = $request->input('end');

        // orders.dealer_id üzerinden filtre (şemanıza uygun)
        $q = DB::table('orders as o')
            ->where('o.dealer_id', $dealerId);

        if ($status !== '') {
            // UI'dan 'completed' gelirse 'delivered' kabul et (uyum katmanı)
            $mapped = $status === 'completed' ? 'delivered' : $status;
            $q->where('o.status', $mapped); // orders.status enum: pending|confirmed|away|delivered|cancelled
        }
        if ($bizId) {
            $q->where('o.user_id', $bizId);
        }
        if ($start && $end) {
            $q->whereBetween(DB::raw('DATE(o.created_at)'), [$start, $end]);
        }

        $rows = $q->selectRaw("
                DATE(o.created_at)                      as day,
                COUNT(*)                                 as order_count,
                COALESCE(SUM(o.total_amount),0)          as gross_total,
                COALESCE(SUM(o.total_amount*0.15),0)     as dealer_profit
            ")
            ->groupBy(DB::raw('DATE(o.created_at)'))
            ->orderBy('day', 'desc')
            ->get();

        $sum = (clone $q)->selectRaw('
                COALESCE(SUM(o.total_amount),0)         as total_gross,
                COALESCE(SUM(o.total_amount*0.15),0)    as total_dealer_profit
            ')->first();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'total_gross'         => (float) ($sum->total_gross ?? 0),
                'total_dealer_profit' => (float) ($sum->total_dealer_profit ?? 0),
            ],
        ]);
    }

    /**
     * GET /api/v1/reports/businesses
     * İşletme kırılımı
     */
    public function busines2(Request $request)
    {
        $dealerId = Auth::id();

        $status = trim((string) $request->input('status', ''));
        $bizId  = $request->integer('business_id') ?: $request->integer('user_id');
        $start  = $request->input('start');
        $end    = $request->input('end');

        $q = DB::table('orders as o')
            ->join('users as u', 'u.id', '=', 'o.user_id') // işletme = users(client)
            ->where('o.dealer_id', $dealerId)
            ->where('u.user_type', 'client');

        if ($status !== '') {
            $q->where('o.status', $status === 'completed' ? 'delivered' : $status);
        }
        if ($bizId) {
            $q->where('o.user_id', $bizId);
        }
        if ($start && $end) {
            $q->whereBetween(DB::raw('DATE(o.created_at)'), [$start, $end]);
        }

        $rows = $q->selectRaw('
                u.id                                  as business_id,
                u.name                                as business,
                COUNT(*)                              as order_count,
                COALESCE(SUM(o.total_amount),0)       as gross_total,
                COALESCE(AVG(o.total_amount),0)       as avg_order,
                COALESCE(SUM(o.total_amount*0.15),0)  as dealer_profit
            ')
            ->groupBy('u.id', 'u.name')
            ->orderByDesc('gross_total')
            ->get();

        $sum = (clone $q)->selectRaw('
                COALESCE(SUM(o.total_amount),0)      as total_gross,
                COALESCE(SUM(o.total_amount*0.15),0) as total_dealer_profit
            ')->first();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'total_gross'         => (float) ($sum->total_gross ?? 0),
                'total_dealer_profit' => (float) ($sum->total_dealer_profit ?? 0),
            ],
        ]);
    }
public function business(Request $request)
{
    $dealerId = Auth::id();

    $status = trim((string) $request->input('status', ''));
    $bizId  = $request->integer('business_id') ?: $request->integer('user_id');
    $start  = $request->input('start');
    $end    = $request->input('end');

    $q = DB::table('delivery_orders as d')
        ->join('users as u', 'u.id', '=', 'd.client_id') // işletme = users(client)
        ->where('d.dealer_id', $dealerId)
        ->where('u.user_type', 'client');

    if ($status !== '') {
        // completed -> delivered map'i gerekiyorsa
        $q->where('d.status', $status === 'completed' ? 'delivered' : $status);
    }

    if ($bizId) {
        $q->where('d.client_id', $bizId);
    }

    if ($start && $end) {
        $q->whereBetween(DB::raw('DATE(d.created_at)'), [$start, $end]);
    }

    $rows = $q->selectRaw('
            u.id                                   as business_id,
            u.name                                 as business,
            COUNT(*)                               as order_count,
            COALESCE(SUM(d.total_amount),0)        as gross_total,
            COALESCE(AVG(d.total_amount),0)        as avg_order,
            COALESCE(SUM(d.total_amount*0.15),0)   as dealer_profit
        ')
        ->groupBy('u.id', 'u.name')
        ->orderByDesc('gross_total')
        ->get();

    $sum = (clone $q)->selectRaw('
            COALESCE(SUM(d.total_amount),0)       as total_gross,
            COALESCE(SUM(d.total_amount*0.15),0)  as total_dealer_profit
        ')->first();

    return response()->json([
        'data' => $rows,
        'meta' => [
            'total_gross'         => (float) ($sum->total_gross ?? 0),
            'total_dealer_profit' => (float) ($sum->total_dealer_profit ?? 0),
        ],
    ]);
}

    /**
     * GET /api/v1/reports/couriers
     * Kurye kırılımı (delivery_orders + orders.dealer_id üzerinden bayi filtresi)
     */
    public function courier(Request $request)
    {
        $dealerId = Auth::id();

        $status = trim((string) $request->input('status', ''));
        $bizId  = $request->integer('business_id') ?: $request->integer('user_id');
        $start  = $request->input('start');
        $end    = $request->input('end');

        $q = DB::table('delivery_orders as d')
            ->leftJoin('orders as o', 'o.id', '=', 'd.parent_order_id') // bayi filtresi için
            ->leftJoin('users as c', 'c.id', '=', 'd.delivery_man_id')  // kurye adı
            ->leftJoin('users as b', 'b.id', '=', 'o.user_id')          // işletme adı (opsiyonel)
            ->where('o.dealer_id', $dealerId);

        if ($status !== '') {
            $q->where('d.status', $status);
        }
        if ($bizId) {
            $q->where('o.user_id', $bizId);
        }
        if ($start && $end) {
            $q->whereBetween(DB::raw('DATE(d.created_at)'), [$start, $end]);
        }

        $rows = $q->selectRaw('
                COALESCE(c.name,"—")                  as courier,
                COUNT(*)                              as delivery_count,
                COALESCE(SUM(d.total_amount),0)       as gross_total,
                COALESCE(SUM(d.total_amount*0.15),0)  as dealer_profit
            ')
            ->groupBy('courier')
            ->orderByDesc('gross_total')
            ->get();

        $sum = (clone $q)->selectRaw('
                COALESCE(SUM(d.total_amount),0)      as total_gross,
                COALESCE(SUM(d.total_amount*0.15),0) as total_dealer_profit
            ')->first();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'total_gross'         => (float) ($sum->total_gross ?? 0),
                'total_dealer_profit' => (float) ($sum->total_dealer_profit ?? 0),
            ],
        ]);
    }

    /**
     * GET /api/v1/reports/orders
     * Sipariş listesi (detay)
     */
    public function orders(Request $request)
    {
        $dealerId = Auth::id();

        $status = trim((string) $request->input('status', ''));
        $bizId  = $request->integer('business_id') ?: $request->integer('user_id');
        $start  = $request->input('start');
        $end    = $request->input('end');

        $q = DB::table('orders as o')
            ->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->where('o.dealer_id', $dealerId);

        if ($status !== '') {
            $q->where('o.status', $status === 'completed' ? 'delivered' : $status);
        }
        if ($bizId) {
            $q->where('o.user_id', $bizId);
        }
        if ($start && $end) {
            $q->whereBetween(DB::raw('DATE(o.created_at)'), [$start, $end]);
        }

        $rows = $q->selectRaw('
                o.id,
                DATE(o.created_at)                    as created_at,
                COALESCE(u.name,"—")                  as business,
                o.status,
                o.total_amount                        as gross_total,
                (o.total_amount * 0.15)               as dealer_profit
            ')
            ->orderByDesc('o.id')
            ->get();

        $sum = (clone $q)->selectRaw('
                COALESCE(SUM(o.total_amount),0)      as total_gross,
                COALESCE(SUM(o.total_amount*0.15),0) as total_dealer_profit
            ')->first();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'total_gross'         => (float) ($sum->total_gross ?? 0),
                'total_dealer_profit' => (float) ($sum->total_dealer_profit ?? 0),
            ],
        ]);
    }

    /**
     * GET /api/v1/me/businesses
     * Bayinin kendi işletmeleri (users tablosundan)
     */
    public function myBusinesses(Request $request)
    {
        $dealerId = Auth::id();

        $rows = DB::table('users')
            ->where('user_type', 'client')
            ->where(function ($w) use ($dealerId) {
                // Tercihen orders.dealer_id kullanıyoruz ama bazı setuplarda users.dealer_id da bulunabiliyor.
                $w->where('dealer_id', $dealerId)    // varsa
                 ->orWhereExists(function ($q) use ($dealerId) {
                     $q->from('orders')
                       ->whereColumn('orders.user_id', 'users.id')
                       ->where('orders.dealer_id', $dealerId);
                 });
            })
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return response()->json($rows);
    }
}

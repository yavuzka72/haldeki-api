<?php
// app/Http/Controllers/Reports/DealerCashReport.php// app/Http/Controllers/Reports/DealerCashReport.php
namespace App\Http\Controllers\Reports;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class DealerReportController extends Controller
{
    /**
     * 🧮 Kasa Raporu
     * Günlük bazda sipariş sayısı, toplam, bayi kârı
     */
    public function cash(Request $request)
    {
        $dealerId = Auth::id();
        $status   = $request->filled('status') ? $request->string('status') : null;
        $bizId    = $request->integer('business_id');
        $start    = $request->date('start');
        $end      = $request->date('end');

        $q = DB::table('orders as o')
            ->join('users as bu', 'bu.id', '=', 'o.business_id')
            ->where('bu.type', 'client')
            ->where('bu.dealer_id', $dealerId);

        if ($status) $q->where('o.status', $status);
        if ($bizId)  $q->where('o.business_id', $bizId);
        if ($start && $end)
            $q->whereBetween(DB::raw('DATE(o.created_at)'), [$start, $end]);

        $rows = $q->selectRaw("
            DATE(o.created_at) as day,
            COUNT(*)           as order_count,
            COALESCE(SUM(o.total_amount),0)        as gross_total,
            COALESCE(SUM(o.total_amount*0.15),0)   as dealer_profit
        ")
        ->groupBy(DB::raw('DATE(o.created_at)'))
        ->orderBy('day', 'desc')
        ->get();

        $sum = $q->selectRaw('
            COALESCE(SUM(o.total_amount),0)       as total_gross,
            COALESCE(SUM(o.total_amount*0.15),0)  as total_dealer_profit
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
     * 🏪 İşletme Raporu
     * Hangi işletme ne kadar sipariş vermiş
     */
    public function business(Request $request)
    {
        $dealerId = Auth::id();
        $status   = $request->filled('status') ? $request->string('status') : null;
        $bizId    = $request->integer('business_id');
        $start    = $request->date('start');
        $end      = $request->date('end');

        $q = DB::table('orders as o')
            ->join('users as bu', 'bu.id', '=', 'o.business_id')
            ->where('bu.type', 'client')
            ->where('bu.dealer_id', $dealerId);

        if ($status) $q->where('o.status', $status);
        if ($bizId)  $q->where('o.business_id', $bizId);
        if ($start && $end)
            $q->whereBetween(DB::raw('DATE(o.created_at)'), [$start, $end]);

        $rows = $q->selectRaw('
            bu.id                         as business_id,
            bu.name                       as business,
            COUNT(*)                      as order_count,
            COALESCE(SUM(o.total_amount),0)      as gross_total,
            COALESCE(AVG(o.total_amount),0)      as avg_order,
            COALESCE(SUM(o.total_amount*0.15),0) as dealer_profit
        ')
        ->groupBy('bu.id','bu.name')
        ->orderByDesc('gross_total')
        ->get();

        $sum = $q->selectRaw('
            COALESCE(SUM(o.total_amount),0)       as total_gross,
            COALESCE(SUM(o.total_amount*0.15),0)  as total_dealer_profit
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
     * 🚚 Kurye Raporu
     * delivery_orders tablosu üzerinden
     */
    public function courier(Request $request)
    {
        $dealerId = Auth::id();
        $status   = $request->filled('status') ? $request->string('status') : null;
        $bizId    = $request->integer('business_id');
        $start    = $request->date('start');
        $end      = $request->date('end');

        $q = DB::table('delivery_orders as d')
            ->join('users as cu', 'cu.id', '=', 'd.courier_id')
            ->join('users as bu', 'bu.id', '=', 'd.business_id')
            ->where('bu.dealer_id', $dealerId);

        if ($status) $q->where('d.status', $status);
        if ($bizId)  $q->where('d.business_id', $bizId);
        if ($start && $end)
            $q->whereBetween(DB::raw('DATE(d.created_at)'), [$start, $end]);

        $rows = $q->selectRaw('
            cu.name                        as courier,
            COUNT(*)                       as delivery_count,
            COALESCE(SUM(d.total_amount),0)        as gross_total,
            COALESCE(SUM(d.total_amount*0.15),0)   as dealer_profit
        ')
        ->groupBy('cu.name')
        ->orderByDesc('gross_total')
        ->get();

        $sum = $q->selectRaw('
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
     * 🧾 Sipariş Raporu (detay listesi)
     */
    public function orders(Request $request)
    {
        $dealerId = Auth::id();
        $status   = $request->filled('status') ? $request->string('status') : null;
        $bizId    = $request->integer('business_id');
        $start    = $request->date('start');
        $end      = $request->date('end');

        $q = DB::table('orders as o')
            ->join('users as bu', 'bu.id', '=', 'o.business_id')
            ->where('bu.type', 'client')
            ->where('bu.dealer_id', $dealerId);

        if ($status) $q->where('o.status', $status);
        if ($bizId)  $q->where('o.business_id', $bizId);
        if ($start && $end)
            $q->whereBetween(DB::raw('DATE(o.created_at)'), [$start, $end]);

        $rows = $q->selectRaw('
            o.id,
            bu.name as business,
            o.status,
            o.total_amount as gross_total,
            (o.total_amount * 0.15) as dealer_profit,
            DATE(o.created_at) as created_at
        ')
        ->orderByDesc('o.id')
        ->get();

        $sum = $q->selectRaw('
            COALESCE(SUM(o.total_amount),0)       as total_gross,
            COALESCE(SUM(o.total_amount*0.15),0)  as total_dealer_profit
        ')->first();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'total_gross'         => (float) ($sum->total_gross ?? 0),
                'total_dealer_profit' => (float) ($sum->total_dealer_profit ?? 0),
            ],
        ]);
    }
}

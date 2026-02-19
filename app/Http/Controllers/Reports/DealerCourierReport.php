<?php
// app/Http/Controllers/Reports/DealerCourierReport.php
namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Support\DealerScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DealerCourierReport extends Controller
{
    public function index(Request $r)
    {
        $base = DB::table('delivery_orders as d');

        $q = DealerScope::byDealerOnDelivery($base, $r);
        // Opsiyonel: business filtresi için orders join’i zaten var (o alias 'o')
        $status = $r->string('status');
        $start  = $r->date('start'); $end = $r->date('end');
        $bizId  = $r->integer('business_id');

        $q = $q
          ->when($bizId, fn($q)=>$q->where('o.business_id', $bizId))
          ->when($status, fn($q)=>$q->where('d.status', $status))
          ->when($start && $end, fn($q)=>$q->whereBetween('d.created_at', [$start, $end]))
          ->join('users as u','u.id','=','d.courier_id');

        $rows = $q->selectRaw('d.courier_id, u.name as courier,
                               COUNT(*) as delivered_orders,
                               SUM(COALESCE(d.total_amount, o.total_amount)) as gross_total,
                               SUM(COALESCE(d.total_amount, o.total_amount))*0.15 as dealer_profit')
                  ->groupBy('d.courier_id','u.name')
                  ->orderByDesc('delivered_orders')
                  ->paginate(50);

        $totals = (clone $q)->selectRaw('SUM(COALESCE(d.total_amount, o.total_amount)) as gross_total,
                                         SUM(COALESCE(d.total_amount, o.total_amount))*0.15 as dealer_profit')->first();

        return response()->json([
            'data'  => $rows->items(),
            'meta'  => [
                'total_gross' => (float)($totals->gross_total ?? 0),
                'total_dealer_profit' => (float)($totals->dealer_profit ?? 0),
            ],
            'links' => ['next' => $rows->nextPageUrl()],
        ]);
    }
}

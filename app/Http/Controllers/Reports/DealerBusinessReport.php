<?php
// app/Http/Controllers/Reports/DealerBusinessReport.php
namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Support\DealerScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DealerBusinessReport extends Controller
{
    public function index(Request $r)
    {
        $base = DB::table('orders');

        // V1 veya V2 (şemana göre)
        $q = DealerScope::byDealerOnOrdersViaOrderUser($base, $r);
        // $q = DealerScope::byDealerOnOrdersViaBusinessOwner($base, $r);

        $q = DealerScope::applyFilters($q, $r, 'orders.created_at')
             ->join('businesses','businesses.id','=','orders.business_id');

        $rows = $q->selectRaw('orders.business_id,
                               businesses.name as business,
                               COUNT(*) as order_count,
                               SUM(orders.total_amount) as gross_total,
                               SUM(orders.total_amount)*0.15 as dealer_profit,
                               AVG(orders.total_amount) as avg_order')
                  ->groupBy('orders.business_id','businesses.name')
                  ->orderByDesc('gross_total')
                  ->paginate(50);

        $totals = (clone $q)->selectRaw('SUM(orders.total_amount) as gross_total,
                                         SUM(orders.total_amount)*0.15 as dealer_profit')->first();

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

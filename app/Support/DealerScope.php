<?php
// app/Support/DealerScope.php
namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DealerScope {

    /** V1 — orders.user_id üzerinden: orders -> users (orders.user_id) */
    public static function byDealerOnOrdersViaOrderUser(Builder|\Illuminate\Database\Query\Builder $q, Request $r) {
        $dealerId = DealerCtx::currentDealerId($r);
        return $q->join('users as ou', 'ou.id', '=', 'orders.user_id')
                 ->where('ou.dealer_id', $dealerId);
    }

    /** V2 — businesses.user_id üzerinden: orders -> businesses -> users */
    public static function byDealerOnOrdersViaBusinessOwner(Builder|\Illuminate\Database\Query\Builder $q, Request $r) {
        $dealerId = DealerCtx::currentDealerId($r);
        return $q->join('businesses as b', 'b.id', '=', 'orders.business_id')
                 ->join('users as bu', 'bu.id', '=', 'b.user_id')
                 ->where('bu.dealer_id', $dealerId);
    }

    /** delivery_orders için: courier user üzerinden kısıtlama (kuryeler de bir bayiye bağlı) */
    public static function byDealerOnDelivery(Builder|\Illuminate\Database\Query\Builder $q, Request $r) {
        $dealerId = DealerCtx::currentDealerId($r);
        return $q
          ->join('orders as o', 'o.id', '=', 'd.order_id')
          ->join('users as cu', 'cu.id', '=', 'd.courier_id')
          ->where('cu.dealer_id', $dealerId);
    }

    /** Ortak filtreler (hepsi opsiyonel) */
    public static function applyFilters($q, Request $r, string $dateCol) {
        $status = $r->string('status');
        $bizId  = $r->integer('business_id');
        $start  = $r->date('start'); $end = $r->date('end');

        return $q
          ->when($bizId, fn($q)=>$q->where('orders.business_id', $bizId))
          ->when($status, fn($q)=>$q->where('orders.status', $status))
          ->when($start && $end, fn($q)=>$q->whereBetween($dateCol, [$start, $end]));
    }
}

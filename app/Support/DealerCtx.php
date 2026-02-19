<?php
// app/Support/DealerCtx.php
namespace App\Support;

use Illuminate\Http\Request;

class DealerCtx {
    // Dealer kullanıcıları için dealer_id zaten dolu olur.
    // Bayinin kendisi user ise: dealer_id yoksa id’yi kullan.
    public static function currentDealerId(Request $r): int {
        $u = $r->user();
        return (int)($u->dealer_id ?? $u->id);
    }
}

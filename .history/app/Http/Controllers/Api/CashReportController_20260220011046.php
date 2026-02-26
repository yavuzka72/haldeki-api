<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class CashReportController extends Controller
{
    public function index(Request $request)
    {
        $fromParam = $request->query('from'); // '2025-11-16'
        $toParam   = $request->query('to');   // '2025-11-16'

        try {
            if ($fromParam) {
                $from = Carbon::parse($fromParam)->startOfDay();
                $to   = Carbon::parse($toParam ?? $fromParam)->endOfDay();
            } else {
                $from = now()->startOfDay();
                $to   = now()->endOfDay();
            }
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Geçersiz tarih formatı. Örnek: from=2026-02-19&to=2026-02-19',
            ], 422);
        }

        $dealerId  = $request->query('dealer_id');
        $courierId = $request->query('courier_id');

        $dealerScopeIds = collect();
        $deliverymanIds = collect();
        if (!empty($dealerId)) {
            $dealerRootId = (int) $dealerId;
            $hasUserDealerColumn = Schema::hasColumn('users', 'dealer_id');
            $dealerScopeIds = collect([$dealerRootId]);

            if ($hasUserDealerColumn) {
                $dealerScopeIds = $dealerScopeIds
                    ->merge(
                        DB::table('users')
                            ->where('dealer_id', $dealerRootId)
                            ->pluck('id')
                    )
                    ->unique()
                    ->values();
            }

            // courier_id request'ten gelmese bile, dealer'a bagli kuryeler otomatik kapsanir
            $deliverymanIdsQ = DB::table('users');
            if ($hasUserDealerColumn && $dealerScopeIds->isNotEmpty()) {
                $deliverymanIdsQ->whereIn('dealer_id', $dealerScopeIds->all());
            } else {
                $deliverymanIdsQ->whereRaw('1=0');
            }

            $deliverymanIds = $deliverymanIdsQ
                ->whereIn('user_type', ['deliveryman', 'courier', 'delivery_man', 'kurye'])
                ->pluck('id');
        }

        $fromDate = $from->toDateString();
        $toDate   = $to->toDateString();
        $orderDateExpr = 'DATE(COALESCE(o.updated_at, o.created_at))';

        $ordersQ = DB::table('orders as o')
            ->leftJoin('users as dealer', 'dealer.id', '=', 'o.dealer_id')
            ->leftJoin('users as buyer', 'buyer.id', '=', 'o.user_id')
            ->leftJoin('partner_clients as pc', 'pc.id', '=', 'o.partner_client_id')
            ->whereBetween(DB::raw($orderDateExpr), [$fromDate, $toDate])
            ->when($dealerId, function ($q) use ($dealerId) {
                return $q->where('o.dealer_id', (int) $dealerId);
            });

        $customers = (clone $ordersQ)
            ->orderBy('o.updated_at')
            ->get([
                DB::raw($orderDateExpr . ' as islem_tarihi'),
                'o.dealer_id',
                DB::raw('COALESCE(dealer.name, "Bayi") as bayi_adi'),
                DB::raw('NULL as delivery_order_id'),
                DB::raw('o.id as kaynak_order_id'),
                DB::raw('COALESCE(pc.name, buyer.name, o.ad_soyad, "Isletme") as musteri_adi'),
                DB::raw('COALESCE(o.total_amount, 0) as siparis_tutari'),
                DB::raw('COALESCE(o.total_amount, 0) as tahsil_edilen_tutar'),
                DB::raw('COALESCE(o.payment_status, "pending") as odeme_durumu'),
                DB::raw('COALESCE(o.dealer_status, o.status, "pending") as siparis_durumu'),
            ]);

        // Kurye/supplier tarafini simdilik kapatiyoruz: sadece orders tablosu
        $suppliers = collect();
        $couriers  = collect();

        // Partner client bazli ciro + hakedis
        $businesses = (clone $ordersQ)
            ->groupBy('o.partner_client_id', 'pc.name', 'pc.commission_rate')
            ->orderBy('pc.name')
            ->get([
                DB::raw('o.partner_client_id'),
                DB::raw('COALESCE(pc.name, "Partner Isletme") as isletme_adi'),
                DB::raw('COALESCE(pc.commission_rate, 10) as komisyon_orani'),
                DB::raw('COUNT(*) as siparis_adedi'),
                DB::raw('COALESCE(SUM(o.total_amount), 0) as ciro'),
                DB::raw('ROUND(COALESCE(SUM(o.total_amount), 0) * (COALESCE(pc.commission_rate, 10) / 100), 2) as hakedis'),
            ]);

        $vendors = $businesses->map(function ($b) use ($dealerId) {
            return (object) [
                'islem_tarihi' => null,
                'dealer_id' => $dealerId ? (int) $dealerId : null,
                'bayi_adi' => 'Bayi',
                'order_id' => null,
                'siparis_kodu' => null,
                'siparis_tutari' => (float) ($b->ciro ?? 0),
                'bayi_komisyon_tutari' => (float) ($b->hakedis ?? 0),
                'siparis_durumu' => 'aggregated',
                'odeme_durumu' => 'calculated',
                'partner_client_id' => $b->partner_client_id,
                'isletme_adi' => $b->isletme_adi,
                'siparis_adedi' => (int) ($b->siparis_adedi ?? 0),
                'ciro' => (float) ($b->ciro ?? 0),
                'komisyon_orani' => (float) ($b->komisyon_orani ?? 0),
                'hakedis' => (float) ($b->hakedis ?? 0),
            ];
        })->values();

        $daily = (clone $ordersQ)
            ->groupBy(DB::raw($orderDateExpr), 'o.dealer_id', 'dealer.name')
            ->orderBy(DB::raw($orderDateExpr))
            ->get([
                DB::raw($orderDateExpr . ' as tarih'),
                'o.dealer_id',
                DB::raw('COALESCE(dealer.name, "Bayi") as bayi_adi'),
                DB::raw('COALESCE(SUM(o.total_amount), 0) as musteri_tahsilat'),
                DB::raw('0 as tedarikci_odeme'),
                DB::raw('0 as kurye_odeme'),
                DB::raw('ROUND(SUM(o.total_amount * (COALESCE(pc.commission_rate, 10) / 100)), 2) as bayi_komisyonu'),
                DB::raw('ROUND(COALESCE(SUM(o.total_amount), 0) - SUM(o.total_amount * (COALESCE(pc.commission_rate, 10) / 100)), 2) as gunluk_net_kasa'),
            ]);

        $totalCiro = (float) $customers->sum('tahsil_edilen_tutar');
        $totalHakedis = (float) $vendors->sum('bayi_komisyon_tutari');

        $summary = [
            'customer_collections_total' => $totalCiro,
            'supplier_payments_total'    => (float) $suppliers->sum('tedarikci_odeme_tutari'),
            'courier_payments_total'     => (float) $couriers->sum('kurye_odeme_tutari'),
            'vendor_commissions_total'   => $totalHakedis,
            'net_cash_total'             => round($totalCiro - $totalHakedis, 2),
        ];

        return response()->json([
            'success'   => true,
            'date_from' => $fromDate,
            'date_to'   => $toDate,
            'dealer_id' => $dealerId,
            'courier_id'=> $courierId,
            'resolved_dealer_scope_ids' => $dealerScopeIds,
            'resolved_deliveryman_ids' => $deliverymanIds,
            'summary'   => $summary,
            'businesses'=> $vendors,
            'daily'     => $daily,
            'customers' => $customers,
            'suppliers' => $suppliers,
            'couriers'  => $couriers,
            'vendors'   => $vendors,
            // Backward-compatible aliaslar
            'daily_cash'           => $daily,
            'customer_collections' => $customers,
            'supplier_payments'    => $suppliers,
            'courier_payments'     => $couriers,
            'vendor_commissions'   => $vendors,
        ]);
    }
}

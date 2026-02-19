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

        $ordersQ = DB::table('orders as o')
            ->leftJoin('users as buyer', 'buyer.id', '=', 'o.user_id')
            ->leftJoin('users as dealer', 'dealer.id', '=', 'o.dealer_id')
            ->whereBetween(DB::raw('DATE(o.created_at)'), [$fromDate, $toDate])
            ->when($dealerId, function ($q) use ($dealerId, $dealerScopeIds) {
                if ($dealerScopeIds->isNotEmpty()) {
                    return $q->whereIn('o.dealer_id', $dealerScopeIds->all());
                }
                return $q->where('o.dealer_id', $dealerId);
            });

        $customers = (clone $ordersQ)
            ->orderBy('o.created_at')
            ->get([
                DB::raw('DATE(o.created_at) as islem_tarihi'),
                'o.dealer_id',
                DB::raw('COALESCE(dealer.name, "Bayi") as bayi_adi'),
                DB::raw('NULL as delivery_order_id'),
                DB::raw('o.id as kaynak_order_id'),
                DB::raw('COALESCE(buyer.name, o.ad_soyad, "Musteri") as musteri_adi'),
                DB::raw('COALESCE(o.total_amount, 0) as siparis_tutari'),
                DB::raw('CASE WHEN o.payment_status = "paid" THEN COALESCE(o.total_amount, 0) ELSE 0 END as tahsil_edilen_tutar'),
                DB::raw('COALESCE(o.payment_status, "pending") as odeme_durumu'),
                DB::raw('COALESCE(o.dealer_status, o.status, "pending") as siparis_durumu'),
            ]);

        $suppliers = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->leftJoin('users as dealer', 'dealer.id', '=', 'o.dealer_id')
            ->leftJoin('users as supplier', 'supplier.id', '=', 'oi.seller_id')
            ->whereBetween(DB::raw('DATE(o.created_at)'), [$fromDate, $toDate])
            ->when($dealerId, function ($q) use ($dealerId, $dealerScopeIds) {
                if ($dealerScopeIds->isNotEmpty()) {
                    return $q->whereIn('o.dealer_id', $dealerScopeIds->all());
                }
                return $q->where('o.dealer_id', $dealerId);
            })
            ->groupBy(
                DB::raw('DATE(o.created_at)'),
                'o.dealer_id',
                'dealer.name',
                'o.id',
                'o.order_number',
                'supplier.name',
                'o.dealer_status',
                'o.status'
            )
            ->orderBy(DB::raw('DATE(o.created_at)'))
            ->get([
                DB::raw('DATE(o.created_at) as islem_tarihi'),
                'o.dealer_id',
                DB::raw('COALESCE(dealer.name, "Bayi") as bayi_adi'),
                DB::raw('o.id as order_id'),
                DB::raw('o.order_number as siparis_kodu'),
                DB::raw('COALESCE(supplier.name, "Tedarikci") as tedarikci_adi'),
                DB::raw('COALESCE(MAX(o.total_amount), 0) as siparis_tutari'),
                DB::raw('COALESCE(SUM(oi.total_price), 0) as tedarikci_odeme_tutari'),
                DB::raw('COALESCE(MAX(o.dealer_status), MAX(o.status), "pending") as siparis_durumu'),
                DB::raw('CASE WHEN COALESCE(MAX(o.supplier_status), "") IN ("paid","delivered","completed","closed") THEN "paid" ELSE "pending" END as odeme_durumu'),
            ]);

        $hasDeliveryOrders = Schema::hasTable('delivery_orders');
        $couriers = collect();
        $vendors  = collect();
        if ($hasDeliveryOrders) {
            $hasCommissionAmount = Schema::hasColumn('delivery_orders', 'commission_amount');
            $hasCommissionStatus = Schema::hasColumn('delivery_orders', 'commission_status');
            $hasCourierPayStatus = Schema::hasColumn('delivery_orders', 'courier_payment_status');
            $hasCourierPaidAt    = Schema::hasColumn('delivery_orders', 'courier_paid_at');
            $hasCommissionAt     = Schema::hasColumn('delivery_orders', 'commission_charged_at');
            $hasDateCol          = Schema::hasColumn('delivery_orders', 'date');

            $deliveryDateExpr = $hasDateCol ? 'DATE(d.date)' : 'DATE(d.created_at)';

            $deliveryQ = DB::table('delivery_orders as d')
                ->leftJoin('orders as o', 'o.id', '=', 'd.parent_order_id')
                ->leftJoin('users as dealer', 'dealer.id', '=', 'o.dealer_id')
                ->leftJoin('users as courier', 'courier.id', '=', 'd.delivery_man_id')
                ->whereBetween(DB::raw($deliveryDateExpr), [$fromDate, $toDate])
                ->when($dealerId, function ($q) use ($dealerId, $dealerScopeIds, $deliverymanIds) {
                    $q->where(function ($w) use ($dealerId, $dealerScopeIds, $deliverymanIds) {
                        if ($dealerScopeIds->isNotEmpty()) {
                            $w->whereIn('o.dealer_id', $dealerScopeIds->all());
                        } else {
                            $w->where('o.dealer_id', $dealerId);
                        }
                        if ($deliverymanIds->isNotEmpty()) {
                            $w->orWhereIn('d.delivery_man_id', $deliverymanIds->all());
                        }
                    });
                })
                ->when($courierId, function ($q) use ($courierId) {
                    $q->where('d.delivery_man_id', (int) $courierId);
                });

            $couriers = (clone $deliveryQ)
                ->orderBy(DB::raw($deliveryDateExpr))
                ->get([
                    DB::raw($deliveryDateExpr . ' as islem_tarihi'),
                    DB::raw('COALESCE(o.dealer_id, 0) as dealer_id'),
                    DB::raw('COALESCE(dealer.name, "Bayi") as bayi_adi'),
                    DB::raw('d.id as delivery_order_id'),
                    DB::raw('o.id as kaynak_order_id'),
                    DB::raw('COALESCE(courier.name, "Kurye") as kurye_adi'),
                    DB::raw('COALESCE(d.total_amount, o.total_amount, 0) as siparis_tutari'),
                    DB::raw('COALESCE(d.fixed_charges, 0) as kurye_odeme_tutari'),
                    DB::raw('COALESCE(d.status, "pending") as teslimat_durumu'),
                    DB::raw(
                        $hasCourierPayStatus
                            ? 'COALESCE(d.courier_payment_status, "pending") as odeme_durumu'
                            : 'CASE WHEN d.delivery_man_id IS NOT NULL THEN "pending" ELSE "unknown" END as odeme_durumu'
                    ),
                ]);

            $vendors = (clone $deliveryQ)
                ->orderBy(DB::raw($deliveryDateExpr))
                ->get([
                    DB::raw($deliveryDateExpr . ' as islem_tarihi'),
                    DB::raw('COALESCE(o.dealer_id, 0) as dealer_id'),
                    DB::raw('COALESCE(dealer.name, "Bayi") as bayi_adi'),
                    DB::raw('o.id as order_id'),
                    DB::raw('o.order_number as siparis_kodu'),
                    DB::raw('COALESCE(d.total_amount, o.total_amount, 0) as siparis_tutari'),
                    DB::raw(
                        $hasCommissionAmount
                            ? 'COALESCE(d.commission_amount, 0) as bayi_komisyon_tutari'
                            : 'ROUND(COALESCE(d.total_amount, o.total_amount, 0) * 0.15, 2) as bayi_komisyon_tutari'
                    ),
                    DB::raw('COALESCE(o.dealer_status, o.status, "pending") as siparis_durumu'),
                    DB::raw(
                        $hasCommissionStatus
                            ? 'COALESCE(d.commission_status, "pending") as odeme_durumu'
                            : 'CASE WHEN '
                                . ($hasCommissionAt ? 'd.commission_charged_at' : 'NULL')
                                . ' IS NOT NULL THEN "paid" ELSE "pending" END as odeme_durumu'
                    ),
                ]);
        }

        $dailyMap = [];
        foreach ($customers as $row) {
            $day = (string) $row->islem_tarihi;
            $dailyMap[$day] ??= [
                'tarih' => $day,
                'dealer_id' => $dealerId ? (int)$dealerId : null,
                'bayi_adi' => null,
                'musteri_tahsilat' => 0.0,
                'tedarikci_odeme' => 0.0,
                'kurye_odeme' => 0.0,
                'bayi_komisyonu' => 0.0,
                'gunluk_net_kasa' => 0.0,
            ];
            $dailyMap[$day]['musteri_tahsilat'] += (float) ($row->tahsil_edilen_tutar ?? 0);
            $dailyMap[$day]['bayi_adi'] = $dailyMap[$day]['bayi_adi'] ?? ($row->bayi_adi ?? null);
        }
        foreach ($suppliers as $row) {
            $day = (string) $row->islem_tarihi;
            $dailyMap[$day] ??= [
                'tarih' => $day,
                'dealer_id' => $dealerId ? (int)$dealerId : null,
                'bayi_adi' => null,
                'musteri_tahsilat' => 0.0,
                'tedarikci_odeme' => 0.0,
                'kurye_odeme' => 0.0,
                'bayi_komisyonu' => 0.0,
                'gunluk_net_kasa' => 0.0,
            ];
            $dailyMap[$day]['tedarikci_odeme'] += (float) ($row->tedarikci_odeme_tutari ?? 0);
            $dailyMap[$day]['bayi_adi'] = $dailyMap[$day]['bayi_adi'] ?? ($row->bayi_adi ?? null);
        }
        foreach ($couriers as $row) {
            $day = (string) $row->islem_tarihi;
            $dailyMap[$day] ??= [
                'tarih' => $day,
                'dealer_id' => $dealerId ? (int)$dealerId : null,
                'bayi_adi' => null,
                'musteri_tahsilat' => 0.0,
                'tedarikci_odeme' => 0.0,
                'kurye_odeme' => 0.0,
                'bayi_komisyonu' => 0.0,
                'gunluk_net_kasa' => 0.0,
            ];
            $dailyMap[$day]['kurye_odeme'] += (float) ($row->kurye_odeme_tutari ?? 0);
            $dailyMap[$day]['bayi_adi'] = $dailyMap[$day]['bayi_adi'] ?? ($row->bayi_adi ?? null);
        }
        foreach ($vendors as $row) {
            $day = (string) $row->islem_tarihi;
            $dailyMap[$day] ??= [
                'tarih' => $day,
                'dealer_id' => $dealerId ? (int)$dealerId : null,
                'bayi_adi' => null,
                'musteri_tahsilat' => 0.0,
                'tedarikci_odeme' => 0.0,
                'kurye_odeme' => 0.0,
                'bayi_komisyonu' => 0.0,
                'gunluk_net_kasa' => 0.0,
            ];
            $dailyMap[$day]['bayi_komisyonu'] += (float) ($row->bayi_komisyon_tutari ?? 0);
            $dailyMap[$day]['bayi_adi'] = $dailyMap[$day]['bayi_adi'] ?? ($row->bayi_adi ?? null);
        }

        $daily = collect($dailyMap)
            ->map(function ($r) {
                $r['gunluk_net_kasa'] = round(
                    ($r['musteri_tahsilat'] ?? 0)
                    - ($r['tedarikci_odeme'] ?? 0)
                    - ($r['kurye_odeme'] ?? 0)
                    + ($r['bayi_komisyonu'] ?? 0),
                    2
                );
                return (object) $r;
            })
            ->sortBy('tarih')
            ->values();

        $summary = [
            'customer_collections_total' => (float) $customers->sum('tahsil_edilen_tutar'),
            'supplier_payments_total'    => (float) $suppliers->sum('tedarikci_odeme_tutari'),
            'courier_payments_total'     => (float) $couriers->sum('kurye_odeme_tutari'),
            'vendor_commissions_total'   => (float) $vendors->sum('bayi_komisyon_tutari'),
            'net_cash_total'             => (float) $daily->sum('gunluk_net_kasa'),
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

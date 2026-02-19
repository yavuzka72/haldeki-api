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
        // -------------------------------------------------------------
        // 1) Tarih aralığı:
        //  - from/to gelirse: o aralık
        //  - sadece from gelirse: o gün
        //  - hiçbiri gelmezse: bugün
        // -------------------------------------------------------------
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

        // Dealer (bayi) ve Kurye filtreleri
        $dealerId  = $request->query('dealer_id');   // temel filtre
        $courierId = $request->query('courier_id');  // vw_kurye_odeme_detay.kurye_id

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

        // Tarihi DATE kolonlarıyla uyumlu string'e çevirelim
        $fromDate = $from->toDateString(); // 'YYYY-MM-DD'
        $toDate   = $to->toDateString();   // 'YYYY-MM-DD'

        // -------------------------------------------------------------
        // 2) Günlük Kasa Özeti (vw_gunluk_kasa_ozet)
        //    Kolonlar:
        //    - tarih, dealer_id, bayi_adi
        //    - musteri_tahsilat, tedarikci_odeme, kurye_odeme, bayi_komisyonu, gunluk_net_kasa
        // -------------------------------------------------------------
        $daily = DB::table('vw_gunluk_kasa_ozet')
            ->whereBetween('tarih', [$fromDate, $toDate])
            ->when($dealerId, function ($q) use ($dealerId, $dealerScopeIds) {
                if ($dealerScopeIds->isNotEmpty()) {
                    $q->whereIn('dealer_id', $dealerScopeIds->all());
                    return;
                }
                $q->where('dealer_id', $dealerId);
            })
            ->orderBy('tarih')
            ->get([
                'tarih',
                'dealer_id',
                'bayi_adi',
                'musteri_tahsilat',
                'tedarikci_odeme',
                'kurye_odeme',
                'bayi_komisyonu',
                'gunluk_net_kasa',
            ]);

        // -------------------------------------------------------------
        // 3) Müşteri Tahsilat Detayı (vw_musteri_tahsilat_detay)
        //    Kolonlar:
        //    - islem_tarihi, dealer_id, bayi_adi
        //    - delivery_order_id, kaynak_order_id
        //    - musteri_id, musteri_adi
        //    - siparis_tutari, tahsil_edilen_tutar
        //    - odeme_durumu, siparis_durumu
        // -------------------------------------------------------------
        $customers = DB::table('vw_musteri_tahsilat_detay')
            ->whereBetween('islem_tarihi', [$fromDate, $toDate])
            ->when($dealerId, function ($q) use ($dealerId, $dealerScopeIds) {
                if ($dealerScopeIds->isNotEmpty()) {
                    $q->whereIn('dealer_id', $dealerScopeIds->all());
                    return;
                }
                $q->where('dealer_id', $dealerId);
            })
            ->orderBy('islem_tarihi')
            ->get([
                'islem_tarihi',
                'dealer_id',
                'bayi_adi',
                'delivery_order_id',
                'kaynak_order_id',
                'musteri_adi',
                'siparis_tutari',
                'tahsil_edilen_tutar',
                'odeme_durumu',
                'siparis_durumu',
            ]);

        // -------------------------------------------------------------
        // 4) Tedarikçi Ödemeleri (vw_tedarikci_odeme_detay)
        //    Kolonlar:
        //    - islem_tarihi, dealer_id, bayi_adi
        //    - order_id, siparis_kodu
        //    - tedarikci_adi
        //    - siparis_tutari, tedarikci_odeme_tutari
        //    - siparis_durumu, odeme_durumu
        // -------------------------------------------------------------
        $suppliers = DB::table('vw_tedarikci_odeme_detay')
            ->whereBetween('islem_tarihi', [$fromDate, $toDate])
            ->when($dealerId, function ($q) use ($dealerId, $dealerScopeIds) {
                if ($dealerScopeIds->isNotEmpty()) {
                    $q->whereIn('dealer_id', $dealerScopeIds->all());
                    return;
                }
                $q->where('dealer_id', $dealerId);
            })
            ->orderBy('islem_tarihi')
            ->get([
                'islem_tarihi',
                'dealer_id',
                'bayi_adi',
                'order_id',
                'siparis_kodu',
                'tedarikci_adi',
                'siparis_tutari',
                'tedarikci_odeme_tutari',
                'siparis_durumu',
                'odeme_durumu',
            ]);

        // -------------------------------------------------------------
        // 5) Kurye Ödemeleri (vw_kurye_odeme_detay)
        //    Kolonlar:
        //    - islem_tarihi, dealer_id, bayi_adi
        //    - delivery_order_id, kaynak_order_id
        //    - kurye_id, kurye_adi
        //    - siparis_tutari, kurye_odeme_tutari
        //    - teslimat_durumu, odeme_durumu
        // -------------------------------------------------------------
        $couriers = DB::table('vw_kurye_odeme_detay')
            ->whereBetween('islem_tarihi', [$fromDate, $toDate])
            ->when($dealerId, function ($q) use ($dealerId, $dealerScopeIds, $deliverymanIds) {
                $q->where(function ($w) use ($dealerId, $dealerScopeIds, $deliverymanIds) {
                    if ($dealerScopeIds->isNotEmpty()) {
                        $w->whereIn('dealer_id', $dealerScopeIds->all());
                    } else {
                        $w->where('dealer_id', $dealerId);
                    }
                    if ($deliverymanIds->isNotEmpty()) {
                        $w->orWhereIn('kurye_id', $deliverymanIds->all());
                    }
                });
            })
            ->when($courierId, function ($q) use ($courierId) {
                // view'de kolon adı courier_id değil, kurye_id
                $q->where('kurye_id', $courierId);
            })
            ->orderBy('islem_tarihi')
            ->get([
                'islem_tarihi',
                'dealer_id',
                'bayi_adi',
                'delivery_order_id',
                'kaynak_order_id',
                'kurye_adi',
                'siparis_tutari',
                'kurye_odeme_tutari',
                'teslimat_durumu',
                'odeme_durumu',
            ]);

        // -------------------------------------------------------------
        // 6) Bayi Komisyonları (vw_bayi_komisyon_detay)
        //    Kolonlar:
        //    - islem_tarihi, dealer_id, bayi_adi
        //    - order_id, siparis_kodu
        //    - siparis_tutari, bayi_komisyon_tutari
        //    - siparis_durumu, odeme_durumu
        // -------------------------------------------------------------
        $vendors = DB::table('vw_bayi_komisyon_detay')
            ->whereBetween('islem_tarihi', [$fromDate, $toDate])
            ->when($dealerId, function ($q) use ($dealerId, $dealerScopeIds) {
                if ($dealerScopeIds->isNotEmpty()) {
                    $q->whereIn('dealer_id', $dealerScopeIds->all());
                    return;
                }
                $q->where('dealer_id', $dealerId);
            })
            ->orderBy('islem_tarihi')
            ->get([
                'islem_tarihi',
                'dealer_id',
                'bayi_adi',
                'order_id',
                'siparis_kodu',
                'bayi_adi',
                'siparis_tutari',
                'bayi_komisyon_tutari',
                'siparis_durumu',
                'odeme_durumu',
            ]);

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

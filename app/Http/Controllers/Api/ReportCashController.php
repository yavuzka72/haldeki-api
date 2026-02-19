<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportCashController extends Controller
{
    public function index(Request $request)
    {
        $from = $request->query('from'); // 2025-11-01
        $to   = $request->query('to');   // 2025-11-30
        $dealerId  = $request->query('dealer_id');   // bayi filtre
        $courierId = $request->query('courier_id');  // kurye filtre

        // Tarih filtresi için whereBetween gibi
        $daily = DB::table('vw_gunluk_kasa_ozet')
            ->when($from && $to, fn($q) =>
                $q->whereBetween('tarih', [$from, $to])
            )
            ->orderBy('tarih')
            ->get();

        $customers = DB::table('vw_musteri_tahsilat_detay')
            ->when($from && $to, fn($q) =>
                $q->whereBetween(DB::raw('DATE(islem_tarihi)'), [$from, $to])
            )
            ->when($dealerId, fn($q) =>
                $q->where('dealer_id', $dealerId) // view'de yoksa ekleyebilirsin
            )
            ->get();

        $suppliers = DB::table('vw_tedarikci_odeme_detay')
            ->when($from && $to, fn($q) =>
                $q->whereBetween(DB::raw('DATE(islem_tarihi)'), [$from, $to])
            )
            ->get();

        $couriers = DB::table('vw_kurye_odeme_detay')
            ->when($from && $to, fn($q) =>
                $q->whereBetween(DB::raw('DATE(islem_tarihi)'), [$from, $to])
            )
            ->when($courierId, fn($q) =>
                $q->where('kurye_id', $courierId)
            )
            ->get();

        $vendors = DB::table('vw_bayi_komisyon_detay')
            ->when($from && $to, fn($q) =>
                $q->whereBetween(DB::raw('DATE(islem_tarihi)'), [$from, $to])
            )
            ->when($dealerId, fn($q) =>
                $q->where('bayi_id', $dealerId)
            )
            ->get();

        return response()->json([
            'daily_cash'            => $daily,
            'customer_collections'  => $customers,
            'supplier_payments'     => $suppliers,
            'courier_payments'      => $couriers,
            'vendor_commissions'    => $vendors,
        ]);
    }
}

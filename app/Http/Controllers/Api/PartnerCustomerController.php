<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use App\Models\Order;

class PartnerCustomerController extends Controller
{
    /**
     * GET /partner/v1/customers
     * Query:
     *  - q (optional): name/phone araması
     *  - per_page (optional)
     */
    public function index(Request $request)
    {
        $partner = $request->attributes->get('partner');

        $per = (int) $request->get('per_page', 20);
        $q   = trim((string) $request->get('q', ''));

        // Orders tablosundaki kolonlara göre müşteri çıkarıyoruz:
        // customer_name, customer_phone, address (PartnerOrderController store bunu dolduruyor)
        $base = Order::query()
            ->where('partner_client_id', $partner->id);

        if ($q !== '') {
            $base->where(function ($w) use ($q) {
                $w->where('customer_name', 'like', "%{$q}%")
                  ->orWhere('customer_phone', 'like', "%{$q}%")
                  ->orWhere('address', 'like', "%{$q}%");
            });
        }

        // distinct customer_phone üzerinden gruplayıp "son sipariş" bilgisini veriyoruz
        $query = $base
            ->select([
                DB::raw('customer_phone as id'), // id gibi davranacak
                DB::raw('MAX(customer_name) as name'),
                DB::raw('customer_phone as phone'),
                DB::raw('MAX(address) as address'),
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('MAX(created_at) as last_order_at'),
            ])
            ->whereNotNull('customer_phone')
            ->groupBy('customer_phone')
            ->orderByDesc(DB::raw('MAX(created_at)'));

        // paginate için subquery tekniği
        $page = (int) $request->get('page', 1);
        $total = (clone $query)->get()->count();

        $rows = $query
            ->forPage($page, $per)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'data' => $rows,
                'current_page' => $page,
                'per_page' => $per,
                'total' => $total,
                'last_page' => (int) ceil($total / max($per, 1)),
            ],
        ]);
    }

    /**
     * GET /partner/v1/customers/{phone}
     */
    public function show(Request $request, $phone)
    {
        $partner = $request->attributes->get('partner');

        $row = Order::query()
            ->where('partner_client_id', $partner->id)
            ->where('customer_phone', $phone)
            ->select([
                DB::raw('customer_phone as id'),
                DB::raw('MAX(customer_name) as name'),
                DB::raw('customer_phone as phone'),
                DB::raw('MAX(address) as address'),
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('MAX(created_at) as last_order_at'),
            ])
            ->groupBy('customer_phone')
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $row,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;

class CouriersController extends Controller
{


    public function index(Request $request)
{
    $q = trim((string) $request->input('q', ''));

    // ✅ POST body’den al
    $resellerId = $request->input('reseller_id');

    if (!$resellerId) {
        return response()->json([
            'success' => false,
            'message' => 'reseller_id zorunludur'
        ], 422);
    }

    $query = User::query();

    // Kurye filtresi
    if (\Schema::hasColumn('users', 'user_type')) {
        $query->where('user_type', 'delivery_man');
    } elseif (\Schema::hasColumn('users', 'role')) {
        $query->where('role', 'courier');
    }

    // Aktif kuryeler
    if (\Schema::hasColumn('users', 'is_active')) {
        $query->where('is_active', 1);
    }

    // ✅ reseller_id artık body’den geliyor
    if (\Schema::hasColumn('users', 'reseller_id')) {
        $query->where('reseller_id', (int)$resellerId);
    }

    // Arama
    if ($q !== '') {
        $query->where(function ($w) use ($q) {
            $w->where('name', 'like', "%{$q}%")
              ->orWhere('phone', 'like', "%{$q}%")
              ->orWhere('email', 'like', "%{$q}%");
        });
    }

    $query->orderByDesc('id');

    $couriers = $query->limit(300)->get([
        'id',
        'name',
        'phone',
        'email',
    ]);

    return response()->json([
        'success' => true,
        'data' => $couriers,
    ]);
}

    public function indexold(Request $request)
    {
        $q = trim((string) $request->query('q', ''));


          $resellerId = $request->query('reseller_id');


        $query = User::query();

        // ✅ SEÇENEK A (yaygın): user_type = deliveryman
        // $query->where('user_type', 'deliveryman');

        // ✅ SEÇENEK B: role = courier
        // $query->where('role', 'courier');

        // ✅ SEÇENEK C: user_type numeric (örnek) => kendi sistemine göre değiştir
        // $query->where('user_type', 3);

        // ⚠️ Hangisi doğruysa SADECE onu bırak.
        // Şimdilik en güvenlisi: deliveryman alanı varsa onu kullan:
        if (\Schema::hasColumn('users', 'user_type')) {
            $query->where('user_type', 'delivery_man');
        } elseif (\Schema::hasColumn('users', 'role')) {
            $query->where('role', 'courier');
        }

        // Aktif kuryeler (alan varsa)
        if (\Schema::hasColumn('users', 'is_active')) {
            $query->where('is_active', 1);
        }

       if (\Schema::hasColumn('users', 'reseller_id')) {
        $query->where('reseller_id', (int)$resellerId);
    }



        // Arama
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")
                  ->orWhere('phone', 'like', "%{$q}%")
                  ->orWhere('email', 'like', "%{$q}%");
            });
        }

        // Sıralama: en yeni / isme göre
        $query->orderByDesc('id');

        $couriers = $query->limit(300)->get([
            'id',
            'name',
            'phone',
            'email',
            // varsa:
            // 'is_active',
            // 'city',
            // 'district',
        ]);

        // ✅ Flutter’ın beklediği format
        return response()->json([
            'success' => true,
            'data' => $couriers,
        ]);
    }
}

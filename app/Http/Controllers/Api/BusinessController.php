<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User; // İşletmeler "users" tablosunda client tipi ise

class BusinessController extends Controller
{
    /**
     * GET /api/v1/me/businesses
     * Bayinin sadece kendi dealer_id’sine bağlı işletmeleri (client) döndür.
     */
    public function myBusinesses(Request $request)
    {
        $dealerId = Auth::id(); // token sahibi bayi

        $query = User::query()
            ->where('user_type', 'client')      // senin mimarine göre
            ->where('dealer_id', $dealerId)
            ->select('id','name');         // ihtiyaç duyduğun alanları ekle

        // Opsiyonel arama
        if ($q = $request->get('q')) {
            $query->where('name', 'like', "%{$q}%");
        }

        return response()->json($query->orderBy('name')->get());
    }

    /**
     * (Opsiyonel) İşletme detay
     * GET /api/v1/businesses/{id}
     */
    public function show($id)
    {
        $dealerId = Auth::id();

        $biz = User::query()
            ->where('user_type', 'client')
            ->where('dealer_id', $dealerId)
            ->where('id', $id)
            ->firstOrFail(['id','name','email','contact_number']);

        return response()->json(['data' => $biz]);
    }
}

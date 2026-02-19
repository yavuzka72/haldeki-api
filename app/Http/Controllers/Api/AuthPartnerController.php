<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PartnerClient;
use Illuminate\Support\Str;

class AuthPartnerController extends Controller
{
    public function token(Request $request)
    {
        $data = $request->validate([
            'partner_key' => 'required|string',
            'partner_secret' => 'required|string',
        ]);

        $partner = PartnerClient::where('partner_key', $data['partner_key'])
            ->where('is_active', 1)
            ->first();

        if (!$partner || !hash_equals($partner->partner_secret, $data['partner_secret'])) {
            return response()->json(['success' => false, 'message' => 'Invalid credentials'], 401);
        }

        $partner->token = Str::random(60);
        $partner->token_expires_at = now()->addHours(12);
        $partner->save();

        return response()->json([
            'success' => true,
            'token_type' => 'Bearer',
            'access_token' => $partner->token,
            'expires_at' => $partner->token_expires_at,
            'partner' => ['id' => $partner->id, 'name' => $partner->name],
        ]);
    }
}

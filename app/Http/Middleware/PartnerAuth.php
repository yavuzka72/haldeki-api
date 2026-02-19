<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\PartnerClient;

class PartnerAuth
{
    public function handle(Request $request, Closure $next)
    {
        // 1) Key/Secret ZORUNLU
        $key    = $request->header('X-Partner-Key');
        $secret = $request->header('X-Partner-Secret');

        if (!$key || !$secret) {
            return response()->json([
                'success' => false,
                'message' => 'Partner key/secret missing',
                'hint' => 'Send X-Partner-Key and X-Partner-Secret headers'
            ], 401);
        }

        // 2) Token ZORUNLU
        $auth = $request->header('Authorization');
        if (!$auth || !str_starts_with($auth, 'Bearer ')) {
            return response()->json([
                'success' => false,
                'message' => 'Partner token missing',
                'hint' => 'Send Authorization: Bearer <token>'
            ], 401);
        }

        $token = trim(substr($auth, 7));

        // 3) ÜÇÜ BİRLİKTE eşleşmeli
        $partner = PartnerClient::where('partner_key', $key)
            ->where('partner_secret', $secret)
            ->where('token', $token)
            ->where('is_active', 1)
            ->first();

        if (!$partner) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid partner credentials or token'
            ], 401);
        }

        // 4) Token süresi kontrolü
        if ($partner->token_expires_at && now()->greaterThan($partner->token_expires_at)) {
            return response()->json([
                'success' => false,
                'message' => 'Partner token expired'
            ], 401);
        }

        $request->attributes->set('partner', $partner);
        return $next($request);
    }
}

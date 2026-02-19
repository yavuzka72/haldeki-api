<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PartnerPriceSyncController
{
    /**
     * GET /api/partner/price-changes?since_id=123&limit=500
     * - partner_client_id token'dan gelir (request'ten değil)
     */
    public function changes(Request $request)
    {
        $request->validate([
            'since_id' => ['nullable', 'integer', 'min:0'],
            'limit'    => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $user = $request->user();
        $partnerClientId = (int)($user->partner_client_id ?? 0);

        if ($partnerClientId <= 0) {
            return response()->json([
                'message' => 'User has no partner_client_id'
            ], 403);
        }

        $sinceId = (int)($request->get('since_id', 0));
        $limit = (int)($request->get('limit', 500));

        // ✅ Partner'a ait değişiklikleri getir
        $rows = DB::table('price_logs')
            ->select([
                'id',
                'product_id',
                'product_variant_id',
                'old_price',
                'new_price',
                'old_active',
                'new_active',
                'changed_by_user_id',
                'created_at',
            ])
            ->where('partner_client_id', $partnerClientId)
            ->where('id', '>', $sinceId)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $nextSinceId = $rows->isEmpty() ? $sinceId : (int)$rows->last()->id;

        return response()->json([
            'partner_client_id' => $partnerClientId,
            'since_id'          => $sinceId,
            'next_since_id'     => $nextSinceId,
            'count'             => $rows->count(),
            'changes'           => $rows,
        ]);
    }

    /**
     * GET /api/partner/price-sync-state
     * - Sunucuda saklanan cursor'u döner (opsiyonel)
     */
    public function state(Request $request)
    {
        $user = $request->user();
        $partnerClientId = (int)($user->partner_client_id ?? 0);

        if ($partnerClientId <= 0) {
            return response()->json(['message' => 'User has no partner_client_id'], 403);
        }

        $state = DB::table('partner_sync_states')
            ->where('partner_client_id', $partnerClientId)
            ->first();

        return response()->json([
            'partner_client_id'  => $partnerClientId,
            'last_price_log_id'  => (int)($state->last_price_log_id ?? 0),
        ]);
    }

    /**
     * POST /api/partner/price-sync-ack
     * Body: { "last_price_log_id": 1412 }
     * - Dış platform "ben 1412'ye kadar işledim" der.
     */
    public function ack(Request $request)
    {
        $request->validate([
            'last_price_log_id' => ['required', 'integer', 'min:0'],
        ]);

        $user = $request->user();
        $partnerClientId = (int)($user->partner_client_id ?? 0);

        if ($partnerClientId <= 0) {
            return response()->json(['message' => 'User has no partner_client_id'], 403);
        }

        $last = (int)$request->integer('last_price_log_id');

        // Güvenlik: partner'ın ilerlettiği id, gerçekten bu partner'a ait loglarda var mı?
        // (İsteğe bağlı; performans için kapatılabilir)
        if ($last > 0) {
            $exists = DB::table('price_logs')
                ->where('partner_client_id', $partnerClientId)
                ->where('id', $last)
                ->exists();

            if (!$exists) {
                // Partner "olmayan" bir id'yi ack edemez
                return response()->json(['message' => 'Invalid last_price_log_id for this partner'], 422);
            }
        }

        // Upsert
        DB::table('partner_sync_states')->updateOrInsert(
            ['partner_client_id' => $partnerClientId],
            ['last_price_log_id' => $last, 'updated_at' => now(), 'created_at' => now()]
        );

        return response()->json([
            'ok' => true,
            'partner_client_id' => $partnerClientId,
            'last_price_log_id' => $last,
        ]);
    }
}

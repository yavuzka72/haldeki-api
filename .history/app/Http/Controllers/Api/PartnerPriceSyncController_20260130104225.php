<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PartnerPriceSyncController
{
    /**
     * 3 header ile partner doğrula:
     * - Authorization: Bearer <api_token>
     * - X-Partner-Key
     * - X-Partner-Secret
     *
     * partner_clients tablosundan partner'i bulur.
     */
    private function resolvePartner(Request $request): object
    {
        $key = (string) $request->header('X-Partner-Key', '');
        $secret = (string) $request->header('X-Partner-Secret', '');

        $auth = (string) $request->header('Authorization', '');
        $bearer = '';
        if (preg_match('/^Bearer\s+(.*)$/i', $auth, $m)) {
            $bearer = trim($m[1] ?? '');
        }

        if ($key === '' || $secret === '' || $bearer === '') {
            abort(response()->json([
                'message' => 'Missing Authorization Bearer / X-Partner-Key / X-Partner-Secret'
            ], 401));
        }

        // partner_clients: partner_key ile bul
        $partner = DB::table('partner_clients')
            ->select(['id', 'partner_secret', 'token', 'is_active'])
            ->where('partner_key', $key)
            ->first();

        if (!$partner) {
            abort(response()->json(['message' => 'Invalid partner key'], 401));
        }

        if (isset($partner->is_active) && (int)$partner->is_active !== 1) {
            abort(response()->json(['message' => 'Partner is inactive'], 403));
        }

        // Secret kontrol
        if (!hash_equals((string)$partner->partner_secret, $secret)) {
            abort(response()->json(['message' => 'Invalid partner secret'], 401));
        }

        // Bearer token kontrol (api_token düz metinse)
        if (!hash_equals((string)$partner->api_token, $bearer)) {
            abort(response()->json(['message' => 'Invalid bearer token'], 401));
        }

        return $partner; // partner->id = partner_client_id
    }

    public function changes(Request $request)
    {
        $partner = $this->resolvePartner($request);
        $partnerClientId = (int) $partner->id;

        $request->validate([
            'since_id' => ['nullable', 'integer', 'min:0'],
            'limit'    => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $sinceId = (int) $request->get('since_id', 0);
        $limit   = (int) $request->get('limit', 500);

        $rows = DB::table('price_logs')
            ->select([
                'id',
                'partner_client_id',
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

        $nextSinceId = $rows->isEmpty() ? $sinceId : (int) $rows->last()->id;

        return response()->json([
            'partner_client_id' => $partnerClientId,
            'since_id'          => $sinceId,
            'next_since_id'     => $nextSinceId,
            'count'             => $rows->count(),
            'changes'           => $rows,
        ]);
    }

    public function state(Request $request)
    {
        $partner = $this->resolvePartner($request);
        $partnerClientId = (int) $partner->id;

        $state = DB::table('partner_sync_states')
            ->where('partner_client_id', $partnerClientId)
            ->first();

        return response()->json([
            'partner_client_id' => $partnerClientId,
            'last_price_log_id' => (int) ($state->last_price_log_id ?? 0),
        ]);
    }

    public function ack(Request $request)
    {
        $partner = $this->resolvePartner($request);
        $partnerClientId = (int) $partner->id;

        $request->validate([
            'last_price_log_id' => ['required', 'integer', 'min:0'],
        ]);

        $last = (int) $request->integer('last_price_log_id');

        if ($last > 0) {
            $exists = DB::table('price_logs')
                ->where('partner_client_id', $partnerClientId)
                ->where('id', $last)
                ->exists();

            if (!$exists) {
                return response()->json(['message' => 'Invalid last_price_log_id for this partner'], 422);
            }
        }

        // MySQL upsert
        DB::statement(
            "INSERT INTO partner_sync_states (partner_client_id, last_price_log_id, created_at, updated_at)
             VALUES (?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE last_price_log_id = VALUES(last_price_log_id), updated_at = NOW()",
            [$partnerClientId, $last]
        );

        return response()->json([
            'ok' => true,
            'partner_client_id' => $partnerClientId,
            'last_price_log_id' => $last,
        ]);
    }
}

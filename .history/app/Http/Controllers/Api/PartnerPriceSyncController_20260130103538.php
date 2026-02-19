<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PartnerPriceSyncController
{
    /**
     * Partner doğrulama:
     * - Authorization Bearer: user doğrular (auth middleware)
     * - X-Partner-Key + X-Partner-Secret: partner_client_id bulur ve doğrular
     */
    private function resolvePartner(Request $request): array
    {
        $key = (string) $request->header('X-Partner-Key', '');
        $secret = (string) $request->header('X-Partner-Secret', '');

        if ($key === '' || $secret === '') {
            abort(response()->json([
                'message' => 'Missing X-Partner-Key or X-Partner-Secret'
            ], 401));
        }

        // ✅ Burada kendi tablonu kullan:
        // ÖNERİ: partner_integrations
        // columns: partner_key, partner_secret, partner_client_id, is_active
        $row = DB::table('partner_clients')
            ->select(['partner_client_id', 'partner_secret', 'is_active'])
            ->where('partner_key', $key)
            ->first();

        if (!$row) {
            abort(response()->json(['message' => 'Invalid partner key'], 401));
        }

        if (isset($row->is_active) && (int)$row->is_active !== 1) {
            abort(response()->json(['message' => 'Partner integration is inactive'], 403));
        }

        // secret eşleşmesi (düz metin saklıyorsan)
        // Eğer secret hashed saklanıyorsa burada Hash::check kullanman gerekir.
        if (!hash_equals((string)$row->partner_secret, $secret)) {
            abort(response()->json(['message' => 'Invalid partner secret'], 401));
        }

        $partnerClientId = (int)($row->partner_client_id ?? 0);
        if ($partnerClientId <= 0) {
            abort(response()->json(['message' => 'Partner has no partner_client_id'], 403));
        }

        return [$partnerClientId, $key];
    }

    /**
     * (Opsiyonel) user bu partner_client_id’ye yetkili mi?
     * Senin sisteminde user->partner_client_id var diyorsan bunu aktif et.
     * Yoksa kapat (true döndür).
     */
    private function assertUserAuthorizedForPartner(Request $request, int $partnerClientId): void
    {
        $user = $request->user();
        if (!$user) {
            abort(response()->json(['message' => 'Unauthenticated'], 401));
        }

        // Eğer sende user->partner_client_id doluysa:
        if (isset($user->partner_client_id) && (int)$user->partner_client_id > 0) {
            if ((int)$user->partner_client_id !== $partnerClientId) {
                abort(response()->json(['message' => 'User not authorized for this partner'], 403));
            }
        }
        // Eğer user->partner_client_id yoksa, burada rol kontrolü koyabilirsin.
    }

    /**
     * GET /api/partner/v1/price-changes?since_id=123&limit=500
     */
    public function changes(Request $request)
    {
        $request->validate([
            'since_id' => ['nullable', 'integer', 'min:0'],
            'limit'    => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        [$partnerClientId] = $this->resolvePartner($request);
        $this->assertUserAuthorizedForPartner($request, $partnerClientId);

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

    /**
     * GET /api/partner/v1/price-sync-state
     */
    public function state(Request $request)
    {
        [$partnerClientId] = $this->resolvePartner($request);
        $this->assertUserAuthorizedForPartner($request, $partnerClientId);

        $state = DB::table('partner_sync_states')
            ->where('partner_client_id', $partnerClientId)
            ->first();

        return response()->json([
            'partner_client_id' => $partnerClientId,
            'last_price_log_id' => (int) ($state->last_price_log_id ?? 0),
        ]);
    }

    /**
     * POST /api/partner/v1/price-sync-ack
     * Body: { "last_price_log_id": 1412 }
     */
    public function ack(Request $request)
    {
        $request->validate([
            'last_price_log_id' => ['required', 'integer', 'min:0'],
        ]);

        [$partnerClientId] = $this->resolvePartner($request);
        $this->assertUserAuthorizedForPartner($request, $partnerClientId);

        $last = (int) $request->integer('last_price_log_id');

        // İsteğe bağlı güvenlik kontrolü
        if ($last > 0) {
            $exists = DB::table('price_logs')
                ->where('partner_client_id', $partnerClientId)
                ->where('id', $last)
                ->exists();

            if (!$exists) {
                return response()->json(['message' => 'Invalid last_price_log_id for this partner'], 422);
            }
        }

        // ✅ Upsert (created_at'ı her seferinde basmamak için daha temiz yaklaşım)
        $existsState = DB::table('partner_sync_states')
            ->where('partner_client_id', $partnerClientId)
            ->exists();

        if ($existsState) {
            DB::table('partner_sync_states')
                ->where('partner_client_id', $partnerClientId)
                ->update([
                    'last_price_log_id' => $last,
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('partner_sync_states')->insert([
                'partner_client_id' => $partnerClientId,
                'last_price_log_id' => $last,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'ok' => true,
            'partner_client_id' => $partnerClientId,
            'last_price_log_id' => $last,
        ]);
    }
}

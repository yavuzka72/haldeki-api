<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class PartnerPriceSyncController extends Controller
{
    /**
     * Partner doğrulama:
     * - Authorization: Bearer <token>
     * - X-Partner-Key
     * - X-Partner-Secret
     *
     * partner_clients tablosundan partner'i bulur.
     * HATA durumlarında daima JSON döner.
     */
    private function resolvePartner(Request $request)
    {
        $key    = (string) $request->header('X-Partner-Key', '');
        $secret = (string) $request->header('X-Partner-Secret', '');

        $auth = (string) $request->header('Authorization', '');
        $bearer = '';
        if (preg_match('/^Bearer\s+(.*)$/i', $auth, $m)) {
            $bearer = trim($m[1] ?? '');
        }

        if ($key === '' || $secret === '' || $bearer === '') {
            return response()->json([
                'message' => 'Missing Authorization Bearer / X-Partner-Key / X-Partner-Secret'
            ], Response::HTTP_UNAUTHORIZED);
        }

        $partner = DB::table('partner_clients')
            ->select(['id', 'partner_secret', 'token', 'is_active'])
            ->where('partner_key', $key)
            ->first();

        if (!$partner) {
            return response()->json(['message' => 'Invalid partner key'], Response::HTTP_UNAUTHORIZED);
        }

        if (isset($partner->is_active) && (int) $partner->is_active !== 1) {
            return response()->json(['message' => 'Partner is inactive'], Response::HTTP_FORBIDDEN);
        }

        // Secret kontrol
        if (!hash_equals((string) $partner->partner_secret, $secret)) {
            return response()->json(['message' => 'Invalid partner secret'], Response::HTTP_UNAUTHORIZED);
        }

        // Bearer token kontrol (token düz metinse)
        if (!hash_equals((string) $partner->token, $bearer)) {
            return response()->json(['message' => 'Invalid bearer token'], Response::HTTP_UNAUTHORIZED);
        }

        // OK => controller metodlarında partner objesi kullanılacak
        return $partner; // ->id = partner_client_id
    }

    /**
     * Partner'in mevcut sync state'ini döner.
     * last_price_log_id yoksa 0 döner.
     */
    public function state(Request $request)
    {
        $partner = $this->resolvePartner($request);
        if ($partner instanceof \Illuminate\Http\JsonResponse) return $partner;

        $partnerClientId = (int) $partner->id;

        $last = (int) DB::table('partner_sync_states')
            ->where('partner_client_id', $partnerClientId)
            ->value('last_price_log_id');

        return response()->json([
            'partner_client_id' => $partnerClientId,
            'last_price_log_id' => $last,
        ]);
    }

    /**
     * Partner, "ben şu ID'ye kadar işledim" diye bildirir.
     * - last_price_log_id price_logs içinde o partner için var mı? (0 hariç)
     * - partner_sync_states tablosuna upsert yapılır.
     */
    public function ack(Request $request)
    {
        $partner = $this->resolvePartner($request);
        if ($partner instanceof \Illuminate\Http\JsonResponse) return $partner;

        $partnerClientId = (int) $partner->id;

        $validated = $request->validate([
            'last_price_log_id' => ['required', 'integer', 'min:0'],
        ]);

        $last = (int) $validated['last_price_log_id'];

        // 0 değilse, bu partner için price_logs'ta gerçekten var olmalı
        if ($last > 0) {
            $exists = DB::table('price_logs')
                ->where('partner_client_id', $partnerClientId)
                ->where('id', $last)
                ->exists();

            if (!$exists) {
                return response()->json([
                    'message' => 'Invalid last_price_log_id for this partner'
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        // (Opsiyonel ama önerilir) geri alma engeli:
        $current = (int) DB::table('partner_sync_states')
            ->where('partner_client_id', $partnerClientId)
            ->value('last_price_log_id');

        if ($current > 0 && $last < $current) {
            return response()->json([
                'message' => 'last_price_log_id cannot go backwards',
                'current_last_price_log_id' => $current,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // MySQL UPSERT (UNIQUE partner_client_id şart!)
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

    /**
     * Partner'in "since_id" sonrasındaki fiyat değişikliklerini döner.
     * where id > since_id
     * next_since_id = son dönen kaydın id'si (yoksa since_id)
     */
    public function changes(Request $request)
    {
        $partner = $this->resolvePartner($request);
        if ($partner instanceof \Illuminate\Http\JsonResponse) return $partner;

        $partnerClientId = (int) $partner->id;

        $validated = $request->validate([
            'since_id' => ['nullable', 'integer', 'min:0'],
            'limit'    => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $sinceId = (int) ($validated['since_id'] ?? 0);
        $limit   = (int) ($validated['limit'] ?? 500);

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
}

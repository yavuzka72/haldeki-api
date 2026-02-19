<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\UserProductPrice;

class PartnerCatalogController extends Controller
{


public function index(Request $request)
{
    // --- Partner Auth ---
    $key    = (string) $request->header('X-Partner-Key', '');
    $secret = (string) $request->header('X-Partner-Secret', '');

    if ($key === '' || $secret === '') {
        return response()->json([
            'message' => 'Missing partner credentials. Use X-Partner-Key and X-Partner-Secret.',
        ], 401);
    }

    $partner = DB::table('partner_clients')
        ->where('partner_key', $key)
        ->where('partner_secret', $secret)
        ->where('is_active', 1)
        ->first();

    if (!$partner) {
        return response()->json(['message' => 'Invalid partner credentials.'], 401);
    }

    $partnerId = (int) $partner->id;

    // --- Paging ---
    $page    = max(1, (int) $request->query('page', 1));
    $perPage = min(200, max(10, (int) $request->query('per_page', 50)));
    $onlyActive = (int) $request->query('only_active', 1);

    // =========================================================
    // 1) Önce partner'a ait varyantların product_id listesini bul
    // =========================================================
    $variantProductIdsQ = DB::table('product_variants as v')
        ->where('v.partner_client_id', $partnerId);

    if ($onlyActive === 1) {
        $variantProductIdsQ->where('v.active', 1);
    }

    $allowedProductIds = $variantProductIdsQ
        ->distinct()
        ->pluck('v.product_id')
        ->all();

    if (empty($allowedProductIds)) {
        return response()->json([
            'partner' => ['id' => $partnerId, 'name' => $partner->name ?? null],
            'data' => [],
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'has_more' => false],
        ]);
    }

    // =========================
    // 2) Products (sadece allowed)
    // =========================
    $productsQ = DB::table('products as p')
        ->select(['p.id', 'p.name', 'p.image', 'p.description', 'p.active', 'p.updated_at'])
        ->whereIn('p.id', $allowedProductIds);

    if ($onlyActive === 1) {
        $productsQ->where('p.active', 1);
    }

    $total = (clone $productsQ)->count();

    $products = $productsQ
        ->orderBy('p.id')
        ->forPage($page, $perPage)
        ->get();

    $productIds = $products->pluck('id')->all();
    if (empty($productIds)) {
        return response()->json([
            'partner' => ['id' => $partnerId, 'name' => $partner->name ?? null],
            'data' => [],
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'has_more' => false],
        ]);
    }

    // --- Categories ---
    $catRows = DB::table('category_product as cp')
        ->join('categories as c', 'c.id', '=', 'cp.category_id')
        ->whereIn('cp.product_id', $productIds)
        ->select(['cp.product_id', 'c.id as category_id', 'c.name as category_name'])
        ->get();

    $catsByProduct = [];
    foreach ($catRows as $r) {
        $catsByProduct[$r->product_id][] = [
            'id' => (int) $r->category_id,
            'name' => $r->category_name,
        ];
    }

    // =========================
    // 3) Variants (ASIL FİLTRE)
    // =========================
    $variants = DB::table('product_variants as v')
        ->whereIn('v.product_id', $productIds)
        ->where('v.partner_client_id', $partnerId)   // ✅ sadece partner'ın varyantları
        ->when($onlyActive === 1, fn($q) => $q->where('v.active', 1))
        ->select([
            'v.id',
            'v.product_id',
            'v.partner_client_id',
            'v.name',
            'v.sku',
            'v.unit',
            'v.multiplier',
            'v.active',
            'v.updated_at',
        ])
        ->orderBy('v.product_id')
        ->orderBy('v.id')
        ->get();

    $variantIds = $variants->pluck('id')->all();

    $variantsByProduct = [];
    foreach ($variants as $v) {
        $variantsByProduct[$v->product_id][] = [
            'id' => (int) $v->id,
            'product_id' => (int) $v->product_id,
            'partner_client_id' => (int) $v->partner_client_id, // ✅ response’da gör
            'name' => $v->name,
            'sku' => $v->sku,
            'unit' => $v->unit,
            'multiplier' => (float) ($v->multiplier ?? 1),
            'active' => (int) ($v->active ?? 1),
            'updated_at' => $v->updated_at,
        ];
    }

    // =========================
    // 4) Prices (partner override)
    // =========================
    $priceByVariant = [];

    if (!empty($variantIds)) {
        $overrideRows = DB::table('user_product_prices as upp')
            ->where('upp.active', 1)
        //    ->where('upp.partner_client_id', $partnerId) // ✅ partner’a özel fiyat
            ->whereIn('upp.product_variant_id', $variantIds)
            ->select(['upp.product_variant_id', 'upp.price', 'upp.updated_at'])
            ->get();

        foreach ($overrideRows as $r) {
            $priceByVariant[(int) $r->product_variant_id] = [
                'price' => (float) $r->price,
                'source' => 'partner_override',
                'updated_at' => $r->updated_at,
            ];
        }
    }

    // --- Response assemble ---
    $data = [];
    foreach ($products as $p) {
        $pVariants = $variantsByProduct[$p->id] ?? [];

        // ürünün partner varyantı yoksa hiç gönderme (opsiyonel ama mantıklı)
        if (empty($pVariants)) {
            continue;
        }

        foreach ($pVariants as &$vv) {
            $vv['price'] = $priceByVariant[$vv['id']] ?? null;
        }
        unset($vv);

        $data[] = [
            'product' => [
                'id' => (int) $p->id,
                'name' => $p->name,
                'image' => $p->image,
                'description' => $p->description,
                'active' => (int) $p->active,
                'updated_at' => $p->updated_at,
                'categories' => $catsByProduct[$p->id] ?? [],
            ],
            'variants' => $pVariants,
        ];
    }

    return response()->json([
        'dealer_id' => $partner->dealer_id ?? null,
        'partner' => [
            'id' => $partnerId,
            'name' => $partner->name ?? null,
        ],
        'data' => $data,
        'meta' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'has_more' => ($page * $perPage) < $total,
        ],
    ]);
}


public function indexallprice(Request $request)
    {
        // --- Partner Auth ---
        $key    = (string) $request->header('X-Partner-Key', '');
        $secret = (string) $request->header('X-Partner-Secret', '');

        if ($key === '' || $secret === '') {
            return response()->json([
                'message' => 'Missing partner credentials. Use X-Partner-Key and X-Partner-Secret.',
            ], 401);
        }

        $partner = DB::table('partner_clients')
               ->where('partner_key', $key)
            ->where('partner_secret', $secret)
            ->where('is_active', 1)
            ->first();

        if (!$partner) {
            return response()->json(['message' => 'Invalid partner credentials.'], 401);
        }

        // Partner hangi kullanıcının fiyatlarını görecek?
         $ownerUserId = (int) ($partner->id ?? 0);
    /*    if ($ownerUserId <= 0) {
            return response()->json([
                'message' => 'Partner is missing user_id mapping (partner_clients.id).',
            ], 422);
        }
        */

        // --- Paging ---
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = min(200, max(10, (int) $request->query('per_page', 50)));

        $onlyActive = (int) $request->query('only_active', 1);

        // --- Products ---
        $productsQ = DB::table('products as p')
            ->select([
                'p.id', 'p.name',  'p.image', 'p.description', 'p.active', 'p.updated_at',
            ]);

        if ($onlyActive === 1) {
            $productsQ->where('p.active', 1);
        }

        $total = (clone $productsQ)->count();

        $products = $productsQ
            ->orderBy('p.id')
            ->forPage($page, $perPage)
            ->get();

        $productIds = $products->pluck('id')->all();
        if (empty($productIds)) {
            return response()->json([
                'partner' => ['id' => (int) $partner->id, 'name' => $partner->name ?? null],
                'data' => [],
                'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
            ]);
        }

        // --- Categories (senin dump: categories + category_product) ---
        $catRows = DB::table('category_product as cp')
            ->join('categories as c', 'c.id', '=', 'cp.category_id')
            ->whereIn('cp.product_id', $productIds)
            ->select([
                'cp.product_id',
                'c.id as category_id',
                'c.name as category_name',
          
            ])
            ->get();

        $catsByProduct = [];
        foreach ($catRows as $r) {
            $catsByProduct[$r->product_id][] = [
                'id' => (int) $r->category_id,
                'name' => $r->category_name,
              
            ];
        }

        // --- Variants ---
        $variants = DB::table('product_variants as v')
            ->whereIn('v.product_id', $productIds)
            ->select([
                'v.id', 'v.product_id', 'v.name', 'v.sku', 'v.unit', 'v.multiplier', 'v.active', 'v.updated_at',
            ])
            ->orderBy('v.product_id')
            ->orderBy('v.id')
            ->get();

        $variantIds = $variants->pluck('id')->all();

        $variantsByProduct = [];
        foreach ($variants as $v) {
            $variantsByProduct[$v->product_id][] = [
                'id'         => (int) $v->id,
                'name'       => $v->name,
                'sku'        => $v->sku,
                'unit'       => $v->unit,
                'multiplier' => (float) ($v->multiplier ?? 1),
                'active'     => (int) ($v->active ?? 1),
                'updated_at' => $v->updated_at,
            ];
        }

        // --- Prices ---
        // Öncelik kuralı:
        // 1) partner_client_id = partner->id (override varsa)
        // 2) yoksa partner->user_id fiyatı
        $priceByVariant = [];

        if (!empty($variantIds)) {
            // Override fiyatlar
            $overrideRows = DB::table('user_product_prices as upp')
                ->where('upp.active', 1)
         //       ->where('upp.partner_client_id', $partner->id)
                ->whereIn('upp.product_variant_id', $variantIds)
                ->select(['upp.product_variant_id', 'upp.price', 'upp.active', 'upp.updated_at'])
                ->get();

            foreach ($overrideRows as $r) {
                $priceByVariant[(int) $r->product_variant_id] = [
                    'price' => (float) $r->price,
                    'source' => 'partner_override',
                    'updated_at' => $r->updated_at,
                ];
            }

            // User fiyatları (override olmayanlara fallback)
            $userRows = DB::table('user_product_prices as upp')
                ->where('upp.active', 1)
                ->whereNull('upp.partner_client_id') // senin dump'ta genelde NULL
                ->where('upp.user_id', $ownerUserId)
                ->whereIn('upp.product_variant_id', $variantIds)
                ->select(['upp.product_variant_id', 'upp.price', 'upp.active', 'upp.updated_at'])
                ->get();

            foreach ($userRows as $r) {
                $vid = (int) $r->product_variant_id;
                if (!isset($priceByVariant[$vid])) {
                    $priceByVariant[$vid] = [
                        'price' => (float) $r->price,
                        'source' => 'user_price',
                        'updated_at' => $r->updated_at,
                    ];
                }
            }
        }

        // --- Response assemble ---
        $data = [];
        foreach ($products as $p) {
            $pVariants = $variantsByProduct[$p->id] ?? [];

            foreach ($pVariants as &$vv) {
                $vv['price'] = $priceByVariant[$vv['id']] ?? null;
            }
            unset($vv);

            $data[] = [
                'product' => [
                    'id'          => (int) $p->id,
                    'name'        => $p->name,
              //     'slug'        => $p->slug,
                    'image'       => $p->image,
                    'description' => $p->description,
                    'active'   => (int) $p->active,
                    'updated_at'  => $p->updated_at,
                    'categories'  => $catsByProduct[$p->id] ?? [],
                ],
                'variants' => $pVariants,
            ];
        }

        return response()->json([
            'partner' => [
                'id' => (int) $partner->id,
                'name' => $partner->name ?? null,
                'user_id' => $ownerUserId,
            ],
            'data' => $data,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => ($page * $perPage) < $total,
            ],
        ]);
    }

public function buildPriceList(Request $request)
{
    // --- Partner Auth ---
    $key    = (string) $request->header('X-Partner-Key', '');
    $secret = (string) $request->header('X-Partner-Secret', '');
    $auth   = (string) $request->header('Authorization', '');

    if ($key === '' || $secret === '') {
        return response()->json(['message' => 'Missing X-Partner-Key / X-Partner-Secret'], 401);
    }

    $token = '';
    if (stripos($auth, 'Bearer ') === 0) {
        $token = trim(substr($auth, 7));
    }
    if ($token === '') {
        return response()->json(['message' => 'Missing Bearer token'], 401);
    }

    $partner = DB::table('partner_clients')
        ->where('partner_key', $key)
        ->where('partner_secret', $secret)
        ->where('token', $token)   // sende alan adı farklıysa değiştir
        ->where('is_active', 1)
        ->first();

    if (!$partner) {
        return response()->json(['message' => 'Invalid partner credentials'], 401);
    }

    $partnerClientId = (int) $partner->id;

    // --- Payload ---
    $oran  = (float) $request->input('multiplier', 1.20);
    $round = (int) $request->input('round', 2);

    if ($oran <= 0) {
        return response()->json(['message' => 'multiplier invalid'], 422);
    }

    // ✅ ZORUNLU: base fiyat hangi user_id’deyse o olmalı (senin örnekte 18)
    $supplierUserId =  18;//(int) ($partner->supplier_user_id ?? 0);
    if ($supplierUserId <= 0) {
        return response()->json([
            'message' => 'partner_clients.supplier_user_id boş. Base fiyatları hangi user tutuyorsa onu yaz (örn: 18).'
        ], 422);
    }

    return DB::transaction(function () use ($partnerClientId, $oran, $round, $supplierUserId) {

        // 1) BASE (partner_client_id IS NULL) → PARTNER VARIANT INSERT (yoksa)
        $variantsInserted = DB::affectingStatement("
            INSERT INTO product_variants
            (
                partner_client_id,
                product_id,
                name,
                sku,
                unit,
                multiplier,
                active,
                created_at,
                updated_at
            )
            SELECT
                ?,
                v.product_id,
                v.name,
                v.sku,
                v.unit,
                ROUND(v.multiplier * ?, 3),
                1,
                NOW(),
                NOW()
            FROM product_variants v
            WHERE v.partner_client_id IS NULL
              AND NOT EXISTS (
                SELECT 1
                FROM product_variants pv
                WHERE pv.partner_client_id = ?
                  AND pv.product_id = v.product_id
                  AND pv.sku = v.sku
              )
        ", [$partnerClientId, $oran, $partnerClientId]);

        // 2) PARTNER VARIANT multiplier update (base’e göre)
        $variantsUpdated = DB::affectingStatement("
            UPDATE product_variants pv
            JOIN product_variants bv
              ON bv.partner_client_id IS NULL
             AND bv.product_id = pv.product_id
             AND bv.sku = pv.sku
            SET pv.multiplier = ROUND(bv.multiplier * ?, 3),
                pv.updated_at = NOW()
            WHERE pv.partner_client_id = ?
        ", [$oran, $partnerClientId]);

        // 3) VARSA partner fiyatları güncelle (base price * oran)
        $pricesUpdated = DB::affectingStatement("
            UPDATE user_product_prices pp
            JOIN product_variants pv
              ON pv.id = pp.product_variant_id
             AND pv.partner_client_id = ?
            JOIN product_variants bv
              ON bv.partner_client_id IS NULL
             AND bv.product_id = pv.product_id
             AND bv.sku = pv.sku
            JOIN user_product_prices bp
              ON bp.product_variant_id = bv.id
             AND bp.user_id = ?
             AND bp.partner_client_id IS NULL
            SET pp.price = ROUND(bp.price * ?, ?),
                pp.updated_at = NOW()
            WHERE pp.partner_client_id = ?
              AND pp.user_id = ?
        ", [$partnerClientId, $supplierUserId, $oran, $round, $partnerClientId, $supplierUserId]);

        // 4) EKSİK partner fiyatlarını insert et
        $pricesInserted = DB::affectingStatement("
            INSERT INTO user_product_prices
            (
                partner_client_id,
                user_id,
                product_variant_id,
                price,
                active,
                created_at,
                updated_at
            )
            SELECT
                ?,
                ?,
                pv.id,
                ROUND(bp.price * ?, ?),
                1,
                NOW(),
                NOW()
            FROM product_variants pv
            JOIN product_variants bv
              ON bv.partner_client_id IS NULL
             AND bv.product_id = pv.product_id
             AND bv.sku = pv.sku
            JOIN user_product_prices bp
              ON bp.product_variant_id = bv.id
             AND bp.user_id = ?
             AND bp.partner_client_id IS NULL
            LEFT JOIN user_product_prices pp
              ON pp.product_variant_id = pv.id
             AND pp.partner_client_id = ?
             AND pp.user_id = ?
            WHERE pv.partner_client_id = ?
              AND pp.id IS NULL
        ", [$partnerClientId, $supplierUserId, $oran, $round, $supplierUserId, $partnerClientId, $supplierUserId, $partnerClientId]);

        return response()->json([
            'ok' => true,
            'partner_client_id' => $partnerClientId,
            'supplier_user_id' => $supplierUserId,
            'multiplier' => $oran,
            'round' => $round,
            'variants_inserted' => $variantsInserted,
            'variants_updated' => $variantsUpdated,
            'prices_updated' => $pricesUpdated,
            'prices_inserted' => $pricesInserted,
        ]);
    });
}


  public function indexw(Request $request)
    {
        // ---- Partner Auth (Header) ----
        // İstersen Authorization: Bearer yerine burada key/secret ile gidiyoruz.
        $key    = (string) $request->header('X-Partner-Key', '');
        $secret = (string) $request->header('X-Partner-Secret', '');

        if ($key === '' || $secret === '') {
            return response()->json([
                'message' => 'Missing partner credentials. Use X-Partner-Key and X-Partner-Secret.',
            ], 401);
        }

        $partner = DB::table('partner_clients')
            ->where('partner_key', $key)
            ->where('partner_secret', $secret)
            ->where('is_active', 1)
            ->first();

        if (!$partner) {
            return response()->json([
                'message' => 'Invalid partner credentials.',
            ], 401);
        }

        // ---- Filters ----
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = min(200, max(10, (int) $request->query('per_page', 50)));

        // Sadece güncellenenleri çekmek için: ?updated_after=2025-01-01 00:00:00
        $updatedAfter = $request->query('updated_after'); // string

        // Ürün aktif filtre (default: sadece aktif)
        $onlyActive = (int) $request->query('only_active', 1); // 1/0

        // ---- Products base query ----
        $productsQ = DB::table('products as p')
            ->select([
                'p.id',
                'p.name',
               // 'p.slug',
                'p.image',
                'p.description',
                'p.active',
                'p.updated_at',
            ]);

        if ($onlyActive === 1) {
            $productsQ->where('p.active', 1);
        }

        if ($updatedAfter) {
            // product, variant, price herhangi biri updated ise dönsün:
            // Basit yaklaşım: ürün updated_at üzerinden filtre
            // (İstersen variant/price updated_at da dahil ederiz.)
            $productsQ->where('p.updated_at', '>=', $updatedAfter);
        }

        $total = (clone $productsQ)->count();

        $products = $productsQ
            ->orderBy('p.id')
            ->forPage($page, $perPage)
            ->get();

        $productIds = $products->pluck('id')->all();

        if (empty($productIds)) {
            return response()->json([
                'partner' => [
                    'id'   => $partner->id,
                    'name' => $partner->name ?? null,
                ],
                'data' => [],
                'meta' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                ],
            ]);
        }

        // ---- Categories (product -> categories[]) ----
        $catRows = DB::table('category_product as cp')
            ->join('categories as c', 'c.id', '=', 'cp.category_id')
            ->whereIn('cp.product_id', $productIds)
            ->select([
                'cp.product_id',
                'c.id as category_id',
                'c.name as category_name',
              //  'c.slug as category_slug',
            ])
            ->get();

        $catsByProduct = [];
        foreach ($catRows as $r) {
            $catsByProduct[$r->product_id][] = [
                'id'   => (int) $r->category_id,
                'name' => $r->category_name,
            //    'slug' => $r->category_slug,
            ];
        }

        // ---- Variants ----
        $variants = DB::table('product_variants as v')
            ->whereIn('v.product_id', $productIds)
            ->select([
                'v.id',
                'v.product_id',
                'v.name',
                'v.sku',
                'v.unit',
                'v.multiplier',
                'v.active',
                'v.updated_at',
            ])
            ->orderBy('v.product_id')
            ->orderBy('v.id')
            ->get();

        $variantIds = $variants->pluck('id')->all();

        $variantsByProduct = [];
        foreach ($variants as $v) {
            $variantsByProduct[$v->product_id][] = [
                'id'         => (int) $v->id,
                'name'       => $v->name,
                'sku'        => $v->sku,
                'unit'       => $v->unit,
                'multiplier' => (float) ($v->multiplier ?? 1),
                'active'     => (int) ($v->active ?? 1),
                'updated_at' => $v->updated_at,
            ];
        }

        // ---- Prices (partner-specific) ----
        // user_product_prices: partner_client_id + product_variant_id
        $pricesByVariant = [];
        if (!empty($variantIds)) {
            $priceRows = DB::table('user_product_prices as upp')
                ->where('upp.partner_client_id', $partner->id)
                ->whereIn('upp.product_variant_id', $variantIds)
                ->select([
                    'upp.product_variant_id',
                    'upp.price',
                //    'upp.currency',
                    'upp.updated_at',
                ])
                ->get();

            foreach ($priceRows as $p) {
                $pricesByVariant[$p->product_variant_id] = [
                    'price'      => (float) $p->price,
                    'currency'   => $p->currency ?? 'TRY',
                    'updated_at' => $p->updated_at,
                ];
            }
        }

        // ---- Final payload ----
        $data = [];
        foreach ($products as $p) {
            $pVariants = $variantsByProduct[$p->id] ?? [];

            // variant içine price göm
            foreach ($pVariants as &$vv) {
                $vv['partner_price'] = $pricesByVariant[$vv['id']] ?? null;
            }
            unset($vv);

            $data[] = [
                'product' => [
                    'id'          => (int) $p->id,
                    'name'        => $p->name,
                //    'slug'        => $p->slug,
                    'image'       => $p->image,
                    'description' => $p->description,
                    'active'   => (int) $p->isactive,
                    'updated_at'  => $p->updated_at,
                    'categories'  => $catsByProduct[$p->id] ?? [],
                ],
                'variants' => $pVariants,
            ];
        }

        return response()->json([
            'partner' => [
                'id'   => (int) $partner->id,
                'name' => $partner->name ?? null,
            ],
            'data' => $data,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => ($page * $perPage) < $total,
            ],
        ]);
    }

    public function batchUpsert(Request $request)
    {
        $partner = $request->attributes->get('partner');
        if (!$partner) {
            return response()->json(['success' => false, 'message' => 'Partner not found'], 401);
        }

        $partnerId = (int) $partner->id;

        $data = $request->validate([
            'products' => ['required','array','min:1'],

            'products.*.external_product_id' => ['required','string','max:80'], // payload böyle geliyor
            'products.*.name'   => ['required','string','max:190'],
            'products.*.active' => ['nullable','boolean'],

            'products.*.variants' => ['required','array','min:1'],
            'products.*.variants.*.sku'   => ['required','string','max:120'],
            'products.*.variants.*.name'  => ['required','string','max:150'],
            'products.*.variants.*.unit'  => ['nullable','string','max:50'],
            'products.*.variants.*.price' => ['nullable','numeric','min:0'],
            'products.*.variants.*.active'=> ['nullable','boolean'],
        ]);

        $created = ['products' => 0, 'variants' => 0, 'prices' => 0];
        $updated = ['products' => 0, 'variants' => 0, 'prices' => 0];

        DB::transaction(function () use (&$created, &$updated, $data, $partnerId) {

            // Payload’daki tüm external_code ve SKU’ları topla
            $externalCodes = [];
            $skus = [];

            foreach ($data['products'] as $p) {
                $externalCodes[] = $p['external_product_id']; // payload adı
                foreach ($p['variants'] as $v) {
                    $skus[] = $v['sku'];
                }
            }

            $externalCodes = array_values(array_unique($externalCodes));
            $skus          = array_values(array_unique($skus));

            // Mevcut productları ve variantları tek seferde çek
            $existingProducts = Product::where('partner_client_id', $partnerId)
                ->whereIn('external_code', $externalCodes)
                ->get()
                ->keyBy('external_code');

            $existingVariants = ProductVariant::where('partner_client_id', $partnerId)
                ->whereIn('sku', $skus)
                ->get()
                ->keyBy('sku');

            foreach ($data['products'] as $p) {

                $externalCode = $p['external_product_id']; // payload → db external_code

                // 1) PRODUCT UPSERT
                if (!isset($existingProducts[$externalCode])) {
                    $product = Product::create([
                        'partner_client_id' => $partnerId,
                        'external_code'     => $externalCode,
                        'name'              => $p['name'],
                        'active'            => $p['active'] ?? 1,
                    ]);
                    $existingProducts[$externalCode] = $product;
                    $created['products']++;
                } else {
                    $product = $existingProducts[$externalCode];
                    $product->update([
                        'name'   => $p['name'],
                        'active' => $p['active'] ?? $product->active,
                    ]);
                    $updated['products']++;
                }

                // 2) VARIANTS + PRICES
                foreach ($p['variants'] as $v) {

                    $sku = $v['sku'];

                    if (!isset($existingVariants[$sku])) {
                        $variant = ProductVariant::create([
                            'partner_client_id' => $partnerId,
                            'product_id'        => $product->id,
                            'sku'               => $sku,
                            'name'              => $v['name'],
                            'unit'              => $v['unit'] ?? null,
                            'active'            => $v['active'] ?? 1,
                        ]);
                        $existingVariants[$sku] = $variant;
                        $created['variants']++;
                    } else {
                        $variant = $existingVariants[$sku];
                        $variant->update([
                            'product_id' => $product->id, // ürün eşlemesi değişmiş olabilir
                            'name'       => $v['name'],
                            'unit'       => $v['unit'] ?? $variant->unit,
                            'active'     => $v['active'] ?? $variant->active,
                        ]);
                        $updated['variants']++;
                    }

                    // PRICE (opsiyonel)
                    if (array_key_exists('price', $v)) {
                        $priceRow = UserProductPrice::where('partner_client_id', $partnerId)
                            ->where('product_variant_id', $variant->id)
                            ->first();

                        if (!$priceRow) {
                            UserProductPrice::create([
                                'partner_client_id'  => $partnerId,
                                'product_variant_id'=> $variant->id,
                                'price'             => $v['price'],
                                'active'            => $v['active'] ?? 1,
                                'user_id'           => $partnerId, // partner entegrasyonu
                            ]);
                            $created['prices']++;
                        } else {
                            $priceRow->update([
                                'price'  => $v['price'],
                                'active' => $v['active'] ?? $priceRow->active,
                            ]);
                            $updated['prices']++;
                        }
                    }
                }
            }
        });

        return response()->json([
            'success' => true,
            'created' => $created,
            'updated' => $updated,
        ]);
    }
}

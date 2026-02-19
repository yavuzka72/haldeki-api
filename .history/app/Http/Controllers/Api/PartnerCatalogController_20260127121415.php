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
                'p.slug',
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
                'c.slug as category_slug',
            ])
            ->get();

        $catsByProduct = [];
        foreach ($catRows as $r) {
            $catsByProduct[$r->product_id][] = [
                'id'   => (int) $r->category_id,
                'name' => $r->category_name,
                'slug' => $r->category_slug,
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
                    'upp.currency',
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
                    'slug'        => $p->slug,
                    'image'       => $p->image,
                    'description' => $p->description,
                    'is_active'   => (int) $p->is_active,
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

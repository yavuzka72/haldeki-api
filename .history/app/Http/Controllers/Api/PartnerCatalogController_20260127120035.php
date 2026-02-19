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
        /** @var \App\Models\PartnerClient $partner */
        $partner = $request->attributes->get('partner');

        // Query params
        $updatedAfter = $request->query('updated_after'); // "2026-01-01 00:00:00" gibi
        $perPage = (int) ($request->query('per_page', 200));
        $page = max(1, (int) $request->query('page', 1));
        $offset = ($page - 1) * $perPage;

        // NOTE: tablo isimlerini senin projene göre düzenle:
        // products, product_variants, user_product_prices (veya variant_prices)
        $q = DB::table('products as p')
            ->whereNull('p.deleted_at')
            ->when($updatedAfter, fn($qq) => $qq->where('p.updated_at', '>=', $updatedAfter))
            ->orderBy('p.id', 'asc');

        $total = (clone $q)->count();

        $products = $q->select([
                'p.id',
                'p.name',
                'p.description',
                'p.category_id',
                'p.active',
                'p.updated_at',
            ])
            ->limit($perPage)
            ->offset($offset)
            ->get();

        $productIds = $products->pluck('id')->all();

        $variants = DB::table('product_variants as v')
            ->whereIn('v.product_id', $productIds)
            ->whereNull('v.deleted_at')
            ->select([
                'v.id',
                'v.product_id',
                'v.sku',
                'v.name',
                'v.unit',
                'v.multiplier',
                'v.active',
                'v.updated_at',
            ])
            ->get();

        $variantIds = $variants->pluck('id')->all();

        // Partner’a özel fiyat: user_product_prices tablosu varsayımı
        // Kolonları: variant_id, partner_client_id, price, currency, updated_at
        $prices = DB::table('user_product_prices as pr')
            ->whereIn('pr.variant_id', $variantIds)
            ->where('pr.partner_client_id', $partner->id)   // veya partner->partner_client_id senin yapına göre
            ->select([
                'pr.variant_id',
                'pr.price',
                'pr.currency',
                'pr.updated_at',
            ])
            ->get()
            ->keyBy('variant_id');

        // ürün -> variant -> price map
        $variantsByProduct = [];
        foreach ($variants as $v) {
            $p = $prices[$v->id] ?? null;

            $variantsByProduct[$v->product_id][] = [
                'id' => (int) $v->id,
                'sku' => $v->sku,
                'name' => $v->name,
                'unit' => $v->unit,
                'multiplier' => (float) $v->multiplier,
                'active' => (int) $v->active,
                'updated_at' => $v->updated_at,
                'price' => [
                    'value' => $p ? (float) $p->price : null,
                    'currency' => $p ? (string) $p->currency : 'TRY',
                    'updated_at' => $p?->updated_at,
                ],
            ];
        }

        $data = [];
        foreach ($products as $p) {
            $data[] = [
                'id' => (int) $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'category_id' => (int) ($p->category_id ?? 0),
                'active' => (int) $p->active,
                'updated_at' => $p->updated_at,
                'variants' => $variantsByProduct[$p->id] ?? [],
            ];
        }

        return response()->json([
            'partner' => [
                'id' => (int) $partner->id,
                'name' => $partner->name,
            ],
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
            ],
            'data' => $data,
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

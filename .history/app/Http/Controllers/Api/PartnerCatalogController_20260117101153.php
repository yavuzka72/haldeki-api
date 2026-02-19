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

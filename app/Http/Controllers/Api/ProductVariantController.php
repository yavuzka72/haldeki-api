<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductVariantController extends Controller
{
    public function store(Request $request, Product $product)
    {
        $data = $request->validate([
            'name'   => 'required|string|max:255',
            'unit'   => 'nullable|string|max:50',
            'sku'    => 'nullable|string|max:120',
            'active' => 'boolean',
        ]);

        $baseSku = isset($data['sku']) && trim((string)$data['sku']) !== ''
            ? Str::upper(trim((string)$data['sku']))
            : Str::upper(Str::slug($product->name . '-' . $data['name'], '-'));

        if ($baseSku === '') {
            $baseSku = 'SKU';
        }

        $sku = $baseSku;
        $i = 1;
        while (ProductVariant::where('sku', $sku)->exists()) {
            $sku = $baseSku . '-' . $i;
            $i++;
        }

        $variant = $product->variants()->create([
            'name'   => $data['name'],
            'unit'   => $data['unit'] ?? null,
            'sku'    => $sku,
            'active' => $data['active'] ?? true,
        ]);

        return response()->json(['success' => true, 'variant' => $variant], 201);
    }

    public function update(Request $request, ProductVariant $variant)
    {
        $data = $request->validate([
            'name'   => 'sometimes|string|max:255',
            'unit'   => 'nullable|string|max:50',
            'sku'    => 'nullable|string|max:120',
            'active' => 'boolean',
        ]);

        if (array_key_exists('sku', $data)) {
            $incoming = trim((string)$data['sku']);
            if ($incoming === '') {
                $base = Str::upper(Str::slug(($data['name'] ?? $variant->name), '-'));
                if ($base === '') {
                    $base = 'SKU';
                }
            } else {
                $base = Str::upper($incoming);
            }

            $newSku = $base;
            $i = 1;
            while (
                ProductVariant::where('sku', $newSku)
                    ->where('id', '!=', $variant->id)
                    ->exists()
            ) {
                $newSku = $base . '-' . $i;
                $i++;
            }

            $data['sku'] = $newSku;
        }

        $variant->update($data);
        return response()->json(['success' => true, 'variant' => $variant]);
    }
}

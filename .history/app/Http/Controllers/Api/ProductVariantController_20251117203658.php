<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;   // <-- BUNU EKLE
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;


class ProductVariantController extends Controller
{
    public function store(Request $request, Product $product)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'active' => 'boolean',
        ]);

        $variant = $product->variants()->create($data);
        return response()->json(['success' => true, 'variant' => $variant]);
    }

    public function update(Request $request, ProductVariant $variant)
    {
        $data = $request->validate([
            'name' => 'string|max:255',
            'active' => 'boolean',
        ]);

        $variant->update($data);
        return response()->json(['success' => true, 'variant' => $variant]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\UserProductPrice;


class PartnerVariantController extends Controller
{
    public function index(Request $request)
    {
        $partner = $request->attributes->get('partner');
        $partnerId = (int) $partner->id;

        $per = (int) $request->get('per_page', 20);
        $q   = trim((string) $request->get('q', ''));
        $productId = $request->get('product_id');

        $query = ProductVariant::query()->where('partner_client_id', $partnerId);

        if (!empty($productId) && $this->columnExists((new ProductVariant)->getTable(), 'product_id')) {
            $query->where('product_id', $productId);
        }

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $table = (new ProductVariant)->getTable();
                if ($this->columnExists($table, 'name')) $w->where('name', 'like', "%{$q}%");
                if ($this->columnExists($table, 'sku')) $w->orWhere('sku', 'like', "%{$q}%");
                if ($this->columnExists($table, 'title')) $w->orWhere('title', 'like', "%{$q}%");
                if ($this->columnExists($table, 'barcode')) $w->orWhere('barcode', 'like', "%{$q}%");
            });
        }

        $data = $query->orderByDesc('id')->paginate($per);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function store2(Request $request, $productId)
    {
        $partner = $request->attributes->get('partner');
        $partnerId = (int) $partner->id;

        // ✅ product bu partner’a ait mi?
        Product::where('id', $productId)
            ->where('partner_client_id', $partnerId)
            ->firstOrFail();

        $data = $request->validate([
            'name' => 'required|string|max:150',
            'unit' => 'required|string|max:50',
            'multiplier' => 'nullable|numeric',
            'barcode' => 'nullable|string|max:100',
            'active' => 'nullable|boolean',
        ]);

        $variant = ProductVariant::query()
            ->where('partner_client_id', $partnerId)
            ->where('product_id', $productId)
            ->where('name', $data['name'])
            ->first();

        if (!$variant) {
            $variant = ProductVariant::create([
                'partner_client_id' => $partnerId, // ✅
                'product_id' => $productId,
                'name' => $data['name'],
                'unit' => $data['unit'],
                'multiplier' => $data['multiplier'] ?? 1,
                'barcode' => $data['barcode'] ?? null,
                'active' => $data['active'] ?? 1,
            ]);
        }

        return response()->json(['success' => true, 'data' => $variant]);
    }
public function store(Request $request, $productId)
{
    $partner = $request->attributes->get('partner');
    $partnerId = (int) $partner->id;

    // ürün bu partner’a ait mi?
    Product::where('id', $productId)
        ->where('partner_client_id', $partnerId)
        ->firstOrFail();

    $data = $request->validate([
        'name' => 'required|string|max:150',
        'unit' => 'required|string|max:50',
        'multiplier' => 'nullable|numeric',
        'barcode' => 'nullable|string|max:100',
        'active' => 'nullable|boolean',
        'partner_sku' => 'required|string|max:120',

        // ✅ opsiyonel fiyat
        'price' => 'nullable|numeric|min:0',
        'price_active' => 'nullable|boolean',
    ]);

    // aynı variant varsa tekrar oluşturma
    $variant = ProductVariant::query()
        ->where('partner_client_id', $partnerId)
        ->where('product_id', $productId)
        ->where('name', $data['name'])
           ->where('sku', $data['partner_sku'])
        ->first();

    if (!$variant) {
        $variant = ProductVariant::create([
            'partner_client_id' => $partnerId,
            'product_id' => $productId,
            'name' => $data['name'],
        //    'unit' => $data['unit'],
      //      'multiplier' => $data['multiplier'] ?? 1,
            'barcode' => $data['barcode'] ?? null,
             'sku' => $data['partner_sku'], // ✅ burası
            'active' => $data['active'] ?? 1,
        ]);
    }

    // ✅ fiyat geldiyse upsert et (partner+variant bazlı tek fiyat)
    if (array_key_exists('price', $data) && $data['price'] !== null) {

        $makeActive = ($data['price_active'] ?? true) ? 1 : 0;

        if ($makeActive) {
            UserProductPrice::where('partner_client_id', $partnerId)
                ->where('product_variant_id', $variant->id)
                ->where('active', 1)
                ->update(['active' => 0]);
        }

        // user_id zorunluysa:
        // - ya nullable yap
        // - ya sistem user id kullan
        $userId = auth()->id(); // ❗ partner auth’ta user yoksa null olur
        // en güvenlisi: user_id nullable olsun
        // yoksa: $userId = 1; gibi bir sistem user

        $priceRow = UserProductPrice::updateOrCreate(
            [
                'partner_client_id' => $partnerId,
                'product_variant_id' => $variant->id,
            ],
            [
                'price' => $data['price'],
                'active' => $makeActive,
                'user_id' => $partnerId, // nullable değilse problem çıkarır
            ]
        );
    }

    return response()->json([
        'success' => true,
        'data' => $variant,
    ]);
}


    public function show(Request $request, $id)
    {
        $partner = $request->attributes->get('partner');
        $partnerId = (int) $partner->id;

        $variant = ProductVariant::where('partner_client_id', $partnerId)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => $variant]);
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            return Schema::hasColumn($table, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use App\Models\Product;

class PartnerProductController extends Controller
{
    public function index(Request $request)
    {
        $partner = $request->attributes->get('partner');
        $partnerId = (int) $partner->id;

        $per = (int) $request->get('per_page', 20);
        $q   = trim((string) $request->get('q', ''));
        $withVariants = (int) $request->get('with_variants', 0) === 1;

        $query = Product::query()->where('partner_client_id', $partnerId);

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%");

                $table = (new Product)->getTable();
                if ($this->columnExists($table, 'sku')) {
                    $w->orWhere('sku', 'like', "%{$q}%");
                }
                if ($this->columnExists($table, 'barcode')) {
                    $w->orWhere('barcode', 'like', "%{$q}%");
                }
            });
        }

        if ($withVariants && method_exists(Product::class, 'variants')) {
            $query->with(['variants' => function ($v) use ($partnerId) {
                // variant’lar da partner’a ait olsun
                $v->where('partner_client_id', $partnerId);
            }]);
        }

        $data = $query->orderByDesc('id')->paginate($per);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function store(Request $request)
    {
        $partner = $request->attributes->get('partner');
        $partnerId = (int) $partner->id;

        $data = $request->validate([
            'name' => 'required|string|max:190',
            'brand' => 'nullable|string|max:120',
            'category_id' => 'nullable|integer',
            'barcode' => 'nullable|string|max:64',
            'active' => 'nullable|boolean',
        ]);

        // duplicate kontrolü partner içinde
        $product = Product::query()
            ->where('partner_client_id', $partnerId)
            ->when(!empty($data['barcode']), fn($q) => $q->where('barcode', $data['barcode']))
            ->when(empty($data['barcode']), fn($q) => $q->where('name', $data['name']))
            ->first();

        if (!$product) {
            $product = Product::create([
                'partner_client_id' => $partnerId, // ✅
                'name' => $data['name'],
                'brand' => $data['brand'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'barcode' => $data['barcode'] ?? null,
                'active' => $data['active'] ?? 1,
            ]);
        }

        return response()->json(['success' => true, 'data' => $product]);
    }

    public function show(Request $request, $id)
    {
        $partner = $request->attributes->get('partner');
        $partnerId = (int) $partner->id;

        $withVariants = (int) $request->get('with_variants', 1) === 1;

        $query = Product::query()->where('partner_client_id', $partnerId);

        if ($withVariants && method_exists(Product::class, 'variants')) {
            $query->with(['variants' => function ($v) use ($partnerId) {
                $v->where('partner_client_id', $partnerId);
            }]);
        }

        $product = $query->where('id', $id)->firstOrFail();

        return response()->json(['success' => true, 'data' => $product]);
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

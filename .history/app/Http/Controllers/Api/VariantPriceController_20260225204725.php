<?php


namespace App\Http\Controllers\Api;
use App\Models\ProductVariant;
use App\Models\UserProductPrice;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller; 
use Illuminate\Validation\Rule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class VariantPriceController extends Controller
{
    public function store(Request $request, ProductVariant $variant)
    {
        $data = $request->validate([
            'price'  => 'required|numeric|min:0',
            'active' => 'boolean',
        ]);

        if (($data['active'] ?? true) === true) {
            $variant->prices()->active()->update(['active' => false]);
        }

        $price = $variant->prices()->create([
            'price'               => $data['price'],
            'active'              => $data['active'] ?? true,
            'user_id'             => $request->user()->id,
        ]);

        return response()->json(['success' => true, 'price' => $price->load('user')]);
    }

    public function update(Request $request, UserProductPrice $price)
    {
        $data = $request->validate([
            'price'  => 'nullable|numeric|min:0',
            'active' => 'nullable|boolean',
        ]);

        if (array_key_exists('active', $data) && $data['active'] === true) {
            $price->variant->prices()->where('id', '!=', $price->id)->active()->update(['active' => false]);
        }

        $price->update($data);

        return response()->json(['success' => true, 'price' => $price->fresh()->load('user')]);
    }
    
     public function setPrice(Request $request, $id)
    {
        $request->validate([
            'price' => 'required|numeric|min:0',
        ]);

        $variant = Variant::findOrFail($id);
        $variant->price = $request->input('price');
        $variant->save();

        return response()->json([
            'success' => true,
            'message' => 'Fiyat güncellendi',
            'data' => [
                'id' => $variant->id,
                'price' => $variant->price,
            ]
        ]);
    }
    public function upsert(Request $request)
    {
        $data = $request->validate([
            'email'              => ['nullable','email', Rule::exists('users','email')],
            'product_variant_id' => ['required','integer', Rule::exists('product_variants','id')],
            'price'              => ['required','numeric','min:0'],
            'active'             => ['nullable','boolean'],
        ]);

        $user = $request->user() ?? User::where('email', $data['email'] ?? null)->firstOrFail();

        $upp = UserProductPrice::updateOrCreate(
            [
                'user_id'            => $user->id,
                'product_variant_id' => $data['product_variant_id'],
            ],
            [
                'price'  => $data['price'],
                'active' => $data['active'] ?? true,
            ]
        );

        return response()->json(['success' => true, 'data' => $upp->load('user')]);
    }

    public function todayUpdateList(Request $request)
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'email' => ['nullable', 'email', Rule::exists('users', 'email')],
            'partner_client_id' => ['nullable', 'integer', Rule::exists('partner_clients', 'id')],
            'partner_email' => ['nullable', 'email', Rule::exists('partner_clients', 'email')],
        ]);

        $targetDate = Carbon::parse($data['date'] ?? now())->toDateString();

        $userId = $data['user_id'] ?? null;
        if (!$userId && !empty($data['email'])) {
            $userId = (int) User::where('email', $data['email'])->value('id');
        }

        $partnerFromHeader = $request->attributes->get('partner');
        $partnerClientId = $partnerFromHeader?->id ?: ($data['partner_client_id'] ?? null);
        if (!$partnerClientId && !empty($data['partner_email'])) {
            $partnerClientId = (int) DB::table('partner_clients')
                ->where('email', $data['partner_email'])
                ->value('id');
        }

        $logRows = DB::table('price_logs as pl')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'pl.product_variant_id')
            ->where(function ($q) use ($targetDate) {
                $q->whereDate('pl.created_at', $targetDate)
                  ->orWhereDate('pl.updated_at', $targetDate);
            })
               ->orderByDesc('pl.id')
            ->select([
                'pl.id',
                'pl.owner_user_id as user_id',
              
                'pl.product_variant_id',
                'pl.new_price',
                'pl.new_active',
                DB::raw('COALESCE(pl.product_id, pv.product_id) as resolved_product_id'),
                'pl.updated_at',
            ])
            ->get();

        // Aynı varyant için gün içinde birden fazla kayıt olabilir; en güncelini tut.
        $latestLogsByVariant = $logRows
            ->filter(fn ($r) => !empty($r->product_variant_id))
            ->unique('product_variant_id')
            ->values();

        $variantIds = $latestLogsByVariant->pluck('product_variant_id')->map(fn ($v) => (int) $v)->values();
        $productIds = $latestLogsByVariant
            ->pluck('resolved_product_id')
            ->filter()
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'date' => $targetDate,
                'count' => 0,
                'data' => [],
            ]);
        }

        $products = DB::table('products')
            ->whereIn('id', $productIds)
            ->select(['id', 'name', 'image', 'description', 'active', 'updated_at'])
            ->get()
            ->keyBy('id');

        $categoriesByProduct = DB::table('category_product as cp')
            ->join('categories as c', 'c.id', '=', 'cp.category_id')
            ->whereIn('cp.product_id', $productIds)
            ->orderBy('c.id')
            ->select(['cp.product_id', 'c.id', 'c.name'])
            ->get()
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->map(fn ($c) => [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
            ])->values());

        $variants = DB::table('product_variants')
            ->whereIn('id', $variantIds)
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        $logsByProduct = $latestLogsByVariant->groupBy('resolved_product_id');
        $data = collect();

        foreach ($logsByProduct as $productId => $logs) {
            $productId = (int) $productId;
            $product = $products->get($productId);
            if (!$product) {
                continue;
            }

            $productArr = (array) $product;
            $variantItems = collect($logs)->map(function ($log) use ($variants) {
                $variant = $variants->get((int) $log->product_variant_id);
                $v = $variant ? (array) $variant : [];

                return [
                    'id' => (int) ($v['id'] ?? $log->product_variant_id),
                    'name' => $v['name'] ?? null,
                    'sku' => $v['sku'] ?? null,
                    'unit' => $v['unit'] ?? null,
                    'multiplier' => (float) ($v['multiplier'] ?? 1),
                    'active' => isset($v['active']) ? (int) ((bool) $v['active']) : null,
                    'updated_at' => $v['updated_at'] ?? null,
                    'price' => $log->new_price !== null
                        ? [
                            'price' => (float) $log->new_price,
                            'source' => 'partner_override',
                            'updated_at' => $log->updated_at,
                        ]
                        : null,
                ];
            })->values();

            $data->push([
                'product' => [
                    'id' => (int) $productArr['id'],
                    'name' => $productArr['name'] ?? null,
                    'image' => $productArr['image'] ?? null,
                    'description' => $productArr['description'] ?? null,
                    'active' => isset($productArr['active']) ? (int) ((bool) $productArr['active']) : null,
                    'updated_at' => $productArr['updated_at'] ?? null,
                    'categories' => $categoriesByProduct->get($productId, collect())->values(),
                ],
                'variants' => $variantItems,
            ]);
        }

        return response()->json([
            'success' => true,
            'date' => $targetDate,
            'count' => $data->count(),
            'data' => $data->values(),
        ]);
    }

}

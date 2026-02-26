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
            'dealer_id' => ['nullable', 'integer'],
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

        $rows = DB::table('price_logs as pl')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'pl.product_variant_id')
            ->leftJoin('partner_clients as pc', 'pc.id', '=', 'pl.partner_client_id')
            ->where(function ($q) use ($targetDate) {
                $q->whereDate('pl.created_at', $targetDate)
                  ->orWhereDate('pl.updated_at', $targetDate);
            })
            ->when($userId, fn ($q) => $q->where('pl.owner_user_id', $userId))
            ->when(isset($data['dealer_id']), fn ($q) => $q->where('pc.dealer_id', (int) $data['dealer_id']))
         //   ->when($partnerClientId, fn ($q) => $q->where('pl.partner_client_id', $partnerClientId))
            ->orderByDesc('pl.id')
            ->select([
                'pl.id',
                'pl.owner_user_id as user_id',
                'pc.dealer_id',
                'pl.partner_client_id',
                'pl.product_variant_id',
                DB::raw('COALESCE(pl.product_id, pv.product_id) as product_id'),
                'pl.old_price',
                'pl.new_price',
                'pl.old_active',
                'pl.new_active',
                'pl.changed_by_user_id',
                'pl.created_at',
                'pl.updated_at',
            ])
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'user_id' => $r->user_id !== null ? (int) $r->user_id : null,
                'dealer_id' => $r->dealer_id !== null ? (int) $r->dealer_id : null,
                'partner_client_id' => $r->partner_client_id !== null ? (int) $r->partner_client_id : null,
                'product_id' => $r->product_id !== null ? (int) $r->product_id : null,
                'product_variant_id' => $r->product_variant_id !== null ? (int) $r->product_variant_id : null,
                'price' => $r->new_price !== null ? (float) $r->new_price : null,
                'active' => $r->new_active !== null ? (bool) $r->new_active : null,
                'old_price' => $r->old_price !== null ? (float) $r->old_price : null,
                'new_price' => $r->new_price !== null ? (float) $r->new_price : null,
                'old_active' => $r->old_active !== null ? (bool) $r->old_active : null,
                'new_active' => $r->new_active !== null ? (bool) $r->new_active : null,
                'changed_by_user_id' => $r->changed_by_user_id !== null ? (int) $r->changed_by_user_id : null,
                'created_at' => $r->created_at,
                'updated_at' => $r->updated_at,
            ]);

        return response()->json([
            'success' => true,
            'date' => $targetDate,
            'count' => $rows->count(),
            'data' => $rows,
        ]);
    }

}

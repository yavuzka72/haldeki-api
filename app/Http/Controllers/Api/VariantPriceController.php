<?php


namespace App\Http\Controllers\Api;
use App\Models\ProductVariant;
use App\Models\UserProductPrice;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller; 
use Illuminate\Validation\Rule;
use App\Models\User;

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

}

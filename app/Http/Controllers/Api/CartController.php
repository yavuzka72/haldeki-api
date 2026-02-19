<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Http;

class CartController extends Controller {
	use ApiResponse;

	public function show() {
		
		$cart = session()->get('cart');
		\Log::info('Sepet içeriği:', ['cart' => $cart]); // Debug için log ekleyelim
		
		if (!$cart) {
			$cart = [
				'items' => [],
				'total' => 0
			];
			session()->put('cart', $cart);
		}

		return response()->json($cart);
	}

	public function add(Request $request)
	{
		$request->validate([
			'product_id' => 'required|integer',
			'variant_id' => 'required|integer',
			'quantity' => 'required|integer|min:1'
		]);

		try {
			// API'den ürün bilgilerini al
			$productResponse = Http::withoutVerifying()->get("https://haldeki.com/api/v1/products/{$request->product_id}");
			$product = $productResponse->json()['data'] ?? null;

			if (!$product) {
				return response()->json(['message' => 'Ürün bulunamadı'], 404);
			}

			// Varyant bilgisini bul
			$variant = null;
			foreach ($product['variants'] as $v) {
				if ($v['id'] == $request->variant_id) {
					$variant = $v;
					break;
				}
			}

			if (!$variant) {
				return response()->json(['message' => 'Ürün varyantı bulunamadı'], 404);
			}

			// Mevcut sepeti al
			$cart = session()->get('cart', [
				'items' => [],
				'total' => 0
			]);

			\Log::info('Sepete ekleme öncesi:', ['cart' => $cart]); // Debug için

			$itemKey = $product['id'] . '-' . $variant['id'];

			// Eğer ürün zaten sepette varsa miktarını güncelle
			if (isset($cart['items'][$itemKey])) {
				$cart['items'][$itemKey]['quantity'] += $request->quantity;
			} else {
				// Yeni ürünü sepete ekle
				$cart['items'][$itemKey] = [
					'product_id' => $product['id'],
					'variant_id' => $variant['id'],
					'name' => $product['name'],
					'variant_name' => $variant['name'],
					'price' => (float)$variant['average_price'],
					'quantity' => $request->quantity,
					'image' => $product['image'] ? 'https://haldeki.com/storage/'.$product['image'] : 'https://via.placeholder.com/300x200'
				];
			}

			// Sepet toplamını güncelle
			$cart['total'] = 0;
			foreach ($cart['items'] as $item) {
				$cart['total'] += $item['price'] * $item['quantity'];
			}

			session()->put('cart', $cart);
			session()->save(); // Session'ı kaydetmeyi garantiye alalım

			\Log::info('Sepete ekleme sonrası:', ['cart' => $cart]); // Debug için

			return response()->json([
				'message' => 'Ürün sepete eklendi',
				'cart' => $cart
			]);

		} catch (\Exception $e) {
			\Log::error('Sepete ekleme hatası: ' . $e->getMessage());
			return response()->json(['message' => 'Bir hata oluştu'], 500);
		}
	}

	public function remove(Request $request)
	{
		$request->validate([
			'product_id' => 'required|integer',
			'variant_id' => 'required|integer'
		]);

		try {
			$cart = session()->get('cart', ['items' => [], 'total' => 0]);
			$itemKey = $request->product_id . '-' . $request->variant_id;

			if (isset($cart['items'][$itemKey])) {
				unset($cart['items'][$itemKey]);

				// Sepet toplamını güncelle
				$cart['total'] = 0;
				foreach ($cart['items'] as $item) {
					$cart['total'] += $item['price'] * $item['quantity'];
				}

				session()->put('cart', $cart);
			}

			return response()->json([
				'message' => 'Ürün sepetten kaldırıldı',
				'cart' => $cart
			]);

		} catch (\Exception $e) {
			\Log::error('Sepetten kaldırma hatası: ' . $e->getMessage());
			return response()->json(['message' => 'Bir hata oluştu'], 500);
		}
	}

	public function update(Request $request)
	{
		$request->validate([
			'product_id' => 'required|integer',
			'variant_id' => 'required|integer',
			'quantity' => 'required|integer|min:1'
		]);

		try {
			$cart = session()->get('cart', ['items' => [], 'total' => 0]);
			$itemKey = $request->product_id . '-' . $request->variant_id;

			if (isset($cart['items'][$itemKey])) {
				$cart['items'][$itemKey]['quantity'] = $request->quantity;

				// Sepet toplamını güncelle
				$cart['total'] = 0;
				foreach ($cart['items'] as $item) {
					$cart['total'] += $item['price'] * $item['quantity'];
				}

				session()->put('cart', $cart);
			}

			return response()->json([
				'message' => 'Sepet güncellendi',
				'cart' => $cart
			]);

		} catch (\Exception $e) {
			\Log::error('Sepet güncelleme hatası: ' . $e->getMessage());
			return response()->json(['message' => 'Bir hata oluştu'], 500);
		}
	}

	public function updateItem(Request $request, CartItem $item) {
		$request->validate([
			'quantity' => 'required|integer|min:1'
		]);

		if ($item->cart->user_id !== Auth::id()) {
			return $this->error('Bu işlem için yetkiniz yok.', 403);
		}

		$item->update([
			'quantity' => $request->quantity,
			'total_price' => $request->quantity * $item->unit_price
		]);

		$cart = Cart::find($item->cart_id);
		$cart->load(['items.productVariant.product', 'items.seller']);

		return $this->success($cart, 'Ürün güncellendi.');
	}

	public function removeItem(CartItem $item) {
		if ($item->cart->user_id !== Auth::id()) {
			return $this->error('Bu işlem için yetkiniz yok.', 403);
		}

		$item->delete();

		$cart = Cart::find($item->cart_id);
		$cart->load(['items.productVariant.product', 'items.seller']);

		return $this->success($cart, 'Ürün sepetten çıkarıldı.');
	}
}

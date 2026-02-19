<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class CartController extends Controller
{
    public function index()
    {
        $cart = session()->get('cart', [
            'items' => [],
            'total' => 0
        ]);

        // Her item için total_price hesapla
        foreach ($cart['items'] as &$item) {
            $item['total_price'] = $item['price'] * $item['quantity'];
        }

        // Sepet toplamını güncelle
        $cart['total'] = collect($cart['items'])->sum('total_price');
        
        // Güncellenmiş sepeti session'a kaydet
        session()->put('cart', $cart);

        return view('cart.index', compact('cart'));
    }

    public function add(Request $request)
    {
        $cart = session()->get('cart', ['items' => [], 'total' => 0]);
        
        $response = Http::withoutVerifying()
            ->get("https://haldeki.com/api/v1/products/{$request->product_id}");
        
        $product = $response->json()['data'];
        $variant = collect($product['variants'])->firstWhere('id', $request->variant_id);
        
        if (!$variant) {
            return response()->json(['error' => 'Ürün varyantı bulunamadı'], 404);
        }

        $quantity = $request->quantity ?? 1;
        $price = $variant['average_price'];

        $cartItem = [
            'product_id' => $product['id'],
            'variant_id' => $variant['id'],
            'name' => $product['name'],
            'variant_name' => $variant['name'],
            'image' => $product['image'],
            'price' => $price,
            'quantity' => $quantity,
            'total_price' => $price * $quantity
        ];

        $cart['items'][$variant['id']] = $cartItem;
        
        // Toplam tutarı güncelle
        $cart['total'] = collect($cart['items'])->sum('total_price');
        
        session()->put('cart', $cart);
        
        return response()->json(['message' => 'Ürün sepete eklendi', 'cart' => $cart]);
    }

    public function update(Request $request)
    {
        $cart = session()->get('cart', ['items' => [], 'total' => 0]);
        
        if (isset($cart['items'][$request->variant_id])) {
            $item = &$cart['items'][$request->variant_id];
            $item['quantity'] = $request->quantity;
            $item['total_price'] = $item['price'] * $request->quantity;
            
            // Toplam tutarı güncelle
            $cart['total'] = collect($cart['items'])->sum('total_price');
            
            session()->put('cart', $cart);
            
            return response()->json(['message' => 'Sepet güncellendi', 'cart' => $cart]);
        }
        
        return response()->json(['error' => 'Ürün bulunamadı'], 404);
    }

    public function remove(Request $request)
    {
        $cart = session()->get('cart', ['items' => [], 'total' => 0]);
        
        if (isset($cart['items'][$request->variant_id])) {
            unset($cart['items'][$request->variant_id]);
            
            // Toplam tutarı güncelle
            $cart['total'] = collect($cart['items'])->sum('total_price');
            
            session()->put('cart', $cart);
            
            return response()->json(['message' => 'Ürün sepetten kaldırıldı', 'cart' => $cart]);
        }
        
        return response()->json(['error' => 'Ürün bulunamadı'], 404);
    }
} 
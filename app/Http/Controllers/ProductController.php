<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ProductController extends Controller
{
    public function index()
    {
        $response = Http::withoutVerifying()->get('https://haldeki.com/api/v1/products');
        $responseData = $response->json()['data'] ?? [];
        
        // API'den gelen pagination bilgileri
        $total = $responseData['total'] ?? 0;
        $perPage = $responseData['per_page'] ?? 15;
        $currentPage = $responseData['current_page'] ?? 1;

        // Ürünleri collection'a çevir
        $products = collect($responseData['data'] ?? [])->map(function ($product) {
            return (object) [
                'id' => $product['id'],
                'name' => $product['name'],
                'description' => $product['description'],
                'image' => $product['image'],
                'discount' => $product['discount'] ?? null,
                'variants' => collect($product['variants'] ?? [])->map(function ($variant) {
                    return (object) $variant;
                }),
            ];
        });

        // Collection'ı paginator'a çevir
        $products = new LengthAwarePaginator(
            $products,
            $total,
            $perPage,
            $currentPage,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ]
        );

        return view('products.index', compact('products'));
    }

    public function show($id)
    {
        $response = Http::withoutVerifying()->get("https://haldeki.com/api/v1/products/{$id}");
        $productData = $response->json()['data'] ?? null;

        if (!$productData) {
            abort(404);
        }

        $product = (object) [
            'id' => $productData['id'],
            'name' => $productData['name'],
            'description' => $productData['description'],
            'image' => $productData['image'],
            'discount' => $productData['discount'] ?? null,
            'variants' => collect($productData['variants'] ?? [])->map(function ($variant) {
                return (object) $variant;
            }),
        ];

        // Benzer ürünler için API isteği
        $relatedResponse = Http::withoutVerifying()->get('https://haldeki.com/api/v1/products', [
            'category' => $productData['category_id'] ?? null,
            'limit' => 4,
            'except' => $id
        ]);
        
        $relatedProducts = collect($relatedResponse->json()['data']['data'] ?? [])->map(function ($product) {
            return (object) [
                'id' => $product['id'],
                'name' => $product['name'],
                'description' => $product['description'],
                'image' => $product['image'],
                'discount' => $product['discount'] ?? null,
                'variants' => collect($product['variants'] ?? [])->map(function ($variant) {
                    return (object) $variant;
                }),
            ];
        });

        return view('products.show', compact('product', 'relatedProducts'));
    }
} 
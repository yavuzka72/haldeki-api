<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;

class HomeController extends Controller
{
    public function index()
    {
        $response = Http::withoutVerifying()->get('https://haldeki.com/api/v1/products');
        $products = $response->json()['data']['data'] ?? [];

        return view('home.index', compact('products'));
    }
} 
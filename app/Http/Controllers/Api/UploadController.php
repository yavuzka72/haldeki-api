<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Controller;

class UploadController extends Controller
{
    public function store(Request $request)
    {
        // Flutter: FormData key'i 'image' olmalı
        $request->validate([
            'image' => 'required|file|image|mimes:jpg,jpeg,png,webp,gif|max:5120', // max 5MB
        ]);

        $file = $request->file('image');

        // public diskine kaydet (storage/app/public/uploads/products)
        $path = $file->store('products', 'public');

        // public URL (storage:link gerekiyor, aşağıya bak)
        $url = Storage::disk('public')->url($path);

        return response()->json([
            'success' => true,
            // Flutter kodundaki beklenti: 'path' string dönmesi
            'path' => $path,
            'url'  => $url,
        ]);
    }
}

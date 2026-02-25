<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Controller;

class UploadController extends Controller
{
    public function store(Request $request)
    {
        $disk = config('filesystems.media_disk', 'public');

        // Flutter: FormData key'i 'image' olmalı
        $request->validate([
            'image' => 'required|file|image|mimes:jpg,jpeg,png,webp,gif|max:5120', // max 5MB
        ]);

        $file = $request->file('image');

        // MEDIA_DISK'e kaydet (local/public veya r2)
        $path = $file->store('products', $disk);

        // Seçili diskten URL üret
        $url = Storage::disk($disk)->url($path);

        return response()->json([
            'success' => true,
            // Flutter kodundaki beklenti: 'path' string dönmesi
            'path' => $path,
            'url'  => $url,
        ]);
    }
}

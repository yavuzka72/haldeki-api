<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use HasFactory;

    // Eğer birebir alan kısıtlı yönetilecekse fillable kalabilir;
    // tüm kolonlar mass-assign edilsin istersen guarded = [] de kullanabilirsin.
    protected $fillable = [
        'name',
        'image',
        'description',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    // API dönerken image_url’in de görünmesini istersen:
    protected $appends = ['image_url'];

    /* ================== İlişkiler ================== */

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function users(): BelongsToMany
    {
        // pivot tablo: user_products (user_id, product_id, active, timestamps)
        return $this->belongsToMany(User::class, 'user_products', 'product_id', 'user_id')
            ->withPivot('active')
            ->withTimestamps();
    }

    public function categories(): BelongsToMany
    {
        // pivot tablo varsayılan: category_product (category_id, product_id)
        // tablo adını zaten 'category_product' yazmışsın, bu da doğru.
        return $this->belongsToMany(Category::class, 'category_product', 'product_id', 'category_id')
            ->withTimestamps(); // pivot’ta timestamps varsa ekle
    }

    /* ================== Accessor ================== */

    public function getImageUrlAttribute(): string
    {
        // Storage::url() public disk’teki dosyalar için doğru. 
        // $this->image 'public/...' gibi bir relative path olmalı (ör: 'products/abc.jpg').
        if (!empty($this->image)) {
            return Storage::url($this->image);
        }

        // public/images/product-placeholder.jpg varsa:
        return asset('images/product-placeholder.jpg');
    }
}

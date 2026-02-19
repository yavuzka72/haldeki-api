<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VariantPrice extends Model
{
    use HasFactory;

    protected $table = 'user_product_prices';

    protected $fillable = [
        'partner_client_id', // ✅ eklendi
        'product_variant_id',
        'user_id',
        'price',
        'active',
    ];

    protected $casts = [
        'price'  => 'decimal:2',
        'active' => 'boolean',
    ];

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

use App\Models\User;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'partner_client_id',
    'product_id',
    'name',
    'sku',       // ✅ mutlaka
    'barcode',
    'unit',
    'multiplier',
    'active',
        
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    protected $appends = [
        'average_price',
        'user_price',
        'partner_price',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(UserProductPrice::class, 'product_variant_id');
    }

    public function partnerPrices(): HasMany
    {
        return $this->hasMany(PartnerVariantPrice::class, 'product_variant_id');
    }

    public function getAveragePriceAttribute(): float
    {
        $avg = $this->partnerPrices()
            ->where('active', 1)
            ->avg('price');

        return $avg !== null ? (float) $avg : 0.0;
    }

    public function getUserPriceAttribute(): ?float
    {
        $email = request('email');
        if (! $email) return null;

        $user = User::where('email', $email)->first();
        if (! $user) return null;

        $price = $this->prices()
            ->where('user_id', $user->id)
            ->where('active', 1)
            ->latest('id')
            ->value('price');

        return $price !== null ? (float) $price : null;
    }

    public function getPartnerPriceAttribute(): ?float
    {
        $partnerKey = request()->header('X-Partner-Key') ?: request('partner_key');
        if (! $partnerKey) return null;

        $partner = PartnerClient::where('partner_key', $partnerKey)
            ->where('is_active', 1)
            ->first();

        if (! $partner) return null;

        $price = $this->partnerPrices()
            ->where('partner_client_id', $partner->id)
            ->where('active', 1)
            ->latest('id')
            ->value('price');

        return $price !== null ? (float) $price : null;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerVariantPrice extends Model
{
    use HasFactory;

    protected $table = 'partner_variant_prices';

    protected $fillable = [
        'partner_client_id',
        'product_variant_id',
        'price',
        'stock_qty',
        'active',
        'currency',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock_qty' => 'integer',
        'active' => 'boolean',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(PartnerClient::class, 'partner_client_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function scopeActive($q)
    {
        return $q->where('active', 1);
    }
}

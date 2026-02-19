<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerVariantMap extends Model
{
    use HasFactory;

    protected $table = 'partner_variant_maps';

    protected $fillable = [
        'partner_client_id',
        'partner_sku',
        'product_variant_id',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(PartnerClient::class, 'partner_client_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}

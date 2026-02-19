<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\DeliveryOrder;
 

class PartnerClient extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        'contact_number',
        'address',
        'country_id',
        'city_id',
        'city',
        'district',
        'latitude',
        'longitude',
        'location_lat',
        'location_lng',
        'status',
        'dealer_id', 
        'partner_key',
        'partner_secret',
        'token',
        'token_expires_at',
        'is_active',
        'webhook_url',
        'webhook_secret',
        'meta',
         'commission_rate', 
         'commission_amount',
         'user_type',
         
        
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'token_expires_at' => 'datetime',
        'meta' => 'array',
        'location_lat' => 'float',
        'location_lng' => 'float',
        'status' => 'integer',
        'user_type' => 'string',
    ];

    public function variantMaps()
    {
        return $this->hasMany(\App\Models\PartnerVariantMap::class, 'partner_client_id');
    }

    public function variantPrices()
    {
        return $this->hasMany(\App\Models\PartnerVariantPrice::class, 'partner_client_id');
    }

      public function deliverOrders(): HasMany
    {
        return $this->hasMany(DeliveryOrder::class, 'reseller_id');
    }
  
 
}

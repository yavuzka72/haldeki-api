<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SpecialCustomerPricing extends Model
{
    use HasFactory;

    protected $table = 'special_customer_pricing';

    protected $fillable = [
        'customer_id',
        'city_id',
        'price_per_km',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'city_id' => 'integer',
        'price_per_km' => 'double',
    ];
}

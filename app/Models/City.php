<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\SpecialCustomerPricing;


class City extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [ 'country_id', 'name', 'address', 'fixed_charges', 'cancel_charges', 'min_distance', 'min_weight', 'per_distance_charges', 'per_weight_charges', 'status', 'commission_type', 'admin_commission'];


    protected $casts = [
        'country_id' => 'integer',
        'fixed_charges' => 'double',
        'cancel_charges' => 'double',
        'min_distance' => 'double',
        'min_weight' => 'double',
        'per_distance_charges' => 'double',
        'per_weight_charges' => 'double',
        'status' => 'integer',
        'admin_commission' => 'double',
    ];

    public function country(){
        return $this->belongsTo(Country::class, 'country_id','id');
    }

    public function extraCharges(){
        return $this->hasMany(ExtraCharge::class,'city_id','id');
    }

    public function extraChargesActive(){
        return $this->extraCharges()->where('status',1);
    }
 public function applySpecialKmRate($customerId)
{
    $special = SpecialCustomerPricing::where('customer_id', $customerId)
        ->where('city_id', $this->id)
        ->first();

    if ($special) {
        $this->per_distance_charges = $special->price_per_km;
    }
}
    
    public function getPricePerKm($customerId)
{
    $special = SpecialCustomerPricing::where('customer_id', $customerId)
        ->where('city_id', $this->id)
        ->first();

    return $special ? $special->price_per_km : $this->per_distance_charges;
}

public function calculateTotalPrice($customerId, $distanceKm)
{
    $pricePerKm = $this->getPricePerKm($customerId);
    return $distanceKm * $pricePerKm;
}
 
    protected static function boot()
    {
        parent::boot();
        static::deleted(function ($row) {
            $row->extraCharges()->delete();
            if($row->forceDeleting === true)
            {
                $row->extraCharges()->forceDelete();
            }
        });
        static::restoring(function($row) {
            $row->extraCharges()->withTrashed()->restore();
        });
    }
}

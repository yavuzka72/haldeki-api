<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use App\Models\SpecialCustomerPricing;

class CityResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
       
        
$userId = $request->user_id ?? Auth::id();
        $pricePerKm = $this->per_distance_charges;

        if ($userId) {
            $special = SpecialCustomerPricing::where('customer_id', $userId)
                ->where('city_id', $this->id)
                ->first();

            if ($special) {
                $pricePerKm = $special->price_per_km;
            }
        }

        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'address'           => $this->address,
            'country_id'        => $this->country_id,
            'country_name'      => optional($this->country)->name,
            'country'           => $this->country,
            'status'            => $this->status,
            'fixed_charges'     => $this->fixed_charges,
            'extra_charges'     => $this->extraChargesActive,
            'cancel_charges'    => $this->cancel_charges,
            'min_distance'      => $this->min_distance,
            'min_weight'        => $this->min_weight,
            'per_distance_charges' => $pricePerKm,
            'per_weight_charges' => $this->per_weight_charges,
            'created_at'         => $this->created_at,
            'updated_at'         => $this->updated_at,
            'deleted_at'         => $this->deleted_at,
            'commission_type'    => $this->commission_type,
            'admin_commission'   => $this->admin_commission,
        ];
    }
}

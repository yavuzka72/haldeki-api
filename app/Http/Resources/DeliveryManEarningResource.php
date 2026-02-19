<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryManEarningResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'wallet_balance'    => (float) $this->wallet_balance,
            'total_withdrawn'   => (float) $this->total_withdrawn,
            'admin_commission'  => (float) $this->admin_commission,
            'delivery_man_commission' =>(float)  $this->delivery_man_commission,
            'total_order'       => (float) $this->total_order,
            'paid_order'        => (float) $this->paid_order,
        ];
    }
}
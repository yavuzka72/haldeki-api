<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\City;

class VehicleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        $selected_city = null;
        if( is_array($this->city_ids) && $this->type == 'city_wise' ) {
            $selected_city = City::whereIn('id', $this->city_ids)->get()->mapWithKeys(function ($item) {
                return [ $item->id => $item->name ];
            });
        }
        return [
            'id'            => $this->id,
            'title'         => $this->title,
            'type'          => $this->type,
            'size'          => $this->size,
            'capacity'      => $this->capacity,
            'city_ids'      => $this->city_ids,
            'city_text'     => $selected_city,
            'status'        => $this->status,
            'description'   => $this->description,
            'vehicle_image' => getSingleMedia($this, 'vehicle_image',null),
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,
            'deleted_at'    => $this->deleted_at,
        ];
    }
}

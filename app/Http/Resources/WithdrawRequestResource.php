<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Wallet;

class WithdrawRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        $wallet_balance = 0;

        if( $this->status == 'requested' ) {
            $wallet = Wallet::where('user_id',$this->user_id)->first();
            $wallet_balance = $wallet->total_amount ?? 0;
        }
        return [
            'id'                => $this->id,
            'user_id'           => $this->user_id,
            'user_name'         => optional($this->user)->name,
            'amount'            => $this->amount,
            'currency'          => $this->currency,
            'status'            => $this->status,            
            'wallet_balance'    => $wallet_balance,
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }
}
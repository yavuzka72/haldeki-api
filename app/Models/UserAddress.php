<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserAddress extends Model
{
    use HasFactory;

    // Eğer tabloda isim `user_addresses` değilse, alttakini açın:
    // protected $table = 'user_addresses';

    protected $fillable = [
        'user_id',
        'title',            // Ev, İş, vb. (opsiyonel)
        'contact_name',     // opsiyonel
        'contact_phone',    // opsiyonel
        'address',
        'city',
        'district',
        'postal_code',
        'country_id',
        'city_id',
        'latitude',
        'longitude',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
      public function scopeMyAddress($q)
    {
        $userId = request('user_id') ?? optional(auth('sanctum')->user())->id;
        if ($userId) {
            $q->where('user_id', $userId);
        }
        return $q;
    }
}

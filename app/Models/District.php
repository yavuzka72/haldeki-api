<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class District extends Model
{
    use SoftDeletes;

    protected $table = 'districts';

    protected $fillable = [
        'city_id',
        'name',
        'status',
    ];

    public $timestamps = false;

    /**
     * İlişkiler
     */

    // Bir ilçe bir şehre (City) bağlıdır
    public function city()
    {
        return $this->belongsTo(\App\Models\City::class);
    }
}

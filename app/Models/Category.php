<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;


class Category extends Model
{
    protected $fillable = ['name','slug','image','description','is_active','order'];
    protected $casts = ['is_active' => 'boolean'];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($category) {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });
    }


  
    
    
    // Sadece BİR tane products() metodu
    public function products()
    {
        // Pivot tablo ismi category_product ise bu yeterli:
        // return $this->belongsToMany(Product::class);

        // İstersen açık yaz:
        return $this->belongsToMany(Product::class, 'category_product'); // ->withTimestamps(); // pivot'ta timestamp varsa aç
    }
}

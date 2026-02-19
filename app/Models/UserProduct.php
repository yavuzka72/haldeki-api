<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class UserProduct extends Model {
	use HasFactory;

	protected $fillable = [
		'user_id',
		'product_id',
		'active'
	];

	protected $casts = [
		'active' => 'boolean'
	];

	public function user(): BelongsTo {
		return $this->belongsTo(User::class);
	}

	public function product(): BelongsTo {
		return $this->belongsTo(Product::class);
	}
 
    public function categories()
    {
        return $this->belongsToMany(Category::class, 'category_product'); // ->withTimestamps();
    }
 
}

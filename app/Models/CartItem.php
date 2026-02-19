<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model {
	protected $fillable = [
		'cart_id',
		'product_variant_id',
		'seller_id',
		'quantity',
		'unit_price',
		'total_price'
	];

	protected $casts = [
		'quantity' => 'integer',
		'unit_price' => 'decimal:2',
		'total_price' => 'decimal:2'
	];

	public function cart(): BelongsTo {
		return $this->belongsTo(Cart::class);
	}

	public function productVariant(): BelongsTo {
		return $this->belongsTo(ProductVariant::class);
	}

	public function seller(): BelongsTo {
		return $this->belongsTo(User::class, 'seller_id');
	}

	protected static function boot() {
		parent::boot();

		static::saving(function ($cartItem) {
			$cartItem->total_price = $cartItem->quantity * $cartItem->unit_price;
		});
	}
}

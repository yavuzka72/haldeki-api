<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class UserProductPrice extends Model {
	use HasFactory;

	protected $fillable = [
		'user_id',
		'product_variant_id',
		'price',
		'active'
	];

	protected $casts = [
		'price' => 'decimal:2',
		'active' => 'boolean'
	];

	public function scopeCheckDuplicate($query, $userId, $variantId, $excludeId = null) {
		$query->where('user_id', $userId)
			->where('product_variant_id', $variantId)
			->where('active', true);

		if ($excludeId) {
			$query->where('id', '!=', $excludeId);
		}

		return $query;
	}

	public function user(): BelongsTo {
		return $this->belongsTo(User::class);
	}

	public function productVariant(): BelongsTo {
		return $this->belongsTo(ProductVariant::class);
	}
	
	public function scopeActive($q)
{
    return $q->where('active', true);
}
}

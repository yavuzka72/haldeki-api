<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy {
	public function viewAny(User $user): bool {
		if ($user->admin_level === 1) return true;

		// Satıcı sadece kendisine atanan ürünleri görebilir
		if ($user->admin_level === 2) {
			return $user->products()->exists();
		}

		return false;
	}

	public function view(User $user, Product $product): bool {
		if ($user->admin_level === 1) return true;

		if ($user->admin_level === 2) {
			return $user->products()->where('product_id', $product->id)->exists();
		}
		return false;
	}

	public function create(User $user): bool {
		return $user->admin_level === 1;
	}

	public function update(User $user, Product $product): bool {
		return $user->admin_level === 1;
	}

	public function delete(User $user, Product $product): bool {
		return $user->admin_level === 1;
	}
}

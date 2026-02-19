<?php

namespace App\Policies;

use App\Models\ProductVariant;
use App\Models\User;

class ProductVariantPolicy {
	public function viewAny(User $user): bool {
		if ($user->admin_level === 1) return true;

		if ($user->admin_level === 2) {
			return $user->products()->exists();
		}
		return false;
	}

	public function view(User $user, ProductVariant $variant): bool {
		if ($user->admin_level === 1) return true;

		if ($user->admin_level === 2) {
			return $user->products()->where('product_id', $variant->product_id)->exists();
		}
		return false;
	}

	public function create(User $user): bool {
		return $user->admin_level === 1;
	}

	public function update(User $user, ProductVariant $variant): bool {
		return $user->admin_level === 1;
	}

	public function delete(User $user, ProductVariant $variant): bool {
		return $user->admin_level === 1;
	}
}

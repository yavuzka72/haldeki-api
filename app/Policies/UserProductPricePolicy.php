<?php

namespace App\Policies;

use App\Models\UserProductPrice;
use App\Models\User;

class UserProductPricePolicy {
	public function viewAny(User $user): bool {
		if ($user->admin_level === 1) return true;
		return $user->admin_level === 2;
	}

	public function view(User $user, UserProductPrice $price): bool {
		if ($user->admin_level === 1) return true;
		return $user->id === $price->user_id;
	}

	public function create(User $user): bool {
		if ($user->admin_level === 1) return true;
		return $user->admin_level === 2;
	}

	public function update(User $user, UserProductPrice $price): bool {
		if ($user->admin_level === 1) return true;
		return $user->id === $price->user_id;
	}

	public function delete(User $user, UserProductPrice $price): bool {
		if ($user->admin_level === 1) return true;
		return $user->id === $price->user_id;
	}
}

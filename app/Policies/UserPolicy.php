<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy {
	public function viewAny(User $user): bool {
		return $user->admin_level === 1;
	}

	public function view(User $user, User $target): bool {
		if ($user->admin_level === 1) return true;
		return $user->id === $target->id;
	}

	public function create(User $user): bool {
		return $user->admin_level === 1;
	}

	public function update(User $user, User $target): bool {
		if ($user->admin_level === 1) return true;
		// Satıcı sadece kendi profilini düzenleyebilir
		return $user->id === $target->id;
	}

	public function delete(User $user, User $target): bool {
		return $user->admin_level === 1;
	}
}

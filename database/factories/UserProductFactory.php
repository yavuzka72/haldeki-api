<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserProduct>
 */
class UserProductFactory extends Factory {
	/**
	 * Define the model's default state.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return [
			'user_id' => \App\Models\User::factory()->seller(),
			'product_id' => \App\Models\Product::factory(),
			'active' => true,
		];
	}
}

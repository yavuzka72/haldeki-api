<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserProductPrice>
 */
class UserProductPriceFactory extends Factory {
	/**
	 * Define the model's default state.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return [
			'user_id' => \App\Models\User::factory()->seller(),
			'product_variant_id' => \App\Models\ProductVariant::factory(),
			'price' => fake()->randomFloat(2, 10, 100),
			'active' => true,
		];
	}
}

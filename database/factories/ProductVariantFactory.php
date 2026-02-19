<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductVariant>
 */
class ProductVariantFactory extends Factory {
	/**
	 * Define the model's default state.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return [
			'product_id' => \App\Models\Product::factory(),
			'name' => fake()->randomElement([
				'1 Kilogram',
				'5 Kilogram',
				'10 Kilogram',
				'1 Kasa',
				'2 Kasa',
				'Yarım Kasa',
				'1 Demet',
				'1 Adet',
				'5 Adet'
			]),
			'active' => true,
		];
	}
}

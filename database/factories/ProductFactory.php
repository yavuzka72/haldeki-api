<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory {
	/**
	 * Define the model's default state.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return [
			'name' => fake()->unique()->randomElement([
				'Domates',
				'Salatalık',
				'Biber',
				'Patlıcan',
				'Soğan',
				'Patates',
				'Havuç',
				'Kabak',
				'Marul',
				'Ispanak',
				'Elma',
				'Armut',
				'Portakal',
				'Mandalina',
				'Muz'
			]),
			'description' => fake()->paragraph(),
			'image' => null,
			'active' => true,
		];
	}
}

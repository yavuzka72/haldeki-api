<?php

namespace Database\Factories;

use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderItemFactory extends Factory {
	protected $model = OrderItem::class;

	public function definition(): array {
		$quantity = $this->faker->numberBetween(1, 5);
		$unitPrice = $this->faker->randomFloat(2, 5, 100);

		return [
			'order_id' => null, // Bu, seeder'da set edilecek
			'product_variant_id' => ProductVariant::factory(),
			'seller_id' => User::factory()->state(['admin' => true, 'admin_level' => 2]),
			'quantity' => $quantity,
			'unit_price' => $unitPrice,
			'total_price' => $quantity * $unitPrice,
			'status' => $this->faker->randomElement(['pending', 'confirmed', 'cancelled']),
		];
	}
}

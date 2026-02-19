<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory {
	protected $model = Order::class;

	public function definition(): array {
		return [
			'user_id' => User::factory(),
			'total_amount' => $this->faker->randomFloat(2, 10, 1000),
			'status' => $this->faker->randomElement(['pending', 'confirmed', 'cancelled']),
			'payment_status' => $this->faker->randomElement(['pending', 'paid', 'failed']),
			'note' => $this->faker->optional()->sentence(),
		];
	}
}

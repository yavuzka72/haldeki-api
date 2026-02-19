<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\UserProductPrice;

class UserProductPriceSeeder extends Seeder {
	/**
	 * Run the database seeds.
	 */
	public function run(): void {
		// Her satıcı için, atanmış ürünlerinin her varyantına fiyat gir
		User::where('admin_level', 2)->each(function ($user) {
			$user->products->each(function ($product) use ($user) {
				$product->variants->each(function ($variant) use ($user) {
					UserProductPrice::create([
						'user_id' => $user->id,
						'product_variant_id' => $variant->id,
						'price' => fake()->randomFloat(2, 10, 100),
						'active' => true
					]);
				});
			});
		});
	}
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Product;
use App\Models\UserProduct;

class UserProductSeeder extends Seeder {
	/**
	 * Run the database seeds.
	 */
	public function run(): void {
		// Her satıcıya rastgele 5-10 ürün ata
		User::where('admin_level', 2)->each(function ($user) {
			$products = Product::inRandomOrder()
				->take(fake()->numberBetween(5, 10))
				->get();

			foreach ($products as $product) {
				UserProduct::create([
					'user_id' => $user->id,
					'product_id' => $product->id,
					'active' => true
				]);
			}
		});
	}
}

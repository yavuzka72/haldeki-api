<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\ProductVariant;

class ProductVariantSeeder extends Seeder {
	/**
	 * Run the database seeds.
	 */
	public function run(): void {
		// Her ürün için 2-3 varyant oluştur
		Product::all()->each(function ($product) {
			ProductVariant::factory()
				->count(fake()->numberBetween(2, 3))
				->create([
					'product_id' => $product->id
				]);
		});
	}
}

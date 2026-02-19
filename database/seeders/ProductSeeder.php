<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Product;

class ProductSeeder extends Seeder {
	/**
	 * Run the database seeds.
	 */
	public function run(): void {
		// 15 ürün oluştur
		Product::factory()
			->count(15)
			->create();
	}
}

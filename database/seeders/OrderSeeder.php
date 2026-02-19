<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\ProductVariant;
use Illuminate\Database\Seeder;
use Faker\Factory as Faker;

class OrderSeeder extends Seeder {
	public function run(): void {
		$faker = Faker::create('tr_TR');

		// Her kullanıcı için 1-3 arası sipariş oluştur
		User::where('admin_level', 0)->each(function ($user) use ($faker) {
			$orderCount = rand(1, 3);

			for ($i = 0; $i < $orderCount; $i++) {
				$order = Order::factory()->create([
					'user_id' => $user->id
				]);

				// Her sipariş için 1-5 arası ürün oluştur
				$itemCount = rand(1, 5);
				$total = 0;

				// Aktif satıcıları al
				$sellers = User::where('admin_level', 2)->where('admin', true)->get();

				// Aktif varyantları al
				$variants = ProductVariant::where('active', true)->get();

				for ($j = 0; $j < $itemCount; $j++) {
					$quantity = rand(1, 5);
					$unitPrice = $faker->randomFloat(2, 5, 100);
					$totalPrice = $quantity * $unitPrice;

					OrderItem::factory()->create([
						'order_id' => $order->id,
						'product_variant_id' => $variants->random()->id,
						'seller_id' => $sellers->random()->id,
						'quantity' => $quantity,
						'unit_price' => $unitPrice,
						'total_price' => $totalPrice,
					]);

					$total += $totalPrice;
				}

				// Siparişin toplam tutarını güncelle
				$order->update(['total_amount' => $total]);
			}
		});
	}
}

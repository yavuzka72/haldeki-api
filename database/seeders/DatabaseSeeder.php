<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder {
	/**
	 * Seed the application's database.
	 */
	public function run(): void {
		// User::factory(10)->create();

		User::factory()->create([
			'name' => 'Admin',
			'email' => 'admin@haldeki.local',
			'password' => Hash::make('Admin.123!'),
			'admin' => true,
			'admin_level' => 1,
		]);

		// Diğer seeder'ları çalıştır
		$this->call([
			UserSeeder::class,
			ProductSeeder::class,
			ProductVariantSeeder::class,
			UserProductSeeder::class,
			UserProductPriceSeeder::class,
			OrderSeeder::class,
		]);
	}
}

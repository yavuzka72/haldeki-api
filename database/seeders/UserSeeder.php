<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;

class UserSeeder extends Seeder {
	/**
	 * Run the database seeds.
	 */
	public function run(): void {
		// 5 satıcı oluştur
		User::factory()
			->count(5)
			->seller()
			->create();

		// 10 müşteri oluştur
		User::factory()
			->count(10)
			->customer()
			->create();
	}
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	/**
	 * Run the migrations.
	 */
	public function up(): void {
		Schema::create('orders', function (Blueprint $table) {
			$table->id();
			$table->string('order_number')->unique();
			$table->integer('reseller_id')->default(1);
			$table->foreignId('user_id')->constrained()->cascadeOnDelete();
			$table->decimal('total_amount', 10, 2);
			$table->enum('status', ['pending', 'confirmed', 'cancelled'])->default('pending');
			$table->enum('payment_status', ['pending', 'paid', 'failed'])->default('pending');
			$table->text('note')->nullable();
			$table->string('shipping_address')->nullable();
			$table->string('phone')->nullable();
			$table->timestamps();
		});
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void {
		Schema::dropIfExists('orders');
	}
};

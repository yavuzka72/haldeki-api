<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void {
		Schema::create('user_product_prices', function (Blueprint $table) {
			$table->id();
			$table->foreignId('user_id')->constrained()->onDelete('cascade');
			$table->foreignId('product_variant_id')->constrained()->onDelete('cascade');
			$table->decimal('price', 10, 2);
			$table->boolean('active')->default(true);
			$table->timestamps();
		});
	}

	public function down(): void {
		Schema::dropIfExists('user_product_prices');
	}
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void {
		Schema::create('user_products', function (Blueprint $table) {
			$table->id();
			$table->foreignId('user_id')->constrained()->onDelete('cascade');
			$table->foreignId('product_id')->constrained()->onDelete('cascade');
			$table->boolean('active')->default(true);
			$table->timestamps();
		});
	}

	public function down(): void {
		Schema::dropIfExists('user_products');
	}
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // Kimlik
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // Eski alanlar (varsa geriye dönük uyum için tutuyoruz)
            $table->boolean('admin')->default(0);
            $table->tinyInteger('admin_level')->default(0); // 0: normal, 1: süper admin, 2: satıcı (fiyat girişi) vb.

            // Bayi modülü alanları
            $table->enum('user_type', ['user','vendor','administrator','supplier'])
                  ->default('user')
                  ->index();

            // Bu kullanıcı hangi bayiye bağlı? (vendor ise NULL)
            $table->unsignedBigInteger('vendor_id')->nullable()->index();

            // İletişim & adres
            $table->string('phone', 191)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 191)->nullable();     // il
            $table->string('district', 191)->nullable(); // ilçe

            // Konum
            $table->decimal('location_lat', 10, 7)->nullable();
            $table->decimal('location_lng', 10, 7)->nullable();

            $table->rememberToken();
            $table->timestamps();

            // Self-referential FK: users.vendor_id -> users.id
            $table->foreign('vendor_id')
                  ->references('id')->on('users')
                  ->nullOnDelete();

            // Sorgu performansı için kompozit indeks
            $table->index(['user_type', 'vendor_id']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};

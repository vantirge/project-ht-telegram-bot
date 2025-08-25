<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::table('telegram_users', function (Blueprint $table) {
            $table->string('state')->nullable();
            $table->string('auth_code')->nullable();
            $table->string('user_login')->nullable();
        });
    }
    public function down() {
        Schema::table('telegram_users', function (Blueprint $table) {
            $table->dropColumn(['state', 'auth_code', 'user_login']);
        });
    }
}; 
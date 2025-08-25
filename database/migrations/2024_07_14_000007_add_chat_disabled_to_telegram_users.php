<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::table('telegram_users', function (Blueprint $table) {
            $table->boolean('chat_disabled')->default(false);
        });
    }
    
    public function down() {
        Schema::table('telegram_users', function (Blueprint $table) {
            $table->dropColumn('chat_disabled');
        });
    }
}; 
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_users', function (Blueprint $table) {
            if (!Schema::hasColumn('telegram_users', 'user_login')) {
                // Ensure column exists in case older schema lacks it
                $table->string('user_login')->nullable()->after('user_id');
            }
            $table->index('user_login', 'telegram_users_user_login_idx');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_users', function (Blueprint $table) {
            $table->dropIndex('telegram_users_user_login_idx');
        });
    }
};





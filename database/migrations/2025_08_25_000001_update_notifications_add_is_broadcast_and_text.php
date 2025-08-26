<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('notifications', 'is_broadcast')) {
                $table->boolean('is_broadcast')->default(true)->after('description');
            }
        });

        // Change column type without Doctrine DBAL (MySQL)
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `notifications` MODIFY `description` TEXT');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE notifications ALTER COLUMN description TYPE TEXT');
        }
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            if (Schema::hasColumn('notifications', 'is_broadcast')) {
                $table->dropColumn('is_broadcast');
            }
        });

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `notifications` MODIFY `description` VARCHAR(255)');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE notifications ALTER COLUMN description TYPE VARCHAR(255)');
        }
    }
};



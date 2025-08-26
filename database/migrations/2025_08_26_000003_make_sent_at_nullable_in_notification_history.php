<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `notification_history` MODIFY `sent_at` TIMESTAMP NULL DEFAULT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE notification_history ALTER COLUMN sent_at DROP NOT NULL');
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `notification_history` MODIFY `sent_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE notification_history ALTER COLUMN sent_at SET NOT NULL');
            DB::statement("ALTER TABLE notification_history ALTER COLUMN sent_at SET DEFAULT now()");
        }
    }
};



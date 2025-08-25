<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNotificationHistoryTable extends Migration
{
    public function up()
    {
        Schema::create('notification_history', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('telegram_id');
            $table->unsignedBigInteger('notification_id');
            $table->timestamp('sent_at')->useCurrent();

            $table->foreign('notification_id')->references('id')->on('notifications')->onDelete('cascade');
            $table->unique(['telegram_id', 'notification_id']);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('notification_history');
    }
} 
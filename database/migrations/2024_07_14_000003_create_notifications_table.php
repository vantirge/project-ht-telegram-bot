<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNotificationsTable extends Migration
{
    public function up()
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id(); // id уведомления
            $table->string('description'); // описание уведомления
            $table->timestamps(); // создаёт created_at и updated_at
        });
    }

    public function down()
    {
        Schema::dropIfExists('notifications');
    }
} 
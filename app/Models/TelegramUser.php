<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramUser extends Model {
    protected $table = 'telegram_users';
    protected $fillable = [
        'telegram_id', 'user_id', 'state', 'auth_code', 'user_login', 'chat_disabled'
    ];
    public $timestamps = false;
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDisable extends Model {
    protected $table = 'notification_disables';
    protected $fillable = ['user_id', 'disabled_at'];
    public $timestamps = true;
} 
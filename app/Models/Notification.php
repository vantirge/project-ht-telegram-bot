<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model {
    protected $table = 'notifications';
    public $timestamps = true;
    protected $fillable = ['description', 'sent_at', 'is_broadcast'];


}
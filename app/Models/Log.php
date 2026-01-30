<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Log extends Model
{
    protected $table = 'log';
    public $timestamps = false;

    protected $fillable = [
        'id_user',
        'context',
        'activity',
        'timestamp'
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'id_user');
    }
}

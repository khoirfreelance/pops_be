<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Config extends Model
{
    protected $table = 'config';
    const UPDATED_AT = 'modified_at';
    protected $fillable = [
        'id_user', 'name', 'value'
    ];
    public $timestamps = true;
}

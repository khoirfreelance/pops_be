<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Wilayah extends Model
{
    protected $table = 'wilayah';
    const UPDATED_AT = 'modified_at';
    protected $fillable = ['provinsi', 'kota', 'kecamatan', 'kelurahan'];
}

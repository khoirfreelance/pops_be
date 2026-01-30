<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Posyandu extends Model
{
    protected $table = 'posyandu';
    const UPDATED_AT = 'modified_at';

    protected $fillable = [
        'nama_posyandu',
        'alamat',
        'id_wilayah',
        'rt',
        'rw'
    ];

    public function cadres()
    {
        return $this->hasMany(User::class, 'id_posyandu');
    }

    public function wilayah()
    {
        return $this->belongsTo(Wilayah::class, 'id_wilayah');
    }
}

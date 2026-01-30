<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Intervensi extends Model
{
    use HasFactory;

    protected $table = 'intervensi';

    protected $fillable = [
        'petugas',
        'tgl_intervensi',
        'desa',
        'nama_subjek',
        'nik_subjek',
        'status_subjek',
        'jk',
        'tgl_lahir',
        'nama_wali',
        'nik_wali',
        'status_wali',
        'rt',
        'rw',
        'posyandu',
        'umur_subjek',
        'bantuan',
        'kategori',
    ];
}

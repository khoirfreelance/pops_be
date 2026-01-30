<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Keluarga extends Model
{
    protected $table = 'keluarga';
    const UPDATED_AT = 'modified_at';
    protected $fillable = ['no_kk','alamat','rt','rw','id_wilayah','is_pending'];

    public function anggota()
    {
        return $this->hasMany(AnggotaKeluarga::class, 'id_keluarga');
    }

    public function kepala()
    {
        return $this->hasOne(AnggotaKeluarga::class, 'id_keluarga', 'id')
            ->where('status_hubungan', 'Kepala Keluarga');
    }

    public function wilayah()
    {
        return $this->belongsTo(Wilayah::class, 'id_wilayah');
    }

}


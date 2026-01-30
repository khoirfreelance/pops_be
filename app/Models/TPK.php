<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TPK extends Model
{
    protected $table = 'tpk';
    const UPDATED_AT = 'modified_at';

    protected $fillable = [
        'no_tpk',
        'id_wilayah'
    ];

    public function cadres()
    {
        return $this->hasMany(Cadre::class, 'id_tpk');
    }

    public function wilayah()
    {
        return $this->belongsTo(Wilayah::class, 'id_wilayah');
    }
}

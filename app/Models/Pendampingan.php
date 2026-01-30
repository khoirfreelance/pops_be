<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pendampingan extends Model
{
    use HasFactory;

    protected $table = 'pendampingan';
    const UPDATED_AT = 'modified_at';

    protected $fillable = [
        'jenis',
        'id_subjek',
        'tgl_pendampingan',
        'tgl_pemeriksaan',
        'dampingan_ke',
        'catatan',
        'bb',
        'tb',
        'lk',
        'lila',
        'lika',
        'hb',
        'imt',
        'usia',
        'asi_ekslusif',
        'imunisasi_dasar',
        'diasuh_oleh',
        'pemberian_vit_a',
        'usia_kehamilan',
        'anemia',
        'kek',
        'riwayat_kehamilan',
        'resti',
        'terpapar_rokok',
        'jamban_sehat',
        'sumber_air_bersih',
        'punya_jaminan',
        'keluarga_teredukasi',
        'mendapatkan_bantuan',
        'riwayat_penyakit',
        'ket_riwayat_penyakit',
        'id_petugas',
        'id_wilayah',
        'rw',
        'rt',
        'is_pending'
    ];

    /**
     * Relasi dinamis ke tabel subjek.
     */
    public function subjek()
    {
        return match ($this->jenis) {
            'catin' => $this->belongsTo(Catin::class, 'id_subjek', 'id'),
            //'anak'  => $this->belongsTo(Anak::class, 'id_subjek', 'id'),
            //'bumil' => $this->belongsTo(Bumil::class, 'id_subjek', 'id'),
            default => null,
        };
    }

    /**
     * Relasi ke petugas.
     */
    public function petugas()
    {
        return $this->belongsTo(User::class, 'id_petugas', 'id');
    }

    public function wilayah()
    {
        return $this->belongsTo(Wilayah::class, 'id_wilayah');
    }
}

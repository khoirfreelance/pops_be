<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pregnancy extends Model
{
    // ✅ Gunakan nama tabel yang benar sesuai DB kamu
    protected $table = 'pregnancy';

    protected $fillable = [
        'nama_petugas',
        'tanggal_pendampingan',
        'nama_ibu',
        'nik_ibu',
        'usia_ibu',
        'nama_suami',
        'nik_suami',
        'pekerjaan_suami',
        'usia_suami',
        'kehamilan_ke',
        'jumlah_anak',
        'status_kehamilan',
        'riwayat_4t',
        'riwayat_penggunaan_kb',
        'riwayat_ber_kontrasepsi',
        'provinsi',
        'kota',
        'kecamatan',
        'kelurahan',
        'rt',
        'rw',
        'tanggal_pemeriksaan_terakhir',
        'berat_badan',
        'tinggi_badan',
        'imt',
        'kadar_hb',
        'status_gizi_hb',
        'lila',
        'status_gizi_lila',
        'riwayat_penyakit',
        'usia_kehamilan_minggu',
        'taksiran_berat_janin',
        'tinggi_fundus',
        'hpl',
        'terpapar_asap_rokok',
        'mendapat_ttd',
        'menggunakan_jamban',
        'menggunakan_sab',
        'fasilitas_rujukan',
        'riwayat_keguguran_iufd',
        'mendapat_kie',
        'mendapat_bantuan_sosial',
        'rencana_tempat_melahirkan',
        'rencana_asi_eksklusif',
        'rencana_tinggal_setelah',
        'rencana_kontrasepsi',
        'posyandu',
        'status_risiko_usia'
    ];

    protected $casts = [
        'tanggal_pendampingan' => 'date',
        'tanggal_pemeriksaan_terakhir' => 'date',
        'hpl' => 'date',
        'berat_badan' => 'float',
        'tinggi_badan' => 'float',
        'imt' => 'float',
        'kadar_hb' => 'float',
        'lila' => 'float',
    ];
}

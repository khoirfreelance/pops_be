<?php

namespace App\Imports;

use App\Models\Child;
use App\Models\Posyandu;
use App\Models\User;
use App\Models\Wilayah;
use App\Models\Log;
use App\Models\Keluarga;
use App\Models\Cadre;
use App\Models\AnggotaKeluarga;
use App\Models\DampinganKeluarga;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Exception;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;

class ChildrenImportPendampingan implements ToCollection, WithStartRow
{
    /* protected array $wilayahUser = [];
    protected string $posyanduUser = '';
    protected string $rtPosyandu = '';
    protected string $rwPosyandu = ''; */

    public function __construct(private int $userId)
    {
        $this->loadWilayahUser();
    }

    public function startRow(): int
    {
        return 2; // skip header
    }

    public function collection(Collection $rows)
    {

        try {
            foreach ($rows as $index => $row) {

                $user = Auth::user();
                $wilayahData = $this->resolveWilayahFromRow($row);

                if (count($row) < 45) {
                    throw new Exception("Format CSV tidak valid di baris " . ($index + 2));
                }

                // =========================
                // 0. Validasi data import
                // =========================
                if (!preg_match('/^[0-9`]+$/', $row[4])) {
                    throw new \Exception(
                        "NIK hanya boleh berisi angka dan karakter `",
                        1001
                    );
                }

                $nik = $this->normalizeNik($row[4] ?? null);
                $nama = strtoupper($row[3]??'-');
                $tglUkur = $this->convertDate($row[2]);

                if (!$nik || !$tglUkur) {
                    throw new \Exception(
                        "NIK atau tanggal pengukuran kosong / tidak valid pada data {$nama}",
                        1001
                    );
                }

                $duplikat = Child::where('nik_anak', $nik)
                    ->whereDate('tgl_pendampingan', $tglUkur)
                    ->first();

                if ($duplikat) {
                    throw new \Exception(
                        "Data atas <strong>{$nik}</strong>, <strong>{$nama}</strong> sudah diunggah pada <strong>"
                        . $duplikat->created_at->format('d-m-Y')."</strong>",
                        1001
                    );
                }
                // dd($this->wilayahUser['provinsi']);

                $data = [
                    'petugas' => strtoupper($row[1]),
                    'tgl_pendampingan' => $tglUkur,
                    'nama_anak' => $nama,
                    'nik_anak' => $nik,
                    'jk' => $this->normalizeJenisKelamin($row[5]),
                    'usia' => ltrim(trim($row[6]), "0"),

                    'nama_ayah' => strtoupper($row[7]),
                    'nik_ayah' => $this->normalizeNik($row[8] ?? null),
                    'pekerjaan_ayah' => strtoupper($row[9]),
                    'usia_ayah' => $row[10],

                    'nama_ibu' => strtoupper($row[11]),
                    'nik_ibu' => $this->normalizeNik($row[12] ?? null),
                    'pekerjaan_ibu' => strtoupper($row[13]),
                    'usia_ibu' => $row[14],

                    'anak_ke' => $row[15],

                    'riwayat_4t' => $row[16],
                    'riwayat_kb' => $row[17],
                    'alat_kontrasepsi' => $row[18],

                    'provinsi'   => $wilayahData['provinsi'],
                    'kota'       => $wilayahData['kota'],
                    'kecamatan'  => $wilayahData['kecamatan'],
                    'kelurahan'  => $wilayahData['kelurahan'],
                    'rt' => ltrim($row[21], "0"),
                    'rw' => ltrim($row[22], "0"),

                    'bb_lahir' => $this->normalizeBeratGramToKg($row[23]),
                    'tb_lahir' => $this->normalizePanjangMToCM($row[24]),
                    'bb' => $this->normalizeBeratGramToKg($row[25]),
                    'tb' => $this->normalizePanjangMToCM($row[26]),

                    'status_gizi' => strtoupper($row[27]),
                    'lila' => $row[28],
                    'lika' => $row[29],

                    'asi' => $row[30],
                    'imunisasi' => $row[31],
                    'diasuh_oleh' => $row[32],
                    'rutin_posyandu' => $row[33],

                    'riwayat_penyakit_bawaan' => $row[34],
                    'penyakit_bawaan' => $row[35],
                    'riwayat_penyakit_6bulan' => $row[36],
                    'penyakit_6bulan' => $row[37],

                    'terpapar_asap_rokok' => $row[38],
                    'penggunaan_jamban_sehat' => $row[39],
                    'penggunaan_sab' => $row[40],
                    'apabila_ada_penyakit' => $row[41],
                    'memiliki_jaminan' => $row[42],
                    'kie' => $row[43],
                    'mendapatkan_bantuan' => $row[44],
                ];

                // Z-Score
                $z_bbu = $this->hitungZScore('BB/U', $data['jk'], $data['usia'], $data['bb']);
                $z_tbu = $this->hitungZScore('TB/U', $data['jk'], $data['usia'], $data['tb']);
                $z_bbtb = $this->hitungZScore('BB/TB', $data['jk'], $data['tb'], $data['bb']);

                $data = array_merge($data, [
                    'zs_bb_u' => $z_bbu,
                    'bb_u' => $this->statusBBU($z_bbu),
                    'zs_tb_u' => $z_tbu,
                    'tb_u' => $this->statusTBU($z_tbu),
                    'zs_bb_tb' => $z_bbtb,
                    'bb_tb' => $this->statusBBTB($z_bbtb),
                ]);

                $child = Child::create($data);

                /* $wilayah = Wilayah::firstOrCreate([
                    'provinsi' => $data['provinsi'],
                    'kota' => $data['kota'],
                    'kecamatan' => $data['kecamatan'],
                    'kelurahan' => $data['kelurahan'],
                ]); */

                $noKK = $data['nik_ayah'] ?? $data['nik_ibu'] ?? $data['nik_anak'] ?? null;

                if ($noKK) {
                    $keluarga = Keluarga::firstOrCreate(
                        [
                            'no_kk' => $noKK,
                        ],
                        [
                            'alamat' => 'Desa: ' . $data['kelurahan'],
                            'rt' => $data['rt'] ?? null,
                            'rw' => $data['rw'] ?? null,
                            'id_wilayah' => $wilayahData['id'],
                            'is_pending' => true,
                        ]
                    );
                    $anggota = [
                        'id_keluarga' => $keluarga->id,
                        'nama' => $data['nama_anak'],
                        'nik' => $data['nik_anak'],
                        'jenis_kelamin' => $this->normalizeJenisKelamin($data['jk']),
                        'status_hubungan' => 'Anak',
                        'is_pending' => true,
                    ];

                    $anggota_ayah = [
                        'id_keluarga' => $keluarga->id,
                        'nama' => $data['nama_ayah'],
                        'nik' => $data['nik_ayah'],
                        'jenis_kelamin' => 'L',
                        'status_hubungan' => 'Kepala Keluarga',
                        'is_pending' => true,
                    ];

                    $anggota_ibu = [
                        'id_keluarga' => $keluarga->id,
                        'nama' => $data['nama_ibu'],
                        'nik' => $data['nik_ibu'],
                        'jenis_kelamin' => 'P',
                        'status_hubungan' => 'Istri',
                        'is_pending' => true,
                    ];

                    if ($data['nik_ayah']) {
                        AnggotaKeluarga::firstOrCreate(["nik" => $anggota_ayah['nik']], $anggota_ayah);
                    }else{
                        AnggotaKeluarga::create($anggota_ayah);
                    }

                    if ($data['nik_ibu']) {
                        AnggotaKeluarga::firstOrCreate(["nik" => $anggota_ibu['nik']], $anggota_ibu);
                    }else{
                        AnggotaKeluarga::create($anggota_ibu);
                    }

                    if ($data['nik_anak']) {
                        AnggotaKeluarga::firstOrCreate(["nik" => $anggota['nik']], $anggota);
                    }else{
                        AnggotaKeluarga::create($anggota);
                    }

                }

                $user = User::where('name', strtoupper($row[1]))
                ->where('id_wilayah', $wilayahData['id'])
                ->first();

                if (!$user) {
                    $user = User::create([
                        'nik' => null,
                        'name' => strtoupper($row[1]),
                        'email' => $this->generateRandomEmail($row[1]),
                        'email_verified_at' => now(),
                        'phone' => null,
                        'role' => null,
                        'id_wilayah' => $wilayahData['id'],
                        'status' => 1,
                        'is_pending' => 1,
                        'password' => '-',
                    ]);
                }

                $posyandu = Posyandu::firstOrCreate([
                    'nama_posyandu' => strtoupper($wilayahData['kelurahan']),
                    'id_wilayah' => $wilayahData['id'],
                    'rt' => $row['rt'] ?? null,
                    'rw' => $row['rw'] ?? null,
                ]);

                $posyanduID = $user->role === 'Super Admin'
                    ? $posyandu->id
                    : $this->posyanduUserID;

                $cadre = Cadre::firstOrCreate([
                    'id_user' => $user->id,
                    'id_posyandu' => $posyanduID,
                ], [
                    'id_tpk' => null,
                    'status' => 'non-kader',
                ]);

                $dampinganKeluarga = DampinganKeluarga::firstOrCreate([
                    'id_pendampingan' => $child->id,
                    'id_keluarga' => $keluarga->id,
                    'id_tpk' => $cadre->id,
                    'jenis' => 'ANAK',
                ]);

                // ✅ Log aktivitas
                Log::create([
                    'id_user' => Auth::id(),
                    'context' => 'Pendampingan Anak',
                    'activity' => 'Import pendampingan anak ' . ($row['nama_anak'] ?? '-'),
                    'timestamp' => now(),
                ]);
            }

        } catch (Exception $e) {
            // ✅ expected error
            if ($e->getCode() === 1001) {
                throw new \Exception($e->getMessage());
            }

            throw new \Exception(
                'Gagal import data, silahkan check dan bandingkan kembali format csv dengan contoh yang diberikan.', $e->getCode(), $e
            );
        }
    }

    private function generateRandomEmail(string $nama): string
    {
        // bersihin nama
        $username = strtolower($nama);
        $username = preg_replace('/[^a-z0-9]+/', '.', $username);
        $username = trim($username, '.');

        // biar unik
        $unique = now()->format('YmdHis') . rand(100, 999);

        return "{$username}.{$unique}@pops.com";
    }

    private function normalizeNik($nik)
    {
        if (is_null($nik)) {
            return null;
        }

        // cast ke string dulu (penting kalau dari Excel)
        $nik = (string) $nik;

        // hapus backtick, spasi, dan karakter aneh
        $nik = trim($nik);
        $nik = str_replace('`', '', $nik);

        // ambil HANYA angka
        //$nik = preg_replace('/\D/', '', $nik);

        return $nik ?: null;
    }

    private function convertDate($date)
    {
        if (!$date) {
            return null;
        }

        $date = trim($date);

        // ✅ Format yang diizinkan
        $acceptedFormats = [
            'm/d/Y',
            'd/m/Y',
            'd-m-Y',
            'Y/m/d',
            'Y-m-d',
        ];

        // =========================
        // 1️⃣ Cek format eksplisit
        // =========================
        foreach ($acceptedFormats as $format) {
            $dt = \DateTime::createFromFormat($format, $date);
            if ($dt && $dt->format($format) === $date) {
                return $dt->format('Y-m-d');
            }
        }

        // =========================
        // 2️⃣ Fallback manual (CSV jelek)
        // =========================
        $parts = preg_split('/[\/\-]/', $date);

        if (count($parts) === 3) {
            if (strlen($parts[0]) === 4) {
                [$y, $m, $d] = $parts;
            } else {
                [$d, $m, $y] = $parts;
            }

            if (checkdate((int)$m, (int)$d, (int)$y)) {
                return sprintf('%04d-%02d-%02d', $y, $m, $d);
            }
        }

        // =========================
        // ❌ FORMAT TIDAK DITERIMA
        // =========================
        throw new \Exception(
            "Format tanggal <strong>{$date}</strong> tidak diterima.<br>"
            . "Format yang diperbolehkan adalah:<br>"
            . "<ul class='text-start'>"
            . "<li><strong>DD/MM/YYYY</strong> (contoh: 25/12/2024)</li>"
            . "<li><strong>DD-MM-YYYY</strong> (contoh: 25-12-2024)</li>"
            . "<li><strong>YYYY/MM/DD</strong> (contoh: 2024/12/25)</li>"
            . "<li><strong>YYYY-MM-DD</strong> (contoh: 2024-12-25)</li>"
            . "</ul>",
            1001
        );
    }

    private function hitungZScore($tipe, $jk, $usiaOrTb, $bb)
    {
        $sex = ($jk == 'L' || $jk == 'l' || $jk == 1 || $jk == "LAKI-LAKI") ? 1 : 2;

        switch ($tipe) {
            case 'BB/U':
                $usia = round($usiaOrTb);
                $row = DB::table('who_weight_for_age')
                    ->where('sex', $sex)
                    ->where('age_month', $usia)
                    ->first();
                break;

            case 'TB/U':
                $usia = round($usiaOrTb);
                $row = DB::table('who_height_for_age')
                    ->where('sex', $sex)
                    ->where('age_month', $usia)
                    ->first();
                break;

            case 'BB/TB':
                $tb = round($usiaOrTb);
                $row = DB::table('who_weight_for_height')
                    ->where('sex', $sex)
                    ->where('height_cm', $tb)
                    ->first();
                break;

            default:
                return null;
        }

        if (!$row) {
            return null;
        }

        return $this->hitungZScoreLMS($bb, $row->L, $row->M, $row->S);
    }

    private function hitungZScoreLMS($nilai, $L, $M, $S)
    {
        if ($L == 0) {
            $z = log($nilai / $M) / $S;
        } else {
            $z = (pow(($nilai / $M), $L) - 1) / ($L * $S);
        }

        // Bulatkan ke 2 angka di belakang koma
        return round($z, 2);
    }

    private function statusBBU($z)
    {
        $result = null;
        if (is_null($z)) {
            $result = null;
        } elseif ($z < -3) {
            $result = 'Severely Underweight';
        } elseif ($z < -2) {
            $result = 'Underweight';
        } elseif ($z <= 1) {
            $result = 'Normal';
        } elseif ($z <= 2) {
            $result = 'Risiko BB Lebih';
        } else {
            $result = 'Overweight';
        }
        return $result;
    }

    private function statusTBU($z)
    {
        $result = null;
        if (is_null($z)) {
            $result = null;
        } elseif ($z < -3) {
            $result = 'Severely Stunted';
        } elseif ($z < -2) {
            $result = 'Stunted';
        } elseif ($z <= 2) {
            $result = 'Normal';
        } else {
            $result = 'Tinggi';
        }
        return $result;
    }

    private function statusBBTB($z)
    {
        $result = null;

        if ($z === null) {
            $result = null;
        } elseif ($z < -3) {
            $result = 'Severely Wasted';
        } elseif ($z < -2) {
            $result = 'Wasted';
        } elseif ($z <= 1) {
            $result = 'Normal';
        } elseif ($z <= 2) {
            $result = 'Risk of Overweight';
        } elseif ($z <= 3) {
            $result = 'Overweight';
        } else {
            $result = 'Obese';
        }

        return $result;
    }

    protected function loadWilayahUser(): void
    {
        $user = User::find($this->userId);
        $this->posyanduUser = 'UNKNOWN';

        if (!$user || !$user->id_wilayah) {
            $this->wilayahUser = [
                'id'    => null,
                'provinsi' => null,
                'kota' => null,
                'kecamatan' => null,
                'kelurahan' => null,
            ];
            return;
        }

        $zona = Wilayah::find($user->id_wilayah);
        $cadre = Cadre::where('id_user', $this->userId)->first();

        if ($cadre && $cadre->posyandu) {
            $this->posyanduUser = $cadre->posyandu->id;
            $this->rtPosyandu = $cadre->posyandu->rt;
            $this->rwPosyandu = $cadre->posyandu->rw;
        }

        $this->wilayahUser = [
            'id' => $zona->id ?? null,
            'provinsi' => $zona->provinsi ?? null,
            'kota' => $zona->kota ?? null,
            'kecamatan' => $zona->kecamatan ?? null,
            'kelurahan' => $zona->kelurahan ?? null,
        ];
    }

    protected function resolveWilayahFromRow(Collection $row): array
    {
        $user = Auth::user();

        // =========================
        // BUKAN SUPER ADMIN
        // =========================
        if (!$user || $user->role !== 'Super Admin') {
            return [
                'id'        => $this->wilayahUser['id'],
                'provinsi'  => $this->wilayahUser['provinsi'],
                'kota'      => $this->wilayahUser['kota'],
                'kecamatan' => $this->wilayahUser['kecamatan'],
                'kelurahan' => $this->wilayahUser['kelurahan'],
            ];
        }

        // =========================
        // SUPER ADMIN
        // =========================
        //$prov = $this->normalizeWilayahKey($row['prov'] ?? null);
        //$kota = $this->normalizeWilayahKey($row['kabkota'] ?? null);
        $kec  = $this->normalizeWilayahKey($row[19] ?? null);
        $kel  = $this->normalizeWilayahKey($row[20] ?? null);

        $wilayah = Wilayah::where('kecamatan', $kec)
            ->where('kelurahan', $kel)
            ->first();;

        if ($wilayah) {
            return [
                'id'        => $wilayah->id,
                'provinsi'  => $wilayah->provinsi,
                'kota'      => $wilayah->kota,
                'kecamatan' => $wilayah->kecamatan,
                'kelurahan' => $wilayah->kelurahan,
            ];
        }


        // =========================
        // SUPER ADMIN → CREATE BARU
        // =========================
        $wilayah = Wilayah::create([
            'provinsi'  => $this->wilayahUser['provinsi'],
            'kota'      => $this->wilayahUser['kota'],
            'kecamatan' => strtoupper($row[19]),
            'kelurahan' => strtoupper($row[20]),
        ]);

        return [
            'id'        => $wilayah->id,
            'provinsi'  => $wilayah->provinsi,
            'kota'      => $wilayah->kota,
            'kecamatan' => $wilayah->kecamatan,
            'kelurahan' => $wilayah->kelurahan,
        ];
    }

    protected function normalizeWilayahKey(?string $value): ?string
    {
        if (!$value) return null;

        $value = strtoupper(trim($value));

        $replace = [
            'KABUPATEN ' => 'KAB ',
            'KAB. ' => 'KAB ',
            'KOTA ' => 'KOTA ',
            '  ' => ' ',
        ];

        return str_replace(array_keys($replace), array_values($replace), $value);
    }

    protected function normalizeBeratGramToKg($berat)
    {
        if (is_null($berat) || $berat === '') {
            return null;
        }

        $berat = floatval(str_replace(',', '.', trim($berat)));

        // Jika berat lebih dari 100, anggap dalam gram
        if ($berat > 100) {
            return round($berat / 1000, 2); // Konversi ke kg
        }

        return round($berat, 2); // Sudah dalam kg
    }

    protected function normalizePanjangMToCM($panjang)
    {
        if (is_null($panjang) || $panjang === '') {
            return null;
        }

        $panjang = floatval(str_replace(',', '.', trim($panjang)));

        // Jika panjang kurang dari atau sama dengan 1, anggap dalam meter
        if ($panjang <= 1) {
            return round($panjang * 100, 2); // Konversi ke cm
        }

        return round($panjang, 2); // Sudah dalam cm
    }

    protected function normalizeJenisKelamin($jk)
    {
        if (is_null($jk) || $jk === '') {
            return null;
        }

        $jk = strtoupper(trim($jk));

        if (in_array($jk, ['L', 'LAKI-LAKI', 'PRIA', '1'])) {
            return 'L';
        } elseif (in_array($jk, ['P', 'PEREMPUAN', 'WANITA', '2'])) {
            return 'P';
        }

        return null; // Nilai tidak valid
    }

}

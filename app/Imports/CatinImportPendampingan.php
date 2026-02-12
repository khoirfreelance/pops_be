<?php

namespace App\Imports;

use App\Models\Catin;
use App\Models\Cadre;
use App\Models\Wilayah;
use App\Models\User;
use App\Models\Posyandu;
use App\Models\Log;
use App\Models\DampinganKeluarga;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;

class CatinImportPendampingan implements
    ToCollection,
    WithStartRow,
    WithCustomCsvSettings
{
    protected array $wilayahUser = [];
    protected string $posyanduUser = '';
    protected string $posyanduUserID = '';
    protected string $rtPosyandu = '';
    protected string $rwPosyandu = '';

    public function __construct(private int $userId)
    {
        $this->loadWilayahUser();
    }

    protected function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        $firstLine = fgets($handle);
        fclose($handle);

        $comma = substr_count($firstLine, ',');
        $semicolon = substr_count($firstLine, ';');

        return $semicolon > $comma ? ';' : ',';
    }

    public function getCsvSettings(): array
    {
        $path = request()->file('file')->getRealPath();

        return [
            'delimiter' => $this->detectDelimiter($path),
            'input_encoding' => 'UTF-8',
        ];
    }

    public function startRow(): int
    {
        return 2; // skip header
    }

    public function collection(Collection $rows)
    {
        try {
            foreach ($rows as $row) {
                $this->processRow($row->toArray());
            }
        } catch (\Exception $e) {
            // ✅ expected error
            if ($e->getCode() === 1001) {
                throw new \Exception($e->getMessage());
            }

            // ❌ error teknis
            Log::error('Import CSV error teknis', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            throw new \Exception(
                'Gagal import data, silahkan check dan bandingkan kembali format csv dengan contoh yang diberikan.'
            );
        }
    }

    private function processRow(array $row): void
    {
        $berat = $row[21] != null ? $this->normalizeDecimal($row[21]) : null;
        $tinggi = $row[22] != null ? $this->normalizeDecimal($row[22]) : null;
        $lila = $row[26] != null ? $this->normalizeDecimal($row[26]) : null;
        $hb = $row[24] != null ? $this->normalizeDecimal($row[24]) : null;
        $usia_perempuan = $row[6] != null ? (int) $row[6] : null;

        DB::transaction(function () use ($row, $berat, $tinggi, $lila, $hb, $usia_perempuan) {
            // =========================
            // INTRO
            // =========================
            $user = Auth::user();
            $wilayahData = $this->resolveWilayahFromRow($row);

            // =========================
            // 0. Validasi data import
            // =========================
            if (!preg_match('/^[0-9`]+$/', $row[4])) {
                throw new \Exception(
                    "NIK hanya boleh berisi angka dan karakter `",
                    1001
                );
            }

            $nik = $this->normalizeNIK($row[4] ?? null);
            $nama = $this->normalizeText($row[3] ?? null);
            $tglUkur = $this->convertDate($row[2] ?? null);

            if (!$nik || !$tglUkur) {
                throw new \Exception(
                    "NIK atau tanggal pendampingan kosong / tidak valid pada data {$nama}",
                    1001
                );
            }

            $duplikat = Catin::where('nik_perempuan', $nik)
                ->whereDate('tanggal_pendampingan', $tglUkur)
                ->first();

            if ($duplikat) {
                throw new \Exception(
                    "Data atas <strong>{$nik}</strong>, <strong>{$nama}</strong> sudah diunggah pada <strong>"
                    . $duplikat->created_at->format('d-m-Y')."</strong>",
                    1001
                );
            }

            $catin = Catin::create(attributes: [
                'nama_petugas' => $this->normalizeText($row[1] ?? null),
                'tanggal_pendampingan' => $this->convertDate($row[2] ?? null),

                'nama_perempuan' => $this->normalizeText($row[3] ?? null),
                'nik_perempuan' => $this->normalizeNIK($row[4] ?? null),
                'pekerjaan_perempuan' => $this->normalizeText($row[5] ?? null),
                'usia_perempuan' => $usia_perempuan ?? 0,
                'hp_perempuan' => $row[7] ?? null,

                'nama_laki' => $this->normalizeText($row[8] ?? null),
                'nik_laki' => $this->normalizeNIK($row[9] ?? null),
                'pekerjaan_laki' => $this->normalizeText($row[10] ?? null),
                'usia_laki' => $row[11] ?? 0,
                'hp_laki' => $row[12] ?? null,

                'pernikahan_ke' => $row[13] ?? null,

                //'provinsi' => $this->wilayahUser['provinsi'] ?? null,
                //'kota' => $this->wilayahUser['kota'] ?? null,
                'provinsi' => $wilayahData['provinsi'] ?? null ,
                'kota' => $wilayahData['kota'] ?? null ,
                'kecamatan' => $wilayahData['kecamatan'] ?? null ,
                'kelurahan' => $wilayahData['kelurahan'] ?? null ,

                'rw' => $row[18] ?? null,
                'rt' => $row[19] ?? null,
                'posyandu' => $this->normalizeText($this->posyanduUser ?? null),

                'tanggal_pemeriksaan' => $row[20] != null ? $this->convertDate($row[20]) : null,
                'berat_perempuan' => $berat,
                'tinggi_perempuan' => $tinggi,
                'hb_perempuan' => $hb,
                'lila_perempuan' => $lila,
                'terpapar_rokok' => $this->toBool($row[28] ?? null),
                'mendapat_ttd' => $this->toBool($row[29] ?? null),
                'menggunakan_jamban' => $this->toBool($row[30] ?? null),
                'sumber_air_bersih' => $this->toBool($row[31] ?? null),
                'punya_riwayat_penyakit' => $this->toBool($row[32] ?? null),
                'riwayat_penyakit' => $row[33] ?? null,
                'mendapat_fasilitas_rujukan' => $this->toBool($row[34] ?? null),
                'mendapat_kie' => $this->toBool($row[35] ?? null),
                'mendapat_bantuan_pmt' => $this->toBool($row[36] ?? null),

                'tanggal_rencana_menikah' => $this->convertDate($row[37] ?? null),
                'rencana_tinggal' => $row[38] ?? null,

                'imt' => $this->hitungIMT($berat, $tinggi),
                'status_kek' => $this->statusKEK($lila),
                'status_hb' => $this->statusHB($hb),
                'status_risiko' => $this->statusRisiko($usia_perempuan),

            ]);

            /* $wilayah = Wilayah::firstOrCreate([
                'provinsi' => $this->normalizeText($row[14] ?? null),
                'kota' => $this->normalizeText($row[15] ?? null),
                'kecamatan' => $this->normalizeText($row[16] ?? null),
                'kelurahan' => $this->normalizeText($row[17] ?? null),

            ]); */

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

            /* $user = User::where('name', strtoupper($row[1]))
                ->whereHas('cadre', function ($q) {
                    $q->where('id_posyandu', $this->posyanduUser)
                    ->whereHas('posyandu', function ($p) {
                        $p->where('rt', $this->rtPosyandu)
                            ->where('rw', $this->rwPosyandu);
                    });
                })
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

            $posyanduID = $user->role === 'Super Admin'? $posyandu->id : $this->posyanduUserID;

            $cadre = Cadre::firstOrCreate([
                'id_tpk' => null,
                'id_user' => $user->id,
                'id_posyandu' => $posyanduID,
                'status' => 'non-kader',
            ]); */

            $dampinganKeluarga = DampinganKeluarga::firstOrCreate([
                'id_pendampingan' => $catin->id,
                'id_keluarga' => 0,
                'id_tpk' => $cadre->id,
                'jenis' => 'CATIN',
            ]);
            // Takeout cause posyandu get from cadre of user doing the import
            // Posyandu::firstOrCreate([
            //     'nama_posyandu' => $row['posyandu'] ?? '-',
            //     'id_wilayah' => $wilayah->id,
            //     'rt' => ltrim($row['rt'], "0") ?? null,
            //     'rw' => ltrim($row['rw'], "0") ?? null,
            // ]);

            Log::create([
                'id_user' => $this->userId,
                'context' => 'Catin',
                'activity' => 'Import data calon pengantin ' . ($row['nama_perempuan'] ?? '-'),
                'timestamp' => now(),
            ]);
        });
    }

    /* =========================
     * Helper functions
     * ========================= */

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

    private function normalizeText($value)
    {
        return $value ? strtoupper(trim($value)) : null;
    }

    private function toBool($val)
    {
        return in_array(strtolower(trim($val)), ['ya', 'y', 'true', '1']) ? true : false;
    }

    private function convertDate($date)
    {
        if (!$date) {
            return null;
        }

        $date = trim($date);

        // ✅ Format yang diizinkan
        $acceptedFormats = [
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

    protected function loadWilayahUser(): void
    {
        $user = User::find($this->userId);
        $this->posyanduUser = 'UNKNOWN';

        if (!$user || !$user->id_wilayah) {
            $this->wilayahUser = [
                'id' => null,
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
            $this->posyanduUserID = $cadre->posyandu->id;
            $this->posyanduUser = $cadre->posyandu->nama_posyandu;
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
    protected function resolveWilayahFromRow(array $row): array
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

        $prov = $this->normalizeWilayahKey($row[14] ?? null);
        $kota = $this->normalizeWilayahKey($row[15] ?? null);
        $kec  = $this->normalizeWilayahKey($row[16] ?? null);
        $kel  = $this->normalizeWilayahKey($row[17] ?? null);

        $wilayah = Wilayah::where('provinsi', $prov)
            ->where('kota', $kota)
            ->where('kecamatan', $kec)
            ->where('kelurahan', $kel)
            ->first();

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
            'provinsi' => $this->normalizeText($row[14] ?? null),
            'kota' => $this->normalizeText($row[15] ?? null),
            'kecamatan' => $this->normalizeText($row[16] ?? null),
            'kelurahan' => $this->normalizeText($row[17] ?? null),
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

    private function hitungIMT($berat, $tinggi)
    {
        if (empty($berat) || empty($tinggi))
            return null;

        // kalau tinggi kemungkinan cm, valid range antara 100-200
        if ($tinggi < 50) {
            // kemungkinan user salah input (meter bukan cm)
            $tinggi = $tinggi * 100;
        }

        $tinggi_m = $tinggi / 100;
        $imt = round($berat / ($tinggi_m * $tinggi_m), 1);

        // batas aman IMT manusia biasanya 10-60
        return ($imt > 5 && $imt < 80) ? $imt : null;
    }

    private function statusKEK($lila)
    {
        if (is_null($lila))
            return null;
        return $lila < 23.5 ? 'KEK' : 'Normal';
    }

    private function statusHB($hb)
    {
        if (is_null($hb))
            return null;
        return $hb < 12 ? 'Anemia' : 'Normal';
    }

    private function statusRisiko($usia_perempuan)
    {
        if (is_null($usia_perempuan))
            return null;
        return ($usia_perempuan < 20 || $usia_perempuan > 35) ? 'Berisiko' : 'Normal';
    }

    private function normalizeDecimal(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // jika ada koma dan titik, asumsikan:
        // titik = ribuan, koma = desimal (ID/EU)
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }
        // jika hanya koma → desimal
        elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}

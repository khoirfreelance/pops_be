<?php

namespace App\Imports;

use App\Models\{Pregnancy, Wilayah, Log, User, Cadre, DampinganKeluarga, Keluarga, AnggotaKeluarga};
use Carbon\Carbon;
use App\Models\Posyandu;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\{
  ToCollection,
  WithStartRow,
  WithCustomCsvSettings
};

class PregnancyImportPendampingan implements
    ToCollection,
    WithStartRow,
    WithCustomCsvSettings
{
    protected array $wilayahUser = [];
    protected string $posyanduUser = '';
    protected string $posyanduUserID = '';
    //protected string $rtPosyandu = '';
    //protected string $rwPosyandu = '';

    public function __construct(private int $userId)
    {
        $this->loadWilayahUser();
    }

    public function startRow(): int
    {
        return 2; // skip header
    }

    public function getCsvSettings(): array
    {
        $path = request()->file('file')->getRealPath();

        return [
        'delimiter' => $this->detectDelimiter($path),
        'input_encoding' => 'UTF-8',
        ];
    }

    protected function detectDelimiter(string $path): string
    {
        $line = fgets(fopen($path, 'r'));
        return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
    }

    public function collection(Collection $rows): void
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

            throw new \Exception(
                'Gagal import data, silahkan check dan bandingkan kembali format csv dengan contoh yang diberikan.',422,$e
            );
        }
    }

    protected function processRow(array $row): void
    {
        DB::transaction(function () use ($row) {


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
                    "NIK atau tanggal pendampingan kosong / tidak valid pada data {$nama}", 1001
                );
            }

            $duplikat = Pregnancy::where('nik_ibu', $nik)
                ->whereDate('tanggal_pendampingan', $tglUkur)
                ->first();

            if ($duplikat) {
                throw new \Exception(
                    "Data atas <strong>{$nik}</strong>, <strong>{$nama}</strong> sudah diunggah pada <strong>"
                    . $duplikat->created_at->format('d-m-Y')."</strong>",
                    1001
                );
            }

            $jum_anak = $this->normalizeDecimal($row[11] ?? null);
            $berat = $this->normalizeDecimal($row[23] ?? null);
            // HARD VALIDATION (WAJIB)
            if ($berat !== null && ($berat < 20 || $berat > 999)) {
                $berat = null;
            }
            $tinggi = $this->meterToCm($this->normalizeDecimal($row[24] ?? null));
            // HARD VALIDATION (WAJIB)
            if ($tinggi !== null && ($tinggi < 50 || $tinggi > 999)) {
                $tinggi = null;
            }

            $hb = $this->normalizeDecimal($row[26] ?? null);
            // HARD VALIDATION (WAJIB)
            if ($hb !== null && ($hb < 5 || $hb > 999)) {
                $hb = null;
            }
            $lila = $this->normalizeDecimal($row[28] ?? null);
            // HARD VALIDATION (WAJIB)
            if ($lila !== null && ($lila < 10 || $lila > 999)) {
                $lila = null;
            }
            $usia = isset($row[5]) ? (int) $row[5] : null;
            // HARD VALIDATION (WAJIB)
            if ($usia !== null && ($usia < 10 || $usia > 999)) {
                $usia = null;
            }

            $imt = $this->hitungIMT($berat, $tinggi);

            $pregnancy = Pregnancy::create([
                'nama_petugas' => $this->normalizeText($row[1] ?? null),
                'tanggal_pendampingan' => $this->convertDate($row[2] ?? null),

                'nama_ibu' => $this->normalizeText($row[3] ?? null),
                'nik_ibu' => $this->normalizeNIK($row[4] ?? null),
                'usia_ibu' => $usia,

                'nama_suami' => $this->normalizeText($row[6] ?? null),
                'nik_suami' => $this->normalizeNIK($row[7] ?? null),
                'pekerjaan_suami' => $this->normalizeText($row[8] ?? null),
                'usia_suami' => isset($row[9]) ? (int) $row[9] : null,

                'kehamilan_ke' => $row[10] ?? null,
                'jumlah_anak' => $jum_anak,
                'status_kehamilan' => $row[12] ?? null,
                'riwayat_4t' => $row[13] ?? null,

                'riwayat_penggunaan_kb' => $row[14] ?? null,
                'riwayat_ber_kontrasepsi' => $row[15] ?? null,
                'provinsi'   => $wilayahData['provinsi'],
                'kota'       => $wilayahData['kota'],
                'kecamatan'  => $wilayahData['kecamatan'],
                'kelurahan'  => $wilayahData['kelurahan'],
                'rt' => $row[20] ?? null,
                'rw' => $row[21] ?? null,

                'tanggal_pemeriksaan_terakhir' => $this->convertDate($row[22] ?? null),
                'berat_badan' => $berat,
                'tinggi_badan' => $tinggi,
                'kadar_hb' => $hb,
                'lila' => $lila,

                'imt' => $imt,
                'status_gizi_hb' => $hb !== null ? ($hb < 11 ? 'Anemia' : 'Normal') : null,
                'status_gizi_lila' => $lila !== null ? ($lila < 23.5 ? 'KEK' : 'Normal') : null,
                'status_risiko_usia' => ($usia < 20 || $usia > 35) ? 'Berisiko' : 'Normal',

                'riwayat_penyakit' => $row[30] ?? null,
                'usia_kehamilan_minggu' => (int) ($row[31] ?? 0),
                'taksiran_berat_janin' => $row[32] ?? null,
                'tinggi_fundus' => $row[33] ?? null,
                'hpl' => $this->convertDate($row[34] ?? null),

                'terpapar_asap_rokok' => $this->toBool($row[35] ?? null),
                'mendapat_ttd' => $this->toBool($row[36] ?? null),
                'menggunakan_jamban' => $this->toBool($row[37] ?? null),
                'menggunakan_sab' => $this->toBool($row[38] ?? null),
                'fasilitas_rujukan' => $this->toBool($row[39] ?? null),
                'riwayat_keguguran_iufd' => $this->toBool($row[40] ?? null),
                'mendapat_kie' => $this->toBool($row[41] ?? null),
                'mendapat_bantuan_sosial' => $this->toBool($row[42] ?? null),

                'rencana_tempat_melahirkan' => $row[43] ?? null,
                'rencana_asi_eksklusif' => $row[44] ?? null,
                'rencana_tinggal_setelah' => $row[45] ?? null,
                'rencana_kontrasepsi' => $row[46] ?? null,

                'posyandu' => $this->posyanduUser ?? null,
            ]);

            /* $wilayah = Wilayah::firstOrCreate([
                'provinsi' => $this->normalizeText($pregnancy->provinsi),
                'kota' => $this->normalizeText($pregnancy->kota),
                'kecamatan' => $this->normalizeText($pregnancy->kecamatan),
                'kelurahan' => $this->normalizeText($pregnancy->kelurahan),
            ]); */

            $noKK = $pregnancy['nik_suami'] ?? null;

            if ($noKK) {
                $keluarga = Keluarga::firstOrCreate(
                    [
                        'no_kk' => $noKK,
                    ],
                    [
                        'alamat' => 'Desa: ' . $wilayahData['kelurahan'],
                        'rt' => $pregnancy['rt'] ?? null,
                        'rw' => $pregnancy['rw'] ?? null,
                        'id_wilayah' => $wilayahData['id'],
                        'is_pending' => true,
                    ]
                );
                $anggota_ayah = [
                    'id_keluarga' => $keluarga->id,
                    'nama' => $pregnancy['nama_suami'],
                    'nik' => $pregnancy['nik_suami'],
                    'jenis_kelamin' => 'L',
                    'status_hubungan' => 'Kepala Keluarga',
                    'is_pending' => true,
                ];

                $anggota_ibu = [
                    'id_keluarga' => $keluarga->id,
                    'nama' => $pregnancy['nama_ibu'],
                    'nik' => $pregnancy['nik_ibu'],
                    'jenis_kelamin' => 'P',
                    'status_hubungan' => 'Istri',
                    'is_pending' => true,
                ];

                if ($pregnancy['nik_suami']) {
                    AnggotaKeluarga::firstOrCreate(["nik" => $anggota_ayah['nik']], $anggota_ayah);
                }else{
                    AnggotaKeluarga::create($anggota_ayah);
                }

                if ($pregnancy['nik_ibu']) {
                    AnggotaKeluarga::firstOrCreate(["nik" => $anggota_ibu['nik']], $anggota_ibu);
                }else{
                    AnggotaKeluarga::create($anggota_ibu);
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
                'id_pendampingan' => $pregnancy->id,
                'id_keluarga' => $keluarga->id,
                'id_tpk' => $cadre->id,
                'jenis' => 'BUMIL',
            ]);
            // Takeout cause posyandu get from cadre of user doing the import
            // Posyandu::firstOrCreate([
            //     'nama_posyandu' => $pregnancy->posyandu ?? '-',
            //     'rt' => $pregnancy->rt,
            //     'rw' => $pregnancy->rw,
            // ]);

            Log::create([
                'id_user' => Auth::id(),
                'context' => 'Ibu Hamil',
                'activity' => 'Import data kehamilan ibu ' . ($row[3] ?? '-'),
                'timestamp' => now(),
            ]);
        });
    }

    /* ================= HELPERS ================= */

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

    private function meterToCm(?float $value): ?float
    {
        if (!$value)
        return null;
        return $value < 3 ? $value * 100 : $value;
    }

    private function hitungIMT($berat, $tinggi): ?float
    {
        if (!$berat || !$tinggi)
        return null;
        return round($berat / pow($tinggi / 100, 2), 1);
    }

    private function normalizeText($value)
    {
        return $value ? strtoupper(trim($value)) : null;
    }

    private function normalizeDecimal(?string $value): ?float
    {
        if (!$value)
        return null;
        $value = str_replace(',', '.', trim($value));
        return is_numeric($value) ? (float) $value : null;
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

    private function normalizeNIK($nik)
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

    private function toBool($val): bool
    {
        return in_array(strtolower(trim((string) $val)), ['ya', 'y', '1', 'true']);
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

        $prov = $this->normalizeWilayahKey($row[16] ?? null);
        $kota = $this->normalizeWilayahKey($row[17] ?? null);
        $kec  = $this->normalizeWilayahKey($row[18] ?? null);
        $kel  = $this->normalizeWilayahKey($row[19] ?? null);

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
            'provinsi'  => $this->normalizeText($row[16] ?? null),
            'kota'      => $this->normalizeText($row[17] ?? null),
            'kecamatan' => $this->normalizeText($row[18] ?? null),
            'kelurahan' => $this->normalizeText($row[19] ?? null),
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
}

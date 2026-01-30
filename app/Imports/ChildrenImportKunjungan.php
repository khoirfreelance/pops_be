<?php

namespace App\Imports;

use App\Models\Kunjungan;
use App\Models\Wilayah;
use App\Models\Posyandu;
use App\Models\Keluarga;
use App\Models\Log;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;

class ChildrenImportKunjungan implements
    ToModel,
    WithHeadingRow,
    WithCustomCsvSettings
{
    protected array $wilayahUser = [];
    public function __construct(private int $userId) {
        $this->loadWilayahUser();
    }

    // Irul Additional
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

    public function model(array $row)
    {
        return DB::transaction(function () use ($row) {
            // =========================
            // INTRO
            // =========================
            $user = Auth::user();
            $wilayahData = $this->resolveWilayahFromRow($row);

            // =========================
            // 0. Validasi data import
            // =========================
            $nik = $this->normalizeNik($row['nik'] ?? null);
            $nama = $this->normalizeText($row['nama'] ?? '-');
            $tglUkur = $this->convertDate($row['tanggal_pengukuran'] ?? null);

            if (!$nik || !$tglUkur) {
                throw new \Exception(
                    "NIK atau tanggal pengukuran kosong / tidak valid pada data {$nama}",
                    1001
                );
            }

            $duplikat = Kunjungan::where('nik', $nik)
                ->whereDate('tgl_pengukuran', $tglUkur)
                ->first();

            if ($duplikat) {
                throw new \Exception(
                    "Data atas NIK {$nik}, nama {$nama} sudah diunggah pada "
                    . $duplikat->created_at->format('d-m-Y'),
                    1001
                );
            }

            // =========================
            // 1. Parse tanggal
            // =========================
            $tglLahir = $this->convertDate($row['tgl_lahir'] ?? null);
            $tglUkur  = $this->convertDate($row['tanggal_pengukuran'] ?? null);

            // =========================
            // 2. Hitung usia & status
            // =========================
            $usia = $this->hitungUmurBulan($tglLahir, $tglUkur);
            $z_bbu  =  $this->normalizeDecimal($row["zs_bbu"] ?? null);
            $z_tbu  =  $this->normalizeDecimal($row["zs_tbu"]?? null);
            $z_bbtb = $this->normalizeDecimal( $row["zs_bbtb"]??null);

            // =========================
            // 3. Status gizi
            // =========================
            $status_bbu  = $this->statusBB($row["bbu"]);
            $status_tbu  = $this->statusTB($row["tbu"]);
            $status_bbtb = $this->statusBBTB($row["bbtb"]);

            $naikBB = $this->normalizeNaikBeratBadan($row["naik_berat_badan"]);

            $jkRaw = trim($row['jk'] ?? '');
            $jk = strtoupper($jkRaw);

            $validateJK = in_array($jk, ['L', 'P']);

            if (!$validateJK) {
                throw new \Exception(
                    "Format salah pada kolom JK. Nilai: '{$jkRaw}'. Gunakan L atau P.",
                    1001
                );
            }

            // =========================
            // 4. Simpan Kunjungan
            // =========================
            $kunjungan = Kunjungan::create([
                'nik' => $this->normalizeNik($row['nik'] ?? null),
                'nama_anak' => $this->normalizeText($row['nama'] ?? null),
                'jk' => $this->normalizeText($jk ?? null),
                'tgl_lahir' => $tglLahir,
                'bb_lahir' =>  $this->normalizeDecimal($row['bb_lahir']??null),
                'tb_lahir' => $this->normalizeDecimal($row['tb_lahir']??null),
                'nama_ortu' => $this->normalizeText($row['nama_ortu'] ?? null),
                'alamat' => $this->normalizeText($row['alamat'])?? null,
                'rt' => $row['rt']?? null,
                'rw' => $row['rw']?? null,
                'puskesmas' => $this->normalizeText($row['pukesmas']?? null),
                'posyandu' => $this->normalizeText($row['posyandu']?? null),
                'tgl_pengukuran' => $tglUkur,
                'usia_saat_ukur' => $usia,
                'bb' =>  $this->normalizeDecimal($row['berat']??null),
                'tb' =>  $this->normalizeDecimal($row['tinggi']??null),
                'lila' => $this->normalizeDecimal($row['lila'] ?? null),
                'provinsi'   => $wilayahData['provinsi'],
                'kota'       => $wilayahData['kota'],
                'kecamatan'  => $wilayahData['kecamatan'],
                'kelurahan'  => $wilayahData['kelurahan'],
                'bb_u' => $this->normalizeStatus($status_bbu),
                'zs_bb_u' => $z_bbu,
                'tb_u' => $this->normalizeStatus($status_tbu),
                'zs_tb_u' => $z_tbu,
                'bb_tb' => $this->normalizeStatus($status_bbtb),
                'zs_bb_tb' => $z_bbtb,
                'naik_berat_badan' => $naikBB,
                'catatan' => 'Perekaman data '.$row['nama'].' pada '.$tglUkur.' dengan hasil '.$this->normalizeStatus($status_bbtb).' secara import data',
                'petugas' => $user->name,
            ]);

            // =========================
            // 5. Posyandu
            // =========================
            /* $wilayah = Wilayah::firstOrCreate([
                'provinsi' => $this->normalizeText($row['prov']) ?? $this->wilayahUser['provinsi'],
                'kota' => $this->normalizeText($row['kabkota']) ?? $this->wilayahUser['kota'],
                'kecamatan' => $this->normalizeText($row['kec']) ?? $this->wilayahUser['kecamatan'],
                'kelurahan' => $this->normalizeText($row['desakel']) ?? $this->wilayahUser['kelurahan'],
            ]); */

            Posyandu::firstOrCreate([
                'nama_posyandu' => $this->normalizeText($row['posyandu']),
                'id_wilayah' => $wilayahData['id'],
                'rt' => $row['rt'] ?? null,
                'rw' => $row['rw'] ?? null,
            ]);

            // =========================
            // 6. Keluarga
            // =========================
            if (!empty($row['no_kk'] ?? null)) {
                Keluarga::firstOrCreate(
                    ['no_kk' => $row['no_kk']],
                    [
                        'alamat' => $this->normalizeText($row['alamat']),
                        'rt' => $row['rt'],
                        'rw' => $row['rw'],
                        'id_wilayah' => $wilayahData['id'],
                        'is_pending' => false,
                    ]
                );
            }

            // =========================
            // 7. Log
            // =========================
            Log::create([
                'id_user' => $this->userId,
                'context' => 'Anak',
                'activity' => 'Import data anak ' . ($row['nama'] ?? '-'),
                'timestamp' => now(),
            ]);

            return $kunjungan;
        });
    }

    /* ======================
     * Helper (reuse existing)
     * ====================== */

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

    private function normalizeStatus($value)
    {
        return $value ? ucwords(trim($value)) : null;
    }

    // Irul Custom ConvertDate
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

    /** Hitung umur (bulan) */
    private function hitungUmurBulan($tglLahir, $tglUkur)
    {
        if (!$tglLahir || !$tglUkur)
            return null;

        $lahir = new \DateTime($tglLahir);
        $ukur = new \DateTime($tglUkur);
        $diff = $lahir->diff($ukur);

        return (int) floor($diff->y * 12 + $diff->m + ($diff->d / 30));
    }
    private function normalizeDecimal($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        // ganti koma menjadi titik
        $value = str_replace(',', '.', $value);

        return is_numeric($value) ? (float) $value : null;
    }

    private function statusBB($status){
        $beratBadan = [
            "Sangat Kurang" => "Severely Underweight",
            "Kurang" => "Underweight",
            "Normal" => "Normal",
            "Risiko Berat Badan Lebih" => "Risiko BB Lebih",
            "BB Lebih" => "BB Lebih",
        ];
        return $beratBadan[$status] ?? null;
    }

    private function statusTB($status){
        $tinggiBadan = [
            "Sangat Pendek" => "Severely Stunted",
            "Pendek" => "Stunted",
            "Normal" => "Normal",
            "Tinggi" => "Tinggi",
        ];
        return $tinggiBadan[$status] ?? null;
    }

    private function statusBBTB($status){
        $bbTb = [
            "Gizi Buruk" => "Severely Wasted",
            "Gizi Kurang" => "Wasted",
            "Gizi Baik" => "Normal",
            "Risiko Gizi Lebih" => "Possible Risk of Overweight",
            "Gizi Lebih" => "Overweight",
            "Obesitas" => "Obese",
        ];
        return $bbTb[$status] ?? null;
    }

    private function normalizeNaikBeratBadan($value)
    {
        if (is_null($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        if (in_array($value, ['yes', 'ya', '1', 'true', 'T'])) {
            return true;
        } elseif (in_array($value, ['no', 'tidak', '0', 'false', 'F'])) {
            return false;
        }

        return null;
    }

    protected function loadWilayahUser(): void
    {
        $user = User::find($this->userId);

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
        $prov = $this->normalizeWilayahKey($row['prov'] ?? null);
        $kota = $this->normalizeWilayahKey($row['kabkota'] ?? null);
        $kec  = $this->normalizeWilayahKey($row['kec'] ?? null);
        $kel  = $this->normalizeWilayahKey($row['desakel'] ?? null);

        $wilayah = Wilayah::where('provinsi', $prov)
            ->where('kota', $kota)
            ->where('kecamatan', $kec)
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
            'provinsi'  => $this->normalizeText($row['prov']),
            'kota'      => $this->normalizeText($row['kabkota']),
            'kecamatan' => $this->normalizeText($row['kec']),
            'kelurahan' => $this->normalizeText($row['desakel']),
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

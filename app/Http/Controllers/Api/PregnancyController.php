<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Pregnancy;
use App\Models\Intervensi;
use App\Models\Cadre;
use Carbon\Carbon;
use App\Models\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\DB;
use App\Imports\PregnancyImportPendampingan;

class PregnancyController extends Controller
{
    public function getData(Request $request)
    {
        try {
            $data = Pregnancy::get();

            $dataRaw = $data;

            if (!empty($request->provinsi)) {
                $data = $data->filter(function ($item) use ($request) {
                    return $item->provinsi === $request->provinsi;
                });
            }
            if (!empty($request->kota)) {
                $data = $data->filter(function ($item) use ($request) {
                    return $item->kota === $request->kota;
                });
            }
            if (!empty($request->kecamatan)) {
                $data = $data->filter(function ($item) use ($request) {
                    return $item->kecamatan === $request->kecamatan;
                });
            }
            if (!empty($request->kelurahan)) {
                $data = $data->filter(function ($item) use ($request) {
                    return $item->kelurahan === $request->kelurahan;
                });
            }

            if ($request->filled('periodeAwal') && $request->filled('periodeAkhir')) {
                // Daftar bulan dalam Bahasa Indonesia
                $bulanMap = [
                    'januari' => 1,
                    'februari' => 2,
                    'maret' => 3,
                    'april' => 4,
                    'mei' => 5,
                    'juni' => 6,
                    'juli' => 7,
                    'agustus' => 8,
                    'september' => 9,
                    'oktober' => 10,
                    'november' => 11,
                    'desember' => 12,
                ];

                // Fungsi bantu untuk konversi teks ke tanggal
                $parsePeriode = function ($periode) use ($bulanMap) {
                    $parts = explode(' ', trim(strtolower($periode))); // ex: ["maret", "2025"]
                    if (count($parts) === 2 && isset($bulanMap[$parts[0]])) {
                        $bulan = $bulanMap[$parts[0]];
                        $tahun = (int) $parts[1];
                        return Carbon::createFromDate($tahun, $bulan, 1);
                    }
                };

                $start = $parsePeriode($request->periodeAwal)->startOfMonth()->format('Y-m-d');
                $end = $parsePeriode($request->periodeAkhir)->endOfMonth()->format('Y-m-d');
                // dd($start, $end);
                $data = $data->filter(function ($item) use ($start, $end) {
                    // dd($item->tanggal_pemeriksaan_terakhir >= $start && $item->tanggal_pendampingan <= $end);
                    return $item->tanggal_pendampingan >= $start && $item->tanggal_pendampingan <= $end;
                });
            }

            if ($request->filled('status') && is_array($request->status)) {
                $data = $data->filter(function ($q) use ($request) {

                    foreach ($request->status as $status) {
                        $statusLower = strtolower($status);

                        // KEK
                        if (str_contains($statusLower, 'kek')) {
                            if (!empty($q->status_gizi_lila) && $q->status_gizi_lila === 'KEK') {
                                return true;
                            }
                        }

                        // Anemia
                        if (str_contains($statusLower, 'anemia')) {
                            if (!empty($q->status_gizi_hb) && $q->status_gizi_hb === 'Anemia') {
                                return true;
                            }
                        }

                        // Risiko Usia
                        if (str_contains($statusLower, 'risiko')) {
                            if (!empty($q->status_risiko_usia) && $q->status_risiko_usia === 'Berisiko') {
                                return true;
                            }
                        }
                    }

                    // tidak ada satupun yang cocok
                    return false;
                });
            }

            if ($request->filled('usia') && is_array($request->usia)) {
                $data = $data->filter(function ($q) use ($request) {
                    $usia = $q->usia_ibu ?? null;
                    if (!$usia)
                        return false;

                    foreach ($request->usia as $range) {
                        $range = trim($range);

                        // < 19 Tahun
                        if ($range === '< 20 Tahun' && $usia < 20) {
                            return true;
                        }

                        // >= 35 Tahun
                        if ($range === '>= 35 Tahun' && $usia >= 35) {
                            return true;
                        }

                        // 19 - 34 Tahun
                        if ($range === '20 - 34 Tahun' && $usia >= 20 && $usia <= 34) {
                            return true;
                        }

                        // fallback jika ada format "X - Y"
                        if (str_contains($range, '-')) {
                            [$min, $max] = array_map('trim', explode('-', $range));
                            if ($usia >= (int) $min && $usia <= (int) $max) {
                                return true;
                            }
                        }
                    }
                });
            }
            /* if ($request->filled('usia') && is_array($request->usia)) {
                $data = $data->filter(function ($q) use ($request) {

                    if (empty($q->usia_ibu)) {
                        return false; // usia kosong memang tidak lolos filter usia
                    }

                    foreach ($request->usia as $range) {
                        $range = trim($range);

                        if ($range === '<20' && $q->usia_ibu < 20) {
                            return true;
                        }

                        if ($range === '>= 35' && $q->usia_ibu > 34) {
                            return true;
                        }

                        if (str_contains($range, '-')) {
                            [$min, $max] = array_map('intval', explode('-', $range));
                            if ($q->usia_ibu >= $min && $q->usia_ibu <= $max) {
                                return true;
                            }
                        }
                    }

                    return false;
                });
            } */

            if ($request->filled('posyandu')) {
                $data = $data->filter(function ($q) use ($request) {
                    if ($request->filled('rw')) {
                        if ($request->filled('rt')) {
                            return strtolower($q->posyandu) == strtolower($request->posyandu) &&
                                strtolower($q->rw) == strtolower($request->rw) &&
                                strtolower($q->rt) == strtolower($request->rt);
                        }
                        return strtolower($q->posyandu) == strtolower($request->posyandu) &&
                            strtolower($q->rw) == strtolower($request->rw);
                    }
                    return strtolower($q->posyandu) == strtolower($request->posyandu);
                });
            }

            $counts = [
                [
                    "title" => "Anemia",
                    "value" => 0,
                    "percent" => "0%",
                    "color" => "warning"
                ],
                [
                    "title" => "KEK",
                    "value" => 0,
                    "percent" => "0%",
                    "color" => "danger"
                ],
                [
                    "title" => "Berisiko",
                    "value" => 0,
                    "percent" => "0%",
                    "color" => "violet"
                ],
                [
                    "title" => "Normal",
                    "value" => 0,
                    "percent" => "0%",
                    "color" => "success"
                ],
                [
                    "title" => "Total Ibu Hamil",
                    "value" => 0,
                    "percent" => "0%",
                    "color" => "secondary"
                ],
            ];

            if ($data->isEmpty()) {
                return response()->json([
                    'message' => 'Tidak ada data kehamilan ditemukan.',
                    'data' => [],
                    'counts' => $counts
                ], 200);
            }
            $intervensiList = Intervensi::where('status_subjek', 'bumil')->orderBy('tgl_intervensi')->get();
            // ✅ Group data per ibu
            $groupedData = $data->map(function ($group) use ($intervensiList, $dataRaw) {

                $intervensi = $intervensiList->Where('nik_subjek', $group->nik_ibu)->map(function ($item) {

                    return [
                        'kader' => $item->petugas,
                        'tanggal' => $item->tgl_intervensi,
                        'intervensi' => $item->kategori,
                    ];
                })->values();

                $riwayat = $dataRaw->Where('nik_ibu', $group->nik_ibu)->sortBy('tanggal_pemeriksaan_terakhir')->map(function ($g) {
                    return [
                        'tanggal_pemeriksaan_terakhir' => $g->tanggal_pemeriksaan_terakhir,
                        'berat_badan' => $g->berat_badan,
                        'tinggi_badan' => $g->tinggi_badan,
                        'imt' => $g->imt,
                        'kadar_hb' => $g->kadar_hb,
                        'status_gizi_hb' => $g->status_gizi_hb,
                        'lila' => $g->lila,
                        'status_risiko_usia' => $g->status_risiko_usia,
                        'status_gizi_lila' => $g->status_gizi_lila,
                        'usia_kehamilan_minggu' => $g->usia_kehamilan_minggu,
                        'posyandu' => $g->posyandu,
                    ];
                })->first();
                //})->values();

                return [
                    'nama_ibu' => $group->nama_ibu,
                    'nik_ibu' => $group->nik_ibu,
                    'usia_ibu' => $group->usia_ibu,
                    'nama_suami' => $group->nama_suami,
                    'nik_suami' => $group->nik_suami,
                    'kehamilan_ke' => $group->kehamilan_ke,
                    'jumlah_anak' => $group->jumlah_anak,
                    'status_risiko_usia' => $group->status_risiko_usia,
                    'status_gizi_hb' => $group->status_gizi_hb,
                    'status_gizi_lila' => $group->status_gizi_lila,
                    'provinsi' => $group->provinsi,
                    'kota' => $group->kota,
                    'kecamatan' => $group->kecamatan,
                    'kelurahan' => $group->kelurahan,
                    'rt' => $group->rt,
                    'rw' => $group->rw,
                    'tanggal_pendampingan' => $group->tanggal_pendampingan,
                    'riwayat_pemeriksaan' => $riwayat,
                    'hpl' => $group->first()->hpl,
                    'intervensi' => $intervensi ?? null,
                ];
            });
            if ($request->filled('intervensi') && is_array($request->intervensi)) {
                $groupedData = $groupedData->filter(function ($q) use ($request) {

                    $jenisIntervensi = ['mbg', 'kie', 'pmt', 'bansos'];
                    $listIntervensi  = $q['intervensi'] ?? collect();

                    foreach ($request->intervensi as $val) {
                        $val = Str::lower(trim($val));

                        // 1️⃣ Belum mendapatkan intervensi
                        if ($val === 'belum mendapatkan intervensi') {
                            if ($listIntervensi->isEmpty()) {
                                return true; // OR
                            }
                            continue;
                        }

                        // kalau memang tidak ada intervensi, skip cek lain
                        if ($listIntervensi->isEmpty()) {
                            continue;
                        }

                        // 2️⃣ Intervensi standar
                        if (in_array($val, $jenisIntervensi)) {
                            if ($listIntervensi->contains(fn ($item) =>
                                Str::lower($item['intervensi']) === $val
                            )) {
                                return true; // OR
                            }
                        }

                        // 3️⃣ Bantuan lainnya
                        if ($val === 'bantuan lainnya') {
                            if ($listIntervensi->contains(fn ($item) =>
                                !in_array(Str::lower($item['intervensi']), $jenisIntervensi)
                            )) {
                                return true; // OR
                            }
                        }
                    }

                    // ❌ tidak ada satupun yang cocok
                    return false;
                });
            }

            if ($groupedData->isEmpty()) {
                return response()->json([
                    'message' => 'Tidak ada data kehamilan ditemukan.',
                    'data' => [],
                    'counts' => $counts
                ], 200);
            }
            return $groupedData;
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal mengambil data kehamilan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function index(Request $request)
    {
        $data = $this->getData($request);

        if ($data instanceof \Illuminate\Http\JsonResponse) {
            return $data;
        }
        $jml = $data
            ->groupBy('nik_ibu')
            ->map(fn ($g) => $g->first())
            ->values();

        $total = $jml->count();

        $count = [
            'Anemia' => 0,
            'KEK' => 0,
            'Berisiko' => 0,
            'Normal' => 0,
            'Total Ibu Hamil' => $total,
        ];

        foreach ($data as $row) {
            $hbStatus = strtoupper($row['status_gizi_hb'] ?? '');
            $lilaStatus = strtoupper($row['status_gizi_lila'] ?? '');
            $riskStatus = strtoupper($row['status_risiko_usia'] ?? '');

            if ($hbStatus === 'ANEMIA')
                $count['Anemia']++;
            if ($lilaStatus === 'KEK')
                $count['KEK']++;
            if ($riskStatus === 'BERISIKO')
                $count['Berisiko']++;

            // Normal = semua normal
            if ($hbStatus === 'NORMAL' && $lilaStatus === 'NORMAL' && $riskStatus === 'NORMAL') {
                $count['Normal']++;
            }
        }


        foreach ($count as $title => $value) {

            $color = match ($title) {
                'KEK' => 'danger',
                'Anemia' => 'warning',
                'Berisiko' => 'violet',
                'Normal' => 'success',
                'Total Ibu Hamil' => 'secondary'
            };

            $percent = $total ? round(($value / $total) * 100, 1) : 0;

            $result[] = [
                'title' => $title,
                'value' => $value,
                'percent' => "{$percent}%",
                'color' => $color,
            ];
        }

        return response()->json([
            'total' => $total,
            'data' => $data->values(),
            'counts' => $result
        ], 200);
    }

    public function import(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|mimes:csv,txt|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $file = $request->file('file');

        if (!$file->isValid()) {
            return response()->json([
                'message' => 'File tidak valid.',
            ], 400);
        }

        //dump($file);
        try {
            Excel::import(
                new PregnancyImportPendampingan(Auth::id()),
                $file
            );

            return response()->json([
                'message' => 'Berhasil mengunggah data ibu hamil',
            ], 200);

        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'Gagal mengunggah data ibu hamil',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // Tambahkan fungsi helper di dalam class
    private function sanitizeNumeric($value)
    {
        // Hapus semua karakter kecuali angka, koma, titik
        $clean = preg_replace('/[^0-9.,]/', '', $value);

        // Ganti koma jadi titik
        $clean = str_replace(',', '.', $clean);

        // Hapus titik ganda atau salah posisi
        $clean = preg_replace('/\.{2,}/', '.', $clean);

        return is_numeric($clean) ? floatval($clean) : null;
    }

    private function detectDelimiter($filePath)
    {
        $delimiters = [',', ';'];
        $handle = fopen($filePath, 'r');
        $firstLine = fgets($handle);
        fclose($handle);

        $maxCount = 0;
        $selectedDelimiter = ',';

        foreach ($delimiters as $delimiter) {
            $count = count(str_getcsv($firstLine, $delimiter));
            if ($count > $maxCount) {
                $maxCount = $count;
                $selectedDelimiter = $delimiter;
            }
        }

        return $selectedDelimiter;
    }

    public function import_intervensi(Request $request)
    {
        if (!$request->hasFile('file')) {
            return response()->json(['message' => 'Tidak ada file yang diunggah'], 400);
        }

        $file = $request->file('file');
        $path = $file->getRealPath();

        $delimiter = $this->detectDelimiter($path);
        $handle = fopen($path, 'r');

        $rows = [];
        $count = 0;

        while (($row = fgetcsv($handle, 1000, $delimiter)) !== false) {
            // Lewati baris kosong atau header
            if ($count === 0 && str_contains(strtolower($row[0]), 'petugas')) {
                $count++;
                continue;
            }
            if (empty($row[0]) && empty($row[3]))
                continue;

            if (count($row) < 16) {
                \Log::warning('Baris CSV tidak lengkap:', $row);
                continue;
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
            $nama = $this->normalizeText($row[3] ?? null);
            $tglUkur = $this->convertDate($row[1]?? null);

            if (!$nik || !$tglUkur) {
                throw new \Exception(
                    "NIK atau tanggal intervensi kosong / tidak valid pada data {$nama}"
                );
            }

            $duplikat = intervensi::where('nik_subjek', $nik)
                ->whereDate('tgl_intervensi', $tglUkur)
                ->first();

            if ($duplikat) {
                throw new \Exception(
                    "Data atas <strong>{$nik}</strong>, <strong>{$nama}</strong> sudah diunggah pada <strong>"
                    . $duplikat->created_at->format('d-m-Y')."</strong>"
                );
            }

            Intervensi::create([
                'petugas' => $this->normalizeText($row[0]?? null) ,
                'tgl_intervensi' => $this->convertDate($row[1]?? null),
                'desa' => $this->normalizeText($row[2]?? null),
                'nama_subjek' => $this->normalizeText($row[3] ?? null),
                'nik_subjek' =>$this->normalizeNIK( $row[4] ?? null),
                'status_subjek' => 'BUMIL',
                'jk' => 'P',
                'tgl_lahir' => $this->convertDate($row[6]?? null),
                'umur_subjek' => $row[7]. ' Tahun' ?? null,
                'nama_wali' => $this->normalizeText($row[8] ?? null),
                'nik_wali' => $this->normalizeNik($row[9] ?? null),
                'status_wali' => $this->normalizeText($row[10] ?? null),
                'rt' => $row[11] ?? null,
                'rw' => $row[12] ?? null,
                'posyandu' => $this->normalizeText($row[13] ?? null),
                'bantuan' => $row[14] ?? null,
                'kategori' => $this->normalizeText($row[15] ?? null),
            ]);

            $count++;
        }

        fclose($handle);

        return response()->json(['message' => "Berhasil unggah data intervensi."]);
    }

    /** Hitung umur (tahun) */
    private function hitungUmurTahun($tglLahir, $tglUkur)
    {
        if (!$tglLahir || !$tglUkur)
            return null;
        return Carbon::parse($tglLahir)->diffInYears(Carbon::parse($tglUkur));
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
            . "</ul>"
        );
    }

    public function status(Request $request)
    {
        try {
            $filterKelurahan = $request->kelurahan;
            // =====================================
            // 2. Tentukan periode (default H-1 bulan)
            // =====================================
            if ($request->filled('periode')) {
                $periode = Carbon::createFromFormat('!Y-m', $request->periode);
                $periodeAkhir = $periode->copy()->endOfMonth();
                $periodeAwal = $periode->copy()->startOfMonth();
            } else {
                $periode = now()->subMonths(1);
                $periodeAkhir = $periode->copy()->endOfMonth();
                $periodeAwal = $periode->copy()->startOfMonth();
            }


            $data = Pregnancy::get();

            /* $data = $data->groupBy('nik_ibu')->map(function ($group) {
                return $group->sortByDesc('tanggal_pemeriksaan_terakhir')->first();
            }); */

            $dataRaw = $data;

            if (!empty($filterKelurahan)) {
                $data = $data->filter(function ($item) use ($filterKelurahan) {
                    return strtolower($item->kelurahan) === strtolower($filterKelurahan);
                });
            }

            $data = $data->filter(function ($item) use ($periodeAwal, $periodeAkhir) {
                return $item->tanggal_pendampingan >= $periodeAwal->format('Y-m-d') &&
                    $item->tanggal_pendampingan <= $periodeAkhir->format('Y-m-d');
            });


            if ($request->posyandu) {
                $data = $data->filter(function ($item) use ($request) {
                    return strtolower($item->posyandu) === strtolower($request->posyandu);
                });
            }

            if ($request->rw) {
                $data = $data->filter(function ($item) use ($request) {
                    return strtolower($item->rw) === strtolower($request->rw);
                });
            }

            if ($request->rt) {
                $data = $data->filter(function ($item) use ($request) {
                    return strtolower($item->rt) === strtolower($request->rt);
                });
            }


            if ($data->isEmpty()) {
                return response()->json([
                    'total' => 0,
                    'counts' => [
                        ['title' => 'Anemia', 'value' => 0, 'percent' => '0%', 'color' => 'warning', 'trend' => []],
                        ['title' => 'KEK', 'value' => 0, 'percent' => '0%', 'color' => 'danger', 'trend' => []],
                        ['title' => 'Berisiko', 'value' => 0, 'percent' => '0%', 'color' => 'violet', 'trend' => []],
                        ['title' => 'Normal', 'value' => 0, 'percent' => '0%', 'color' => 'success', 'trend' => []],
                        ['title' => 'Total Ibu Hamil', 'value' => 0, 'percent' => '0%', 'color' => 'secondary', 'trend' => []],
                    ],
                    'kelurahan' => $filterKelurahan,
                ]);
            }

            $jml = $data
                ->groupBy('nik_ibu')
                ->map(fn ($g) => $g->first())
                ->values();

            $total = $jml->count();

            // =====================================
            // 5. Hitung status berdasarkan FIELD BARU
            // =====================================
            $count = [
                'Anemia' => 0,
                'KEK' => 0,
                'Berisiko' => 0,
                'Normal' => 0,
                'Total Ibu Hamil' => $total,
            ];
            foreach ($data as $row) {

                $hbStatus = strtoupper($row->status_gizi_hb ?? '');
                $lilaStatus = strtoupper($row->status_gizi_lila ?? '');
                $riskStatus = strtoupper($row->status_risiko_usia ?? '');

                if ($hbStatus === 'ANEMIA')
                    $count['Anemia']++;
                if ($lilaStatus === 'KEK')
                    $count['KEK']++;
                if ($riskStatus === 'BERISIKO')
                    $count['Berisiko']++;

                // Normal = semua normal
                if ($hbStatus === 'NORMAL' && $lilaStatus === 'NORMAL' && $riskStatus === 'NORMAL') {
                    $count['Normal']++;
                }
            }

            // =====================================
            // 6. TREND 6 BULAN TERAKHIR
            // =====================================
            $trendCount = [];

            $monthsToTrend = 6;
            foreach ($count as $status => $v) {

                $trend = collect();

                for ($i = ($monthsToTrend - 1); $i >= 0; $i--) {

                    $tgl = $periode->copy();
                    $tgl->subMonths($i);
                    $awal = $tgl->copy()->startOfMonth()->format('Y-m-d');
                    $akhir = $tgl->copy()->endOfMonth()->format('Y-m-d');

                    $monthData = $dataRaw->filter(function ($item) use ($awal, $akhir) {
                        return $item->tanggal_pendampingan >= $awal &&
                            $item->tanggal_pendampingan <= $akhir;
                    });
                    $groupedMonth = $monthData->groupBy('nik_ibu')->map(function ($group) {
                        return $group->sortByDesc('tanggal_pemeriksaan_terakhir')->first();
                    });

                    $totalMonth = $groupedMonth->count();
                    $jumlah = 0;

                    foreach ($groupedMonth as $row) {
                        $hbStatus = strtoupper($row->status_gizi_hb ?? '');
                        $lilaStatus = strtoupper($row->status_gizi_lila ?? '');
                        $riskStatus = strtoupper($row->status_risiko_usia ?? '');

                        if ($status === 'Anemia' && $hbStatus === 'ANEMIA')
                            $jumlah++;
                        if ($status === 'KEK' && $lilaStatus === 'KEK')
                            $jumlah++;
                        if ($status === 'Berisiko' && $riskStatus === 'BERISIKO')
                            $jumlah++;
                        if (
                            $status == 'Normal'
                            && $hbStatus == 'NORMAL'
                            && $lilaStatus == 'NORMAL'
                            && $riskStatus == 'NORMAL'
                        ) {
                            $jumlah++;
                        }

                        if ($status === 'Total Ibu Hamil') {
                            $jumlah = $totalMonth;
                        }
                    }
                    //dd($groupedMonth);
                    $persen = $totalMonth ? round(($jumlah / $totalMonth) * 100, 1) : 0;

                    $trend->push([
                        'bulan' => $tgl->format('M'),
                        'persen' => $persen,
                        'jumlah' => $jumlah,
                        'total' => $totalMonth,
                    ]);
                }

                $trendCount[$status] = $trend;
            }

            // =====================================
            // 7. Format output
            // =====================================
            $result = [];

            foreach ($count as $title => $value) {

                $color = match ($title) {
                    'KEK' => 'danger',
                    'Anemia' => 'warning',
                    'Berisiko' => 'violet',
                    'Normal' => 'success',
                    'Total Ibu Hamil' => 'secondary'
                };

                $percent = $total ? round(($value / $total) * 100, 1) : 0;

                $result[] = [
                    'title' => $title,
                    'value' => $value,
                    'percent' => "{$percent}%",
                    'color' => $color,
                    'trend' => $trendCount[$title],
                ];
            }

            return response()->json([
                'total' => $total,
                'counts' => $result,
                //elurahan' => $wilayah->kelurahan,
                'final' => $data->values(),
                'groupMonth' => $groupedMonth
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal mengambil data status bumil',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 🔹 Helper untuk parsing "Juni 2025" → Carbon date
     */
    private function parseBulanTahun(string $periode, bool $isAkhir = false): Carbon
    {
        $bulanMap = [
            'januari' => 1,
            'februari' => 2,
            'maret' => 3,
            'april' => 4,
            'mei' => 5,
            'juni' => 6,
            'juli' => 7,
            'agustus' => 8,
            'september' => 9,
            'oktober' => 10,
            'november' => 11,
            'desember' => 12,
        ];

        $parts = explode(' ', strtolower(trim($periode)));
        if (count($parts) === 2 && isset($bulanMap[$parts[0]])) {
            [$namaBulan, $tahun] = $parts;
            $bulan = $bulanMap[$namaBulan];
            $date = Carbon::createFromDate($tahun, $bulan, 1);
            return $isAkhir ? $date->endOfMonth() : $date->startOfMonth();
        }

        // fallback: coba parse langsung format "YYYY-MM" atau "YYYY-MM-DD"
        return Carbon::parse($periode);
    }

    public function show($nik_ibu)
    {
        try {
            // ✅ Ambil semua data ibu berdasarkan NIK
            $records = Pregnancy::where('nik_ibu', $nik_ibu)->orderByDesc('tanggal_pemeriksaan_terakhir')->get();

            if ($records->isEmpty()) {
                return response()->json([
                    'message' => 'Data ibu hamil tidak ditemukan',
                    'nik_ibu' => $nik_ibu,
                    'data' => null
                ], 404);
            }

            $latest = $records->first();
            //$alamat =

            // ✅ Format profil utama ibu hamil
            $ibu = [
                'nik' => $latest->nik_ibu,
                'nama' => $latest->nama_ibu ?? '-',
                'nama_suami' => $latest->nama_suami ?? '-',
                'nik_suami' => $latest->nik_suami ?? '-',
                'usia_suami' => $latest->usia_suami ?? '-',
                'usia' => $latest->usia_ibu ?? '-',
                'kelurahan' => $latest->kelurahan ?? '-',
                'rw' => $latest->rw ?? '-',
                'rt' => $latest->rt ?? '-',
                'kecamatan' => $latest->kecamatan ?? '-',
                'provinsi' => $latest->provinsi ?? '-',
                'kota' => $latest->kota ?? '-',
                'status_gizi' => $latest->status_risiko_usia ?? '-',
            ];

            // ✅ Riwayat Pemeriksaan (3 terakhir)
            $riwayatPemeriksaan = $records->take(5)->map(function ($item) {
                return [
                    'tanggal' => optional($item->tanggal_pemeriksaan_terakhir)->format('Y-m-d'),
                    'anemia' => $item->status_gizi_hb ?? '-',
                    'kek' => $item->status_gizi_lila ?? '-',
                    'risiko' => $item->status_risiko_usia ?? '-',
                    'berat_badan' => $item->berat_badan ?? '-',
                    'tinggi_badan' => $item->tinggi_badan ?? '-',
                    'imt' => $item->imt ?? '-',
                    'lila' => $item->lila ?? '-',
                    'kadar_hb' => $item->kadar_hb ?? '-',
                    'usia_kehamilan_minggu' => $item->usia_kehamilan_minggu ?? '-',
                ];
            });

            // ✅ Riwayat Intervensi dummy (bisa dihubungkan tabel lain jika sudah ada)
            $intervensi = Intervensi::where('nik_subjek', $latest->nik_ibu)
                ->orderByDesc('tgl_intervensi')
                ->get();

            $riwayatIntervensi = $intervensi->map(function ($item) {
                return [
                    'kader' => $item->petugas ?? '-',
                    'tanggal' => $item->tgl_intervensi ?? '-',
                    'intervensi' => $item->kategori ?? '-',
                ];
            })->values();

            // ✅ Data Kehamilan (semua record)
            $dataKehamilan = $records->map(function ($item) {
                return [
                    'tgl_pendampingan' => optional($item->tanggal_pendampingan)->format('Y-m-d'),
                    'kehamilan_ke' => $item->kehamilan_ke ?? '-',
                    'risiko' => $item->status_risiko_usia ?? '-',
                    'tb' => $item->tinggi_badan ?? '-',
                    'bb' => $item->berat_badan ?? '-',
                    'lila' => $item->lila ?? '-',
                    'kek' => $item->status_gizi_lila ?? '-',
                    'hb' => $item->kadar_hb ?? '-',
                    'anemia' => $item->status_gizi_hb ?? '-',
                    'asap_rokok' => $item->terpapar_asap_rokok ?? '-',
                    'bantuan_sosial' => $item->mendapat_bantuan_sosial ?? '-',
                    'jamban_sehat' => $item->menggunakan_jamban ?? '-',
                    'sumber_air_bersih' => $item->menggunakan_sab ?? '-',
                    'keluhan' => $item->keluhan ?? '-',
                    'intervensi' => $item->intervensi ?? '-',
                ];
            });

            return response()->json([
                'ibu' => $ibu,
                'riwayat_pemeriksaan' => $riwayatPemeriksaan,
                'riwayat_intervensi' => $riwayatIntervensi,
                'kehamilan' => $dataKehamilan,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal mengambil detail ibu hamil',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function mapDetailBumil($rows)
    {
        return $rows
            ->groupBy('nik_ibu')
            ->map(function ($items) {
                $main = $items->sortByDesc('tgl_pendampingan')->first();

                $intervensi = Intervensi::where('nik_subjek', $main->nik_ibu)
                    ->orderBy('tgl_intervensi', 'DESC')
                    ->first();

                return [
                    'id' => $main->nik_ibu ?? '-',
                    'nama' => $main->nama_ibu ?? '-',
                    'usia' => $main->usia_ibu ?? '-',
                    'rw' => $main->rw ?? '-',
                    'rt' => $main->rt ?? '-',
                    'anemia' => $main->status_gizi_hb ?? '-',
                    'kek' => $main->status_gizi_lila ?? '-',
                    'risiko' => $main->status_risiko_usia ?? '-',
                    'tanggal' => optional($main->tanggal_pemeriksaan_terakhir)->format('Y-m-d'),
                    'jenis' => $intervensi->kategori ?? '-',
                ];
            })
            ->values();
    }

    public function tren(Request $request)
    {
        try {
            $filterKelurahan = $request->kelurahan;
            $data = Pregnancy::get();

            $data = $data->groupBy('nik_ibu')->map(function ($group) {
                return $group->sortByDesc('tanggal_pemeriksaan_terakhir')->first();
            });
            //dump($data);
            $dataRaw = $data;
            // Filter default wilayah user
            if (!empty($filterKelurahan)) {
                $data->where('kelurahan', $filterKelurahan);
            }

            // 2️⃣ Filter tambahan request
            foreach (['kelurahan', 'posyandu', 'rw', 'rt'] as $f) {
                if ($request->filled($f)) {
                    $data->where($f, $request->$f);
                }
            }

            // 3️⃣ Tentukan current & previous month
            if ($request->filled('periode')) {
                $periode = Carbon::createFromFormat('!Y-m', $request->periode)->startOfMonth();

                $currentMonth = $periode->format('Y-m');
                $previousMonth = $periode->copy()->subMonth()->format('Y-m');
            } else {
                $currentMonth = now()->subMonth()->format('Y-m');
                $previousMonth = now()->subMonths(2)->format('Y-m');
            }

            // 4️⃣ Ambil data all (tanpa filter bulan)
            $allData = $data;

            // 5️⃣ Filter current & previous berdasarkan bulan
            $current = $allData->filter(
                fn($i) =>
                Carbon::parse($i->tanggal_pemeriksaan_terakhir)->format('Y-m') === $currentMonth
            );

            $previous = $allData->filter(
                fn($i) =>
                Carbon::parse($i->tanggal_pemeriksaan_terakhir)->format('Y-m') === $previousMonth
            );


            // 6️⃣ Fungsi hitung status
            $countStatus = function ($rows) {
                $total = $rows->count();

                return [
                    'total' => $total,
                    'KEK' => $rows->filter(fn($r) => $this->detectKek($r))->count(),
                    'Anemia' => $rows->filter(fn($r) => $this->detectAnemia($r))->count(),
                    'Risiko Usia' => $rows->filter(fn($r) => $this->detectRisti($r))->count(),
                ];
            };

            $currCount = $countStatus($current);
            $prevCount = $countStatus($previous);

            // 7️⃣ Susun tabel seperti gizi anak
            $statuses = ['KEK', 'Anemia', 'Risiko Usia'];
            $dataTable = [];

            foreach ($statuses as $status) {
                $jumlah = $currCount[$status] ?? 0;
                $total = $currCount['total'] ?? 0;
                $persen = $total > 0 ? round(($jumlah / $total) * 100, 1) : 0;

                $prevJumlah = $prevCount[$status] ?? 0;
                $prevTotal = $prevCount['total'] ?? 0;
                $prevPersen = $prevTotal > 0 ? round(($prevJumlah / $prevTotal) * 100, 1) : 0;

                if ($prevJumlah > 0) {
                    // Rumus: (curr - prev) / prev * 100
                    $trendPercent = round((($jumlah - $prevJumlah) / $prevJumlah) * 100, 1);
                } else {
                    // Kalau bulan lalu nol → otomatis dianggap 100% naik jika bulan ini > 0
                    $trendPercent = $jumlah > 0 ? 100 : 0;
                }

                // Format tampilan tren
                $tren = $trendPercent === 0
                    ? ''
                    : ($trendPercent > 0 ? "{$trendPercent}%" : "" . abs($trendPercent) . "%");

                $trenClass = $trendPercent > 0
                    ? 'text-danger'
                    : ($trendPercent < 0 ? 'text-success' : 'text-muted');

                $trenIcon = $trendPercent > 0
                    ? 'fa-solid fa-caret-up'
                    : ($trendPercent < 0 ? 'fa-solid fa-caret-down' : 'fa-solid fa-minus');

                $dataTable[] = [
                    'status' => $status,
                    'jumlah' => $jumlah,
                    'persen' => $persen,
                    'tren' => $tren,
                    'trenClass' => $trenClass,
                    'trenIcon' => $trenIcon,
                ];
            }

            // Jika kosong, isi default
            if (($currCount['total'] ?? 0) === 0) {
                $dataTable = collect($statuses)->map(fn($s) => [
                    'status' => $s,
                    'jumlah' => 0,
                    'persen' => 0,
                    'tren' => '-',
                    'trenClass' => 'text-muted',
                    'trenIcon' => 'fa-solid fa-minus',
                ]);
            }

            return response()->json([
                'total' => $currCount['total'] ?? 0,
                'dataTable_bumil' => $dataTable,
                'detail' => $this->mapDetailBumil($current),
                'periode' => [
                    'current' => $currentMonth,
                    'previous' => $previousMonth,
                ],
            ], 200);

        } catch (\Exception $e) {
            \Log::error('tren bumil error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Gagal menghitung tren bumil',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function case(Request $request)
    {
        $query = Pregnancy::query();
        if ($request->filled('provinsi'))
            $query->where('provinsi', $request->provinsi);
        if ($request->filled('kota'))
            $query->where('kota', $request->kota);
        if ($request->filled('kecamatan'))
            $query->where('kecamatan', $request->kecamatan);
        if ($request->filled('kelurahan'))
            $query->where('kelurahan', $request->kelurahan);
        if ($request->filled('posyandu'))
            $query->where('posyandu', $request->posyandu);
        if ($request->filled('rw'))
            $query->where('rw', $request->rw);
        if ($request->filled('rt'))
            $query->where('rt', $request->rt);

        // 5. Tentukan periode current & previous
        if ($request->filled('periode') && strlen($request->periode) >= 7) {
            $tanggal = Carbon::createFromFormat('Y-m', $request->periode);
            $bulanIni = $tanggal->format('Y-m');
            $bulanLalu = $tanggal->subMonth()->format('Y-m');
        } else {
            $bulanIni = now()->subMonth()->format('Y-m');
            $bulanLalu = now()->subMonths(2)->format('Y-m');
        }


        // 6. Ambil seluruh data Kunjungan yg relevan
        $data = $query->get();

        // ========== 7. Hitung CASUS berdasarkan NIK IBU (unique) ==========
        $nik_case = $data->filter(function ($item) {
            return
                ($item->status_gizi_hb && $item->status_gizi_hb !== 'Normal') ||
                ($item->status_gizi_lila && $item->status_gizi_lila !== 'Normal') ||
                ($item->status_risiko_usia && $item->status_risiko_usia !== null && $item->status_risiko_usia !== 'Normal');
        })->pluck('nik_ibu')->unique();

        // total kasus = jumlah ibu bermasalah
        $totalCase = $nik_case->count();

        // Jika kamu masih butuh grouping kategori, sesuaikan ke unique nik:
        $ane_kek = $data->filter(function ($item) {
            return $item->status_gizi_hb !== 'Normal'
                || $item->status_gizi_lila !== 'Normal';
        })->pluck('nik_ibu')->unique()->count();

        $ris_ane = $data->filter(function ($item) {
            return $item->status_risiko_usia !== null && $item->status_risiko_usia !== 'Normal'
                && $item->status_gizi_hb !== 'Normal';
        })->pluck('nik_ibu')->unique()->count();

        $ris_kek = $data->filter(function ($item) {
            return $item->status_risiko_usia !== null && $item->status_risiko_usia !== 'Normal'
                && $item->status_gizi_lila !== 'Normal';
        })->pluck('nik_ibu')->unique()->count();


        $totalCase = $ane_kek + $ris_ane + $ris_kek;
        return response()->json([
            'status' => 'success',
            'message' => 'Data anak berhasil dimuat',
            'grouping' => [
                'ane_kek' => $ane_kek,
                'ris_kek' => $ris_kek,
                'ris_ane' => $ris_ane,
            ],
            'totalCase' => $totalCase
        ]);

    }

    public function intervensiSummary(Request $request)
    {
        try {
            $user = Auth::user();

            // 🔹 Wilayah user default
            $wilayah = [
                'kelurahan' => $user->kelurahan ?? null,
                'kecamatan' => $user->kecamatan ?? null,
                'kota' => $user->kota ?? null,
                'provinsi' => $user->provinsi ?? null,
            ];

            $data = Pregnancy::get();

            $dataRaw = $data;

            $data = $data->groupBy('nik_ibu')->map(function ($group) {
                return $group->sortByDesc('tanggal_pemeriksaan_terakhir')->first();
            });

            // 🔹 Filter wilayah (prioritas: request > user)
            foreach (['kelurahan', 'posyandu', 'rw', 'rt'] as $f) {
                if ($request->filled($f)) {
                    $data = $data->filter(function ($item) use ($request, $f) {
                        return strtolower($item->kelurahan) == strtolower($request[$f]);
                    });
                } elseif (!empty($wilayah[$f] ?? null)) {
                    $data = $data->where(strtolower($f), strtolower($wilayah[$f]));
                }
            }

            // 🔹 Penentuan periode
            if ($request->filled('periodeAwal') && $request->filled('periodeAkhir')) {
                $awal = Carbon::parse($request->periodeAwal)->startOfDay();
                $akhir = Carbon::parse($request->periodeAkhir)->endOfDay();
            } elseif ($request->filled('periode')) {
                $periode = trim($request->periode);
                if (preg_match('/^\d{4}-\d{2}$/', $periode)) {
                    $awal = Carbon::createFromFormat('Y-m', $periode)->startOfMonth();
                    $akhir = (clone $awal)->endOfMonth();
                } else {
                    $bulanMap = [
                        'januari' => 1,
                        'februari' => 2,
                        'maret' => 3,
                        'april' => 4,
                        'mei' => 5,
                        'juni' => 6,
                        'juli' => 7,
                        'agustus' => 8,
                        'september' => 9,
                        'oktober' => 10,
                        'november' => 11,
                        'desember' => 12
                    ];
                    $parts = preg_split('/\s+/', strtolower($periode));
                    if (count($parts) >= 2) {
                        $monthName = $parts[0];
                        $year = intval($parts[1]);
                        $month = $bulanMap[$monthName] ?? null;
                        if ($month) {
                            $awal = Carbon::create($year, $month, 1)->startOfMonth();
                            $akhir = (clone $awal)->endOfMonth();
                        }
                    }
                }
            }

            if (!isset($awal) || !isset($akhir)) {
                $periode = Carbon::now()->subMonth();
                $akhir = (clone $periode)->endOfMonth();
                $awal = (clone $periode)->startOfMonth();
            }
            $data = $data->filter(function ($item) use ($awal, $akhir) {
                return $item->tanggal_pendampingan >= $awal && $item->tanggal_pendampingan <= $akhir;
            });

            // 🔹 Ambil data
            $rows = $data;

            if ($rows->isEmpty()) {
                return response()->json([
                    'message' => 'Tidak ada data kehamilan ditemukan.',
                    'dataTable_bumil' => [],
                    'total' => 0,
                ]);
            }

            $nikCase = $rows->pluck('nik_ibu')->unique();
            $dataIntervensi = Intervensi::where('status_subjek', 'bumil')
                ->whereIn('nik_subjek', $nikCase->toArray())->get();

            // Filter periode
            $dataIntervensi = $dataIntervensi->filter(function ($item) use ($akhir, $awal) {
                $tglIntervensi = Carbon::parse($item->tgl_intervensi);
                return $tglIntervensi >= $awal && $tglIntervensi <= $akhir;
            });

            // 🔹 Group by nik_ibu
            $grouped = $rows->map(function ($latest) use ($dataRaw, $dataIntervensi) {
                $riwayat = $dataRaw->where('nik_ibu', $latest->nik_ibu)->sortBy('tanggal_pemeriksaan_terakhir')->map(function ($g) {
                    return [
                        'tanggal_pemeriksaan_terakhir' => $g->tanggal_pemeriksaan_terakhir,
                        'berat_badan' => $g->berat_badan,
                        'tinggi_badan' => $g->tinggi_badan,
                        'imt' => $g->imt,
                        'kadar_hb' => $g->kadar_hb,
                        'status_gizi_hb' => $g->status_gizi_hb,
                        'lila' => $g->lila,
                        'status_gizi_lila' => $g->status_gizi_lila,
                        'usia_kehamilan_minggu' => $g->usia_kehamilan_minggu,
                        'posyandu' => $g->posyandu,
                        'intervensi' => $g->intervensi ?? null,
                    ];
                })->values();

                $latest->intervensi = $dataIntervensi->filter(function ($interv) use ($latest) {
                    return $interv->nik_subjek == $latest->nik_ibu;
                });

                // 🔹 Deteksi kondisi (case-insensitive)
                $isKek = str_contains(strtolower(trim($latest->status_gizi_lila ?? '')), 'kek');
                $isAnemia = str_contains(strtolower(trim($latest->status_gizi_hb ?? '')), 'anemia');
                $isRisti = str_contains(strtolower(trim($latest->status_risiko_usia ?? '')), 'berisiko');

                // 🔹 Deteksi intervensi
                $kekInterv = $this->detectIntervensiFor($latest, 'kek');
                $anemiaInterv = $this->detectIntervensiFor($latest, 'anemia');
                $ristiInterv = $this->detectIntervensiFor($latest, 'risti');

                return [
                    'nama_ibu' => $latest->nama_ibu,
                    'nik_ibu' => $latest->nik_ibu,
                    'usia_ibu' => $latest->usia_ibu,
                    'nama_suami' => $latest->nama_suami,
                    'nik_suami' => $latest->nik_suami,
                    'kehamilan_ke' => $latest->kehamilan_ke,
                    'jumlah_anak' => $latest->jumlah_anak,
                    'status_kehamilan' => $latest->status_kehamilan,
                    'provinsi' => $latest->provinsi,
                    'kota' => $latest->kota,
                    'kecamatan' => $latest->kecamatan,
                    'kelurahan' => $latest->kelurahan,
                    'rt' => $latest->rt,
                    'rw' => $latest->rw,
                    'posyandu' => $latest->posyandu,
                    'tanggal_pemeriksaan_terakhir' => $latest->tanggal_pemeriksaan_terakhir,
                    'riwayat_pemeriksaan' => $riwayat,
                    'kek' => $isKek ? 'Ya' : 'Tidak',
                    'kek_intervensi' => $kekInterv ? 'Ya' : 'Tidak',
                    'anemia' => $isAnemia ? 'Ya' : 'Tidak',
                    'anemia_intervensi' => $anemiaInterv ? 'Ya' : 'Tidak',
                    'risti' => $isRisti ? 'Ya' : 'Tidak',
                    'risti_intervensi' => $ristiInterv ? 'Ya' : 'Tidak',
                ];
            });

            return response()->json([
                'total' => $grouped->count(),
                'dataTable_bumil' => $grouped->values()->all(),
            ]);
        } catch (\Throwable $e) {
            \Log::error('intervensiSummary error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Gagal mengambil data intervensi bumil',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    protected function detectKek($row)
    {
        // cek kolom status_gizi_lila atau lila value
        if (isset($row->status_gizi_lila) && $row->status_gizi_lila) {
            $s = strtolower($row->status_gizi_lila);
            return Str::contains($s, ['kek', 'kurang energi kronis', 'kurang']);
        }
        // fallback: jika LILA numeric threshold (mis. <23 untuk wanita hamil)
        if (isset($row->lila) && is_numeric($row->lila)) {
            return floatval($row->lila) < 23.0;
        }
        return false;
    }

    protected function detectAnemia($row)
    {
        if (isset($row->status_gizi_hb) && $row->status_gizi_hb) {
            $s = strtolower($row->status_gizi_hb);
            return Str::contains($s, ['anemia', 'rendah', 'hb rendah', 'ya']);
        }
        if (isset($row->kadar_hb) && is_numeric($row->kadar_hb)) {
            return floatval($row->kadar_hb) < 11.0; // cutoff umum anemia pada ibu hamil
        }
        return false;
    }

    protected function detectRisti($row)
    {
        if (isset($row->status_risiko_usia) && $row->status_risiko_usia) {
            $s = strtolower($row->status_risiko_usia);
            return Str::contains($s, ['berisiko']);
        }
        return false;
    }

    protected function detectIntervensiFor($row, $type)
    {

        // direct boolean column check
        $col = $type . '_intervensi';
        if (isset($row->$col)) {
            if ($row->$col === 'Ya' || $row->$col === 1 || $row->$col === true) {
                return true;
            }
        }

        // check 'intervensi' text/json
        if (isset($row->intervensi) && !empty($row->intervensi)) {
            $intervensi = $row->intervensi->first();
            if (empty($intervensi))
                return false;
            $interv = $intervensi->kategori;
            if (is_string($interv)) {
                $s = strtolower($interv);
                if ($type === 'kek')
                    return true;
                if ($type === 'anemia')
                    return true;
                if ($type === 'risti')
                    return true;
            }
        }

        // also check columns like 'kek_intervensi' spelled various ways
        $altCols = [
            'kek' => ['kek_intervensi', 'intervensi_kek', 'intervensi_kek_ya'],
            'anemia' => ['anemia_intervensi', 'intervensi_anemia', 'intervensi_anemia_ya'],
            'risti' => ['risti_intervensi', 'intervensi_risti', 'intervensi_risiko']
        ];
        foreach ($altCols[$type] ?? [] as $c) {
            if (isset($row->$c) && ($row->$c === 'Ya' || $row->$c === 1 || $row->$c === true))
                return true;
        }
        return false;
    }


    public function intervensi(Request $request)
    {
        // ==========================
        // Tentukan periode (Y-m)
        // ==========================
        if ($request->filled('periode')) {
            $tanggal = Carbon::createFromFormat('Y-m', $request->periode)->endOfMonth();
        } else {
            $tanggal = now()->subMonth()->endOfMonth(); // default bulan berjalan -1
        }

        // ==========================
        // A. QUERY KUNJUNGAN BUMIL
        // ==========================
        $data = Pregnancy::get();

        $data = $data->groupBy('nik_ibu')->map(function ($group) {
            return $group->sortByDesc('tanggal_pemeriksaan_terakhir')->first();
        });


        if ($request->filled('provinsi'))
            $data = $data->filter(function ($item) use ($request) {
                return strtolower($item->provinsi) == strtolower($request->provinsi);
            });
        if ($request->filled('kota'))
            $data = $data->filter(function ($item) use ($request) {
                return strtolower($item->kota) == strtolower($request->kota);
            });
        if ($request->filled('kecamatan'))
            $data = $data->filter(function ($item) use ($request) {
                return strtolower($item->kecamatan) == strtolower($request->kecamatan);
            });
        if ($request->filled('kelurahan'))
            $data = $data->filter(function ($item) use ($request) {
                return strtolower($item->kelurahan) == strtolower($request->kelurahan);
            });
        if ($request->filled('posyandu'))
            $data = $data->filter(function ($item) use ($request) {
                return strtolower($item->posyandu) == strtolower($request->posyandu);
            });
        if ($request->filled('rw'))
            $data = $data->filter(function ($item) use ($request) {
                return $item->rw == $request->rw;
            });
        if ($request->filled('rt'))
            $data = $data->filter(function ($item) use ($request) {
                return $item->rt == $request->rt;
            });


        $data3bulan = $data->filter(function ($item) use ($tanggal) {
            $tgl = Carbon::parse($item->tanggal_pendampingan);
            $awal = (clone $tanggal)->subMonths(2)->startOfMonth();
            return $tgl >= $awal && $tgl <= $tanggal;
        });

        $data = $data3bulan->filter(function ($item) use ($tanggal) {
            $tgl = Carbon::parse($item->tanggal_pendampingan);
            return $tgl->year == $tanggal->year && $tgl->month == $tanggal->month;
        });


        $dataGanda3bulan = $data3bulan->filter(function ($item) {
            $ganda = 0;
            if ($item->status_gizi_lila == 'KEK')
                $ganda++;   // KEK
            if ($item->status_gizi_hb == 'Anemia')
                $ganda++;       // Anemia
            if ($item->status_risiko_usia == 'Berisiko')
                $ganda++; // Berisiko
            return $ganda >= 2; // minimal 2 parameter bermasalah → status ganda
        });

        $nikCase = $dataGanda3bulan->pluck('nik_ibu')->unique();

        // ==========================
        // B. QUERY INTERVENSI BUMIL
        // ==========================
        $dataIntervensi = Intervensi::where('status_subjek', 'bumil')
            ->whereIn('nik_subjek', $nikCase->toArray())->get();

        // Filter periode
        $dataIntervensi = $dataIntervensi->filter(function ($item) use ($tanggal) {
            $tglIntervensi = Carbon::parse($item->tgl_intervensi);
            $tglFilter = Carbon::create($tanggal->year, $tanggal->month, 1)->endOfMonth();

            return $tglIntervensi <= $tglFilter && (clone $tglFilter)->startOfMonth() <= $tglIntervensi;
        });



        $dataGanda = $dataGanda3bulan->filter(function ($item) use ($tanggal) {
            $tgl = Carbon::parse($item->tanggal_pendampingan);
            return $tgl->year == $tanggal->year && $tgl->month == $tanggal->month;
        });

        $dataAll = $dataGanda->map(function ($item) use ($dataIntervensi) {
            $intervensiUntukIbu = $dataIntervensi->filter(function ($interv) use ($item) {
                return $interv->nik_subjek == $item->nik_ibu;
            });

            return [
                'nik' => $item->nik_ibu,
                'nama' => $item->nama_ibu,
                'kelurahan' => $item->kelurahan,
                'posyandu' => $item->posyandu,
                'rt' => $item->rt,
                'rw' => $item->rw,
                'umur' => $item->usia_ibu,
                'data_kunjungan' => $item,
                'data_intervensi' => $intervensiUntukIbu->values(),
            ];
        });

        $dataGiziGandaTerintervensi = $dataAll->filter(function ($item) {
            return $item['data_intervensi']->isNotEmpty();
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Data intervensi & kunjungan bumil berhasil dimuat',

            'grouping' => [
                'total_case' => $dataGanda->count(),
                'punya_keduanya' => $dataGiziGandaTerintervensi->count(),
                'hanya_kunjungan' => $dataGanda->count() - $dataIntervensi->count(),
            ],

            'detail' => [
                'punya_keduanya' => $dataGiziGandaTerintervensi->values(),
                'hanya_kunjungan' => $dataAll->filter(function ($item) {
                    return $item['data_intervensi']->isEmpty();
                })->values(),
            ],
            'tren' => $this->generateLabelAndData($tanggal, $data3bulan)
        ]);
    }

    public function indikatorBulanan(Request $request)
    {
        try {
            $query = Pregnancy::query();

            if ($request->filled('provinsi')) {
                $query->where('provinsi', $request->provinsi);
            }
            if ($request->filled('kota')) {
                $query->where('kota', $request->kota);
            }
            if ($request->filled('kecamatan')) {
                $query->where('kecamatan', $request->kecamatan);
            }
            if ($request->filled('kelurahan')) {
                $query->where('kelurahan', $request->kelurahan);
            }

            foreach (['posyandu', 'rw', 'rt'] as $f) {
                if ($request->filled($f))
                    $query->where($f, $request->$f);
            }

            $startDate = now()->subMonths(11)->startOfMonth();
            $endDate = now()->endOfMonth();

            $query->whereBetween('tanggal_pendampingan', [$startDate, $endDate]);

            $data = $query->get([
                'nik_ibu',
                'tanggal_pendampingan',
                'status_gizi_lila',
                'status_gizi_hb',
                'status_risiko_usia'
            ]);

            $groupedByMonth = $data->groupBy(function ($item) {
                return Carbon::parse($item->tanggal_pendampingan)->format('Y-m');
            });

            /* $data = $data->groupBy('nik_ibu')->map(function ($group) {
                return $group->sortByDesc('tanggal_pemeriksaan_terakhir')->first();
            }); */

            if ($data->isEmpty()) {
                return response()->json([
                    'labels' => [],
                    'indikator' => [],
                ]);
            }

            $months = collect(range(0, 11))
                ->map(fn($i) => now()->startOfMonth()->subMonths(11 - $i)->format('M Y'))
                ->values();

            $indikatorList = ['KEK', 'Anemia', 'Berisiko'];
            $result = [];
            foreach ($indikatorList as $indikator) {
                $result[$indikator] = array_fill(0, 12, 0);
            }

            // Group per bulan, ambil semua record
            $groupedByMonth = $data->groupBy(function ($item) {
                return Carbon::parse($item->tanggal_pendampingan)->format('Y-m');
            });

            // Hitung
            foreach ($groupedByMonth as $monthKey => $rows) {
                $label = Carbon::createFromFormat('Y-m-d', $monthKey . "-01")->format('M Y');
                $idx = $months->search($label);
                if ($idx === false)
                    continue;

                $result['KEK'][$idx] = $rows->filter(
                    fn($i) =>
                    str_contains(strtolower($i->status_gizi_lila ?? ''), 'kek')
                )->count();

                $result['Anemia'][$idx] = $rows->filter(
                    fn($i) =>
                    str_contains(strtolower($i->status_gizi_hb ?? ''), 'anemia')
                )->count();

                $result['Berisiko'][$idx] = $rows->filter(
                    fn($i) =>
                    str_contains(strtolower($i->status_risiko_usia ?? ''), 'berisiko')
                )->count();
            }

            return response()->json([
                'labels' => $months,
                'indikator' => $result,
            ]);

        } catch (\Throwable $th) {
            return response()->json([
                'error' => 'Gagal memuat data indikator bulanan',
                'message' => $th->getMessage(),
            ], 500);
        }
    }


    public function indikatorBulanan_old(Request $request)
    {
        try {
            $query = Pregnancy::query();

            // 🔹 Filter opsional
            foreach (['kelurahan', 'posyandu', 'rw', 'rt'] as $f) {
                if ($request->filled($f)) {
                    $query->where($f, $request->$f);
                }
            }

            // 🔹 Ambil kolom relevan
            $data = $query->get([
                'tanggal_pemeriksaan_terakhir',
                'status_gizi_lila',
                'status_gizi_hb',
                'status_risiko_usia'
            ]);

            // 🔹 Buat 12 bulan terakhir (misal: "Nov 2024", "Dec 2024", ..., "Oct 2025")
            $months = collect(range(0, 11))
                ->map(fn($i) => now()->subMonths(11 - $i)->format('M Y'))
                ->values();

            $indikatorList = ['KEK', 'Anemia', 'Berisiko', 'Normal'];
            $result = [];
            foreach ($indikatorList as $indikator) {
                $result[$indikator] = array_fill(0, 12, 0);
            }

            if ($data->isEmpty()) {
                return response()->json([
                    'labels' => $months,
                    'indikator' => $result,
                ]);
            }

            foreach ($data as $item) {
                if (!$item->tanggal_pendampingan)
                    continue;

                $monthKey = Carbon::parse($item->tanggal_pendampingan)->format('M Y');
                $idx = $months->search($monthKey);
                if ($idx === false)
                    continue;

                $isKEK = str_contains(strtolower(trim($item->status_gizi_lila ?? '')), 'kek');
                $isAnemia = str_contains(strtolower(trim($item->status_gizi_hb ?? '')), 'anemia');
                $isBerisiko = str_contains(strtolower(trim($item->status_risiko_usia ?? '')), 'berisiko');

                if ($isKEK) {
                    $result['KEK'][$idx]++;
                } elseif ($isAnemia) {
                    $result['Anemia'][$idx]++;
                } elseif ($isBerisiko) {
                    $result['Berisiko'][$idx]++;
                } else {
                    $result['Normal'][$idx]++;
                }
            }

            return response()->json([
                'labels' => $months,
                'indikator' => $result,
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'error' => 'Gagal memuat data indikator',
                'message' => $th->getMessage(),
            ], 500);
        }
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

    private function generateLabelAndData(Carbon $tanggal, $data)
    {
        $labels = [];
        $kek = [];
        $anemia = [];
        $risiko = [];

        // Ambil 3 bulan: bulan sekarang + 2 bulan ke belakang
        $start = $tanggal->copy()->subMonths(2)->startOfMonth();
        $end = $tanggal->copy()->endOfMonth();

        // 1. Buat label bulan Y-m
        $cursor = $start->copy();
        while ($cursor <= $end) {
            $labels[] = $cursor->format('M Y');
            $cursor->addMonth();
        }

        // 2. Siapkan summary default 0
        $summary = [];
        foreach ($labels as $lb) {
            $summary[$lb] = [
                'kek' => 0,
                'anemia' => 0,
                'risiko' => 0,
            ];
        }

        // 3. Mapping data dari database → kelompokkan ke bulan Y-m
        $data->each(function ($item) use (&$summary) {
            $bulan = Carbon::parse($item['tanggal_pendampingan'])->format('M Y');
            if (isset($summary[$bulan])) {
                $ganda = 0;
                if ($item->status_gizi_lila == 'KEK')
                    $ganda++;
                if ($item->status_gizi_hb == 'Anemia')
                    $ganda++;
                if ($item->status_risiko_usia == 'Berisiko')
                    $ganda++;

                if ($ganda > 1) {
                    if ($item->status_gizi_lila == 'KEK')
                        $summary[$bulan]['kek'] += 1;
                    if ($item->status_gizi_hb == 'Anemia')
                        $summary[$bulan]['anemia'] += 1;
                    if ($item->status_risiko_usia == 'Berisiko')
                        $summary[$bulan]['risiko'] += 1;
                }
            }
        });

        // 4. Convert summary ke dalam 3 series
        foreach ($labels as $lb) {
            $kek[] = $summary[$lb]['kek'];
            $anemia[] = $summary[$lb]['anemia'];
            $risiko[] = $summary[$lb]['risiko'];
        }

        // 5. Return dalam bentuk line chart friendly
        return [
            'labels' => $labels,
            'series' => [
                'kek' => $kek,
                'anemia' => $anemia,
                'risiko' => $risiko,
            ],
        ];
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

    private function normalizeDecimal(?string $value): ?float
    {
        if (!$value)
        return null;
        $value = str_replace(',', '.', trim($value));
        return is_numeric($value) ? (float) $value : null;
    }

    private function meterToCm(?float $value): ?float
    {
        if (!$value)
        return null;
        return $value < 3 ? $value * 100 : $value;
    }

    public function delete($nik)
    {
        try {
            DB::beginTransaction();

            $deletedPendampingan = false;
            $deletedIntervensi = false;
            // Pregnancy (Ibu hamil)
            if (Pregnancy::where('nik_ibu', $nik)->exists()) {
                Pregnancy::where('nik_ibu', $nik)->delete();
                $deletedPendampingan = true;
            }

            // Intervensi
            if (Intervensi::where('nik_subjek', $nik)->exists()) {
                Intervensi::where('nik_subjek', $nik)->delete();
                $deletedIntervensi = true;
            }

            if (!$deletedPendampingan && !$deletedIntervensi) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Data dengan NIK '.$nik.' tidak ditemukan.'
                ], 404);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Data NIK '.$nik.' berhasil dihapus.'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan server.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function bulkDelete(Request $request)
    {
        try {
            DB::beginTransaction();

            $niks = $request->ids; // array NIK

            if (!is_array($niks) || empty($niks)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data tidak valid'
                ], 422);
            }

            $deletedPregnancy = Pregnancy::whereIn('nik_ibu', $niks)->delete();
            $deletedIntervensi = Intervensi::whereIn('nik_subjek', $niks)->delete();

            DB::commit();

            \App\Models\Log::create([
                'id_user'  => Auth::id(),
                'context'  => 'Data Bumil',
                'activity' => 'Bulk Delete (' . (
                    $deletedPregnancy +
                    $deletedIntervensi
                ) . ' data)',
                'timestamp'=> now(),
            ]);

            return response()->json([
                'success' => true,
                'deleted' => [
                    'pendampingan'     => $deletedPregnancy,
                    'intervensi'    => $deletedIntervensi,
                ]
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            \Log::error('Bulk delete anak gagal', [
                'ids' => $request->ids,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus data keluarga'
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $user = Auth::user();

            $validated = $request->validate([
                'nik_ibu' => 'required|string',
                'bb' => 'nullable|numeric',
                'tb' => 'nullable|numeric',
                'lila' => 'nullable|numeric',
                'hb' => 'nullable|numeric',
                'nama_suami' => 'nullable|string',
                'nama_ibu' => 'nullable|string',
                'tanggal_pendampingan' => 'nullable|date',
            ]);

            $nikIbu = $this->normalizeNIK($validated['nik_ibu']);

            $prevPregnancy = Pregnancy::where('nik_ibu', $nikIbu)
                ->orderByDesc('created_at')
                ->first();

            // =====================
            // NORMALISASI ANTROPOMETRI
            // =====================
            $berat = $this->normalizeDecimal($validated['bb'] ?? null);
            if ($berat !== null && ($berat < 20 || $berat > 999)) $berat = null;

            $tinggi = $this->meterToCm($this->normalizeDecimal($validated['tb'] ?? null));
            if ($tinggi !== null && ($tinggi < 50 || $tinggi > 999)) $tinggi = null;

            $hb = $this->normalizeDecimal($validated['hb'] ?? null);
            if ($hb !== null && ($hb < 5 || $hb > 999)) $hb = null;

            $lila = $this->normalizeDecimal($validated['lila'] ?? null);
            if ($lila !== null && ($lila < 10 || $lila > 999)) $lila = null;

            $imt = $this->hitungIMT($berat, $tinggi);

            // =====================
            // CREATE DATA
            // =====================
            $pregnancy = Pregnancy::create([

                // =====================
                // PETUGAS & WAKTU
                // =====================
                'nama_petugas' => $this->normalizeText($user->name ?? null),
                'tanggal_pendampingan' => $this->convertDate($request->tanggal_pendampingan ?? null),
                'tanggal_pemeriksaan_terakhir' => now(),

                // =====================
                // IDENTITAS
                // =====================
                'nik_ibu' => $nikIbu,
                'nama_ibu' => $this->normalizeText(
                    $validated['nama_ibu']
                    ?? $prevPregnancy->nama_ibu
                    ?? null
                ),

                'nik_suami' => $this->normalizeNik($request->nik_suami ?? $prevPregnancy->nik_suami ?? null),
                'nama_suami' => $this->normalizeText(
                    $validated['nama_suami']
                    ?? $prevPregnancy->nama_suami
                    ?? null
                ),

                'usia_ibu' => $prevPregnancy->usia_ibu ?? null,
                'usia_suami' => $prevPregnancy->usia_suami ?? null,
                'pekerjaan_suami' => $prevPregnancy->pekerjaan_suami ?? null,

                // =====================
                // KEHAMILAN
                // =====================
                'kehamilan_ke' => $prevPregnancy->kehamilan_ke ?? null,
                'jumlah_anak' => $prevPregnancy->jumlah_anak ?? null,
                'status_kehamilan' => $prevPregnancy->status_kehamilan ?? null,
                'usia_kehamilan_minggu' => $prevPregnancy->usia_kehamilan_minggu ?? null,
                'hpl' => $prevPregnancy->hpl ?? null,

                // =====================
                // RIWAYAT
                // =====================
                'riwayat_4t' => $prevPregnancy->riwayat_4t ?? null,
                'riwayat_penggunaan_kb' => $prevPregnancy->riwayat_penggunaan_kb ?? null,
                'riwayat_ber_kontrasepsi' => $prevPregnancy->riwayat_ber_kontrasepsi ?? null,
                'riwayat_penyakit' => $prevPregnancy->riwayat_penyakit ?? null,
                'riwayat_keguguran_iufd' => $prevPregnancy->riwayat_keguguran_iufd ?? null,

                // =====================
                // ANTROPOMETRI
                // =====================
                'berat_badan' => $berat ?? $prevPregnancy->berat_badan ?? null,
                'tinggi_badan' => $tinggi ?? $prevPregnancy->tinggi_badan ?? null,
                'kadar_hb' => $hb ?? $prevPregnancy->kadar_hb ?? null,
                'lila' => $lila ?? $prevPregnancy->lila ?? null,
                'imt' => $imt ?? $prevPregnancy->imt ?? null,

                // =====================
                // STATUS GIZI
                // =====================
                'status_gizi_hb' => $hb !== null
                    ? ($hb < 11 ? 'Anemia' : 'Normal')
                    : ($prevPregnancy->status_gizi_hb ?? null),

                'status_gizi_lila' => $lila !== null
                    ? ($lila < 23.5 ? 'KEK' : 'Normal')
                    : ($prevPregnancy->status_gizi_lila ?? null),

                'status_risiko_usia' => $prevPregnancy->status_risiko_usia ?? null,

                // =====================
                // LINGKUNGAN & PERILAKU
                // =====================
                'terpapar_asap_rokok' => $prevPregnancy->terpapar_asap_rokok ?? null,
                'mendapat_ttd' => $prevPregnancy->mendapat_ttd ?? null,
                'menggunakan_jamban' => $prevPregnancy->menggunakan_jamban ?? null,
                'menggunakan_sab' => $prevPregnancy->menggunakan_sab ?? null,
                'fasilitas_rujukan' => $prevPregnancy->fasilitas_rujukan ?? null,
                'mendapat_kie' => $prevPregnancy->mendapat_kie ?? null,
                'mendapat_bantuan_sosial' => $prevPregnancy->mendapat_bantuan_sosial ?? null,

                // =====================
                // RENCANA
                // =====================
                'rencana_tempat_melahirkan' => $prevPregnancy->rencana_tempat_melahirkan ?? null,
                'rencana_asi_eksklusif' => $prevPregnancy->rencana_asi_eksklusif ?? null,
                'rencana_tinggal_setelah' => $prevPregnancy->rencana_tinggal_setelah ?? null,
                'rencana_kontrasepsi' => $prevPregnancy->rencana_kontrasepsi ?? null,

                // =====================
                // WILAYAH
                // =====================
                'provinsi' => $prevPregnancy->provinsi ?? null,
                'kota' => $prevPregnancy->kota ?? null,
                'kecamatan' => $prevPregnancy->kecamatan ?? null,
                'kelurahan' => $prevPregnancy->kelurahan ?? null,
                'rt' => $prevPregnancy->rt ?? null,
                'rw' => $prevPregnancy->rw ?? null,

                // =====================
                // LAIN-LAIN
                // =====================
                'taksiran_berat_janin'=> $prevPregnancy->taksiran_berat_janin ?? null,
                'tinggi_fundus'=> $prevPregnancy->tinggi_fundus ?? null,
                'posyandu' => $this->posyanduUser ?? $prevPregnancy->posyandu ?? null,
            ]);

            return response()->json([
                'message' => 'Data berhasil disimpan',
                'data' => $pregnancy
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal menyimpan data',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function update(Request $request, $nik)
    {
        try {
            $user = Auth::user();

            // 1. Validasi input
            $validated = $request->validate([
                'nik_ibu' => 'required|string',
                'bb' => 'nullable|numeric',
                'tb' => 'nullable|numeric',
                'lila' => 'nullable|numeric',
                'hb' => 'nullable|numeric',
                'nama_suami' => 'nullable|string',
                'nama_ibu' => 'nullable|string',
            ]);

            $pregnancy = Pregnancy::where('nik_ibu', $nik);

            if (!$pregnancy) {
                return response()->json([
                    'message' => 'Data ibu hamil tidak ditemukan'
                ], 404);
            }

            // 2. Cari data berdasarkan NIK
            $pregnancy->update([
                'nik_ibu' => $validated['nik_ibu'] ?? $pregnancy->nik_ibu,
                'nama_ibu' => $this->normalizeText($validated['nama_ibu'] ?? $pregnancy->nama_ibu),
                'nama_suami' => $this->normalizeText($validated['nama_suami'] ?? $pregnancy->nama_suami),
                'berat_badan' => isset($validated['bb']) ? $this->normalizeDecimal($validated['bb']) : $pregnancy->berat_badan,
                'tinggi_badan' => isset($validated['tb']) ? $this->normalizeDecimal($validated['tb']) : $pregnancy->tinggi_badan,
                'lila' => isset($validated['lila']) ? $this->normalizeDecimal($validated['lila']) : $pregnancy->lila,
                'kadar_hb' => isset($validated['hb']) ? $this->normalizeDecimal($validated['hb']) : $pregnancy->kadar_hb,
            ]);

            \App\Models\Log::create([
                'id_user' => \Auth::id(),
                'context' => 'Data Ibu Hamil',
                'activity' => 'Ubah data ibu hamil ' . ($nik ?? '-'),
                'timestamp' => now(),
            ]);

            return response()->json([
                'message' => 'Data '. $validated['nama_ibu'] . ' berhasil diperbarui',
                'data' => $pregnancy
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal update data',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

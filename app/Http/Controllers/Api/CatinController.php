<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Str;


use App\Models\Catin;
use App\Models\Log;
use App\Models\Wilayah;
use App\Models\Posyandu;
use App\Models\Intervensi;
use App\Models\Kunjungan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Imports\CatinImportPendampingan;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

class CatinController extends Controller
{
    public function getData(Request $request)
    {
        try {
            $data = Catin::get();
            $dataRaw = $data;

            $data = $data->filter(function ($item) use ($request) {
                if (!empty($request->provinsi) &&
                    strtolower(trim($item->provinsi)) !== strtolower(trim($request->provinsi))) {
                    return false;
                }

                if (!empty($request->kota) &&
                    strtolower(trim($item->kota)) !== strtolower(trim($request->kota))) {
                    return false;
                }

                if (!empty($request->kecamatan) &&
                    strtolower(trim($item->kecamatan)) !== strtolower(trim($request->kecamatan))) {
                    return false;
                }

                if (!empty($request->kelurahan) &&
                    strtolower(trim($item->kelurahan)) !== strtolower(trim($request->kelurahan))) {
                    return false;
                }

                return true;
            });

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
                //dd($start, $end);
                //dd($data->toArray());
                $data = $data->filter(function ($item) use ($start, $end) {
                    // dd($item->tanggal_pendampingan_terakhir >= $start && $item->tanggal_pendampingan_terakhir <= $end);
                    return $item->tanggal_pendampingan >= $start && $item->tanggal_pendampingan <= $end;
                });
                //dd($data);
            }

            if ($request->filled('status') && is_array($request->status)) {
                $data = $data->filter(function ($q) use ($request) {

                    foreach ($request->status as $status) {
                        $statusLower = strtolower($status);

                        // KEK
                        if (str_contains($statusLower, 'kek')) {
                            if (!empty($q->status_kek) && $q->status_kek === 'KEK') {
                                return true;
                            }
                        }

                        // Anemia
                        if (str_contains($statusLower, 'anemia')) {
                            if (!empty($q->status_hb) && $q->status_hb === 'Anemia') {
                                return true;
                            }
                        }

                        // Risiko
                        if (str_contains($statusLower, 'risiko')) {
                            if (!empty($q->status_risiko) && $q->status_risiko === 'Berisiko') {
                                return true;
                            }
                        }
                    }

                    // ❌ tidak ada satupun status yang cocok
                    return false;
                });
            }

            if ($request->filled('usia') && is_array($request->usia)) {
                $data = $data->filter(function ($q) use ($request) {
                    $usia = $q->usia_perempuan ?? null;
                    if (!$usia)
                        return false;

                    foreach ($request->usia as $range) {
                        $range = trim($range);

                        // < 19 Tahun
                        if ($range === '< 19 Tahun' && $usia < 19) {
                            return true;
                        }

                        // >= 35 Tahun
                        if ($range === '>= 35 Tahun' && $usia >= 35) {
                            return true;
                        }

                        // 19 - 34 Tahun
                        if ($range === '19 - 34 Tahun' && $usia >= 19 && $usia <= 34) {
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
                    "color" => "danger"
                ],
                [
                    "title" => "KEK",
                    "value" => 0,
                    "percent" => "0%",
                    "color" => "warning"
                ],
                [
                    "title" => "Risiko Usia",
                    "value" => 0,
                    "percent" => "0%",
                    "color" => "violet"
                ],
                [
                    "title" => "Total Kasus",
                    "value" => 0,
                    "percent" => "0%",
                    "color" => "success"
                ],
                [
                    "title" => "Total Calon Pengantin",
                    "value" => 0,
                    "percent" => "0%",
                    "color" => "secondary"
                ],
            ];


            $grouped = $data->groupBy('nik_perempuan')->sortBy('tanggal_pendampingan')->map(function ($items) {

                $main = $items->first();

                return [
                    // informasi utama
                    'nama_perempuan' => $main->nama_perempuan,
                    'nik_perempuan' => $main->nik_perempuan,
                    'usia_perempuan' => $main->usia_perempuan,
                    'hp_perempuan' => $main->hp_perempuan,
                    'kerja_perempuan' => $main->pekerjaan_perempuan,

                    'nama_laki' => $main->nama_laki,
                    'nik_laki' => $main->nik_laki,
                    'usia_laki' => $main->usia_laki,
                    'hp_laki' => $main->hp_laki,
                    'kerja_laki' => $main->pekerjaan_laki,

                    'provinsi' => $main->provinsi,
                    'kota' => $main->kota,
                    'kecamatan' => $main->kecamatan,
                    'kelurahan' => $main->kelurahan,
                    'rw' => $main->rw,
                    'rt' => $main->rt,
                    'posyandu' => $main->posyandu,
                    'status_risiko' => $main->status_risiko,
                    'status_hb' => $main->status_hb,
                    'status_kek' => $main->status_kek,
                    'tgl_pernikahan' => $main->tanggal_rencana_menikah,
                    'tgl_kunjungan' => $main->tanggal_pendampingan,

                    // RIWAYAT PEMERIKSAAN
                    'riwayat_pemeriksaan' => $items->map(function ($d) {
                        return [
                            'status_risiko' => $d->status_risiko,
                            'tanggal_pemeriksaan' => $d->tanggal_pemeriksaan,
                            'berat_perempuan' => $d->berat_perempuan,
                            'tinggi_perempuan' => $d->tinggi_perempuan,
                            'imt_perempuan' => $d->imt_perempuan,
                            'hb_perempuan' => $d->hb_perempuan,
                            'status_hb' => $d->status_hb,
                            'lila_perempuan' => $d->lila_perempuan,
                            'status_kek' => $d->status_kek,
                            'riwayat_penyakit' => $d->riwayat_penyakit,
                            'terpapar_rokok' => $d->terpapar_rokok,
                            'menggunakan_jamban' => $d->menggunakan_jamban,
                            'sumber_air_bersih' => $d->sumber_air_bersih
                        ];
                    })->values(),

                    // RIWAYAT PENDAMPINGAN
                    'riwayat_pendampingan' => $items->whereNotNull('tanggal_pendampingan')->map(function ($d) {
                        return [
                            'tanggal_pendampingan' => $d->tanggal_pendampingan,
                            'nama_petugas' => $d->nama_petugas,
                        ];
                    })->values(),
                ];

            })->values();
            //dd($grouped);
            if ($grouped->isEmpty()) {
                return collect(); // BIAR index() yang handle default
            }
            return $grouped;
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

        $data = collect($data);

        // =========================
        // EMPTY RESPONSE
        // =========================
        if ($data->isEmpty()) {
            return response()->json(
                $this->defaultCatinEmptyResponse($request),
                200
            );
        }

        // =========================
        // NORMAL FLOW
        // =========================
        $jml = $data
            ->groupBy('nik_perempuan')
            ->map(fn ($g) => $g->first())
            ->values();

        $total = $jml->count();

        $count = [
            'Anemia' => 0,
            'KEK' => 0,
            'Risiko Usia' => 0,
            'Total Kasus' => 0,
            //'Total Calon Pengantin' => $data->count(),
            'Total Calon Pengantin' => $total,
        ];

        // Hitung masing-masing kategori
        foreach ($data as $row) {
            $hbStatus = strtoupper($row['status_hb'] ?? '');
            //$lilaStatus = strtoupper($row['status_kek'] ?? '');
            $kekStatus = strtoupper($row['status_kek'] ?? '');
            $riskStatus = strtoupper($row['status_risiko'] ?? '');

            if ($hbStatus === 'ANEMIA') {
                $count['Anemia']++;
            }

            if ($kekStatus === 'KEK') {
                $count['KEK']++;
            }

            if ($riskStatus === 'BERISIKO') {
                $count['Risiko Usia']++;
            }
        }

        // Hitung catin berisiko = TIGA-TIGANYA DIJUMLAH
        $count['Total Kasus'] =
            $count['Anemia'] +
            $count['KEK'] +
            $count['Risiko Usia'];

        $total = $count['Total Calon Pengantin'];

        $result = [];
        //dd($count);
        foreach ($count as $title => $value) {

            $color = match ($title) {
                'Anemia' => 'danger',
                'KEK' => 'warning',
                'Risiko Usia' => 'violet',
                'Total Kasus' => 'success',
                'Total Calon Pengantin' => 'secondary',
            };

            $percent = $total
                ? round(($value / $total) * 100, 1)
                : 0;

            $result[] = [
                'title' => $title,
                'value' => $value,
                'percent' => "{$percent}%",
                'color' => $color,
                'trend' => [],
            ];
        }

        return response()->json([
            'total' => $total,
            'data' => $data->values(),
            'counts' => $result,
            'wilayah' => [
                'provinsi' => $request->provinsi ?? '',
                'kota' => $request->kota ?? '',
                'kecamatan' => $request->kecamatan ?? '',
                'kelurahan' => $request->kelurahan ?? '',
            ],
        ], 200);

    }

    private function defaultCatinEmptyResponse(Request $request)
    {
        return [
            'total' => 0,
            'data' => [],
            'counts' => [
                [
                    'title' => 'Anemia',
                    'value' => 0,
                    'percent' => '0%',
                    'color' => 'danger',
                    'trend' => [],
                ],
                [
                    'title' => 'KEK',
                    'value' => 0,
                    'percent' => '0%',
                    'color' => 'warning',
                    'trend' => [],
                ],
                [
                    'title' => 'Risiko Usia',
                    'value' => 0,
                    'percent' => '0%',
                    'color' => 'violet',
                    'trend' => [],
                ],
                [
                    'title' => 'Total Kasus',
                    'value' => 0,
                    'percent' => '0%',
                    'color' => 'success',
                    'trend' => [],
                ],
                [
                    'title' => 'Total Calon Pengantin',
                    'value' => 0,
                    'percent' => '0%',
                    'color' => 'secondary',
                    'trend' => [],
                ],
            ],
            'wilayah' => [
                'provinsi'  => $request->provinsi ?? '',
                'kota'      => $request->kota ?? '',
                'kecamatan' => $request->kecamatan ?? '',
                'kelurahan' => $request->kelurahan ?? '',
            ],
        ];
    }

    private function parseBulanTahun(string $periode, bool $akhirBulan = false): Carbon
    {
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

        $parts = explode(' ', trim(strtolower($periode))); // contoh: ["maret", "2025"]

        if (count($parts) === 2 && isset($bulanMap[$parts[0]])) {
            $bulan = $bulanMap[$parts[0]];
            $tahun = (int) $parts[1];

            $date = Carbon::createFromDate($tahun, $bulan, 1);
            return $akhirBulan ? $date->endOfMonth() : $date->startOfMonth();
        }

        // fallback jika format salah
        return Carbon::now();
    }

    public function show($nik_perempuan)
    {
        try {
            // Ambil semua record berdasarkan NIK perempuan
            $records = Catin::where('nik_perempuan', $nik_perempuan)
                ->orderByDesc('tanggal_pendampingan')
                ->get();

            if ($records->isEmpty()) {
                return response()->json([
                    'message' => 'Data catin tidak ditemukan',
                    'nik_perempuan' => $nik_perempuan,
                    'data' => null
                ], 404);
            }

            // Record terbaru
            $latest = $records->first();

            // ============ PROFIL CATIN ============
            $catin = [
                'nik_perempuan' => $latest->nik_perempuan,
                'nama_perempuan' => $latest->nama_perempuan ?? '-',
                'pekerjaan_perempuan' => $latest->pekerjaan_perempuan ?? '-',
                'usia_perempuan' => $latest->usia_perempuan ?? '-',

                'nik_laki' => $latest->nik_laki,
                'nama_laki' => $latest->nama_laki ?? '-',
                'pekerjaan_laki' => $latest->pekerjaan_laki ?? '-',
                'usia_laki' => $latest->usia_laki ?? '-',

                // Domisili
                'provinsi' => $latest->provinsi,
                'kota' => $latest->kota,
                'kecamatan' => $latest->kecamatan,
                'kelurahan' => $latest->kelurahan,
                'rw' => $latest->rw,
                'rt' => $latest->rt,

                // Rencana menikah
                'tgl_menikah' => $latest->tanggal_rencana_menikah ?? '-',

                // Pemeriksaan terakhir
                'kader' => $latest->nama_petugas ?? '-',
                'status_risiko' => $latest->status_risiko ?? '-',
                'status_kek' => $latest->status_kek ?? '-',
                'status_hb' => $latest->status_hb ?? '-',

                'tgl_kunjungan' => $latest->tanggal_pendampingan ?? '-',
                'tb' => $latest->tinggi_perempuan ?? '-',
                'bb' => $latest->berat_perempuan ?? '-',
                'lila' => $latest->lila_perempuan ?? '-',
                'hb' => $latest->hb_perempuan ?? '-',

                'riwayat_penyakit' => $latest->riwayat_penyakit ?? '-',
                'sumber_air_bersih' => $latest->sumber_air_bersih ?? '-',
                'jamban_sehat' => $latest->menggunakan_jamban ?? '-',
            ];

            // ============ PEMERIKSAAN TERAKHIR (1 row saja) ============
            $pemeriksaan_terakhir = [
                [
                    'status_risiko' => $latest->status_risiko,
                    'tanggal_pemeriksaan' => $latest->tanggal_pemeriksaan,
                    'berat_perempuan' => $latest->berat_perempuan,
                    'tinggi_perempuan' => $latest->tinggi_perempuan,
                    'imt_perempuan' => $latest->imt_perempuan,
                    'lila_perempuan' => $latest->lila_perempuan,
                    'hb_perempuan' => $latest->hb_perempuan,
                    'status_hb' => $latest->status_hb,
                    'status_kek' => $latest->status_kek
                ]
            ];

            // ============ RIWAYAT LENGKAP (tabel bawah) ============
            $riwayat = $records->map(function ($r) {
                return [
                    'kader' => $r->nama_petugas,
                    'tanggal' => $r->tanggal_pendampingan,
                    'bb' => $r->berat_perempuan,
                    'tb' => $r->tinggi_perempuan,
                    'imt' => $r->imt_perempuan,
                    'lila' => $r->lila_perempuan,
                    'hb' => $r->hb_perempuan,
                    'status_risiko' => $r->status_risiko,
                    'status_hb' => $r->status_hb,
                    'status_kek' => $r->status_kek,
                    'riwayat_penyakit' => $r->riwayat_penyakit,
                    'menggunakan_jamban' => $r->menggunakan_jamban == 1 ? true : false,
                    'sumber_air_bersih' => $r->sumber_air_bersih == 1 ? true : false
                ];
            });

            // ============ RETURN ============
            return response()->json([
                'catin' => $catin,
                'pemeriksaan_terakhir' => $pemeriksaan_terakhir,
                'riwayat' => $riwayat,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal mengambil detail catin',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function convertDate($date)
    {
        if (!$date)
            return null;
        $date = str_replace('/', '-', trim($date));
        try {
            return Carbon::parse($date)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    private function toBool($val)
    {
        return in_array(strtolower(trim($val)), ['ya', 'y', 'true', '1']) ? true : false;
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

    private function mapDetailCatin($rows)
    {
        return $rows
            ->groupBy('nik_perempuan')
            ->map(function ($items) {
                $main = $items->sortByDesc('tanggal_pendampingan')->first();

                return [
                    // informasi utama
                    'nama_perempuan' => $main->nama_perempuan,
                    'nik_perempuan' => $main->nik_perempuan,
                    'usia_perempuan' => $main->usia_perempuan,
                    'hp_perempuan' => $main->hp_perempuan,
                    'kerja_perempuan' => $main->pekerjaan_perempuan,

                    'nama_laki' => $main->nama_laki,
                    'nik_laki' => $main->nik_laki,
                    'usia_laki' => $main->usia_laki,
                    'hp_laki' => $main->hp_laki,
                    'kerja_laki' => $main->pekerjaan_laki,

                    'provinsi' => $main->provinsi,
                    'kota' => $main->kota,
                    'kecamatan' => $main->kecamatan,
                    'kelurahan' => $main->kelurahan,
                    'rw' => $main->rw,
                    'rt' => $main->rt,
                    'posyandu' => $main->posyandu,
                    'status_risiko' => $main->status_risiko,
                    'status_hb' => $main->status_hb,
                    'status_kek' => $main->status_kek,
                    'tgl_pernikahan' => $main->tanggal_rencana_menikah,
                    'tgl_kunjungan' => $main->tanggal_pendampingan,

                    // RIWAYAT PEMERIKSAAN
                    'pemeriksaan_terakhir' => $items->map(function ($d) {
                        return [
                            'status_risiko' => $d->status_risiko,
                            'tanggal_pemeriksaan' => $d->tanggal_pemeriksaan,
                            'berat_perempuan' => $d->berat_perempuan,
                            'tinggi_perempuan' => $d->tinggi_perempuan,
                            'imt_perempuan' => $d->imt_perempuan,
                            'hb_perempuan' => $d->hb_perempuan,
                            'status_hb' => $d->status_hb,
                            'lila_perempuan' => $d->lila_perempuan,
                            'status_kek' => $d->status_kek,
                            'riwayat_penyakit' => $d->riwayat_penyakit,
                            'terpapar_rokok' => $d->terpapar_rokok,
                            'menggunakan_jamban' => $d->menggunakan_jamban,
                            'sumber_air_bersih' => $d->sumber_air_bersih
                        ];
                    })->values(),

                    // RIWAYAT PENDAMPINGAN
                    'riwayat_pendampingan' => $items->whereNotNull('tanggal_pendampingan')->map(function ($d) {
                        return [
                            'tanggal_pendampingan' => $d->tanggal_pendampingan,
                            'nama_petugas' => $d->nama_petugas,
                        ];
                    })->values(),
                ];
            })
            ->values();
    }

    public function status(Request $request)
    {
        try {
            // =====================================
            // 2. Tentukan periode (default H-1 bulan)
            // =====================================
            if ($request->filled('periode')) {
                $periode = Carbon::createFromFormat('!Y-m', $request->periode);
                $periodeAwal = $periode->copy()->startOfMonth();
                $periodeAkhir = $periode->copy()->endOfMonth();
                //dd($periode);
            } else {
                $periode = now()->subMonths(1)->startOfMonth();
                $periodeAwal = $periode->copy()->startOfMonth();
                $periodeAkhir = $periode->copy()->endOfMonth();
            }

            $data = Catin::get();

            /* $data = $data->groupBy('nik_perempuan')->map(function ($group) {
                return $group->sortByDesc('tanggal_pendampingan')->first();
            }); */

            $dataRaw = $data;

            $data = $data->filter(function ($item) use ($request) {
                if (!empty($request->provinsi) &&
                    strtolower(trim($item->provinsi)) !== strtolower(trim($request->provinsi))) {
                    return false;
                }

                if (!empty($request->kota) &&
                    strtolower(trim($item->kota)) !== strtolower(trim($request->kota))) {
                    return false;
                }

                if (!empty($request->kecamatan) &&
                    strtolower(trim($item->kecamatan)) !== strtolower(trim($request->kecamatan))) {
                    return false;
                }

                if (!empty($request->kelurahan) &&
                    strtolower(trim($item->kelurahan)) !== strtolower(trim($request->kelurahan))) {
                    return false;
                }

                return true;
            });


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
                        [
                            'title' => 'Anemia',
                            'value' => 0,
                            'percent' => '0%',
                            'color' => 'danger',
                            'trend' => [],
                        ],
                        [
                            'title' => 'KEK',
                            'value' => 0,
                            'percent' => '0%',
                            'color' => 'warning',
                            'trend' => [],
                        ],
                        [
                            'title' => 'Risiko Usia',
                            'value' => 0,
                            'percent' => '0%',
                            'color' => 'violet',
                            'trend' => [],
                        ],
                        [
                            'title' => 'Total Kasus',
                            'value' => 0,
                            'percent' => '0%',
                            'color' => 'success',
                            'trend' => [],
                        ],
                        [
                            'title' => 'Total Calon Pengantin',
                            'value' => 0,
                            'percent' => '0%',
                            'color' => 'secondary',
                            'trend' => [],
                        ],
                    ],
                    'kelurahan' => '',
                ], 200);
            }

            $jml = $data
            ->groupBy('nik_perempuan')
            ->map(fn ($g) => $g->first())
            ->values();

            $total = $jml->count();
            //$total = $data->count();

            // =====================================
            // 5. Hitung status berdasarkan FIELD BARU
            // =====================================
            $count = [
                'Anemia' => 0,
                'KEK' => 0,
                'Risiko Usia' => 0,
                'Total Kasus' => 0,
                'Total Calon Pengantin' => $data->count(),
            ];

            foreach ($data as $row) {
                $hbStatus = strtoupper($row['status_hb'] ?? '');
                $lilaStatus = strtoupper($row['status_kek'] ?? '');
                $riskStatus = strtoupper($row['status_risiko'] ?? '');

                if ($hbStatus === 'ANEMIA')
                    $count['Anemia']++;

                if ($lilaStatus === 'KEK')
                    $count['KEK']++;

                if ($riskStatus === 'BERISIKO')
                    $count['Risiko Usia']++;
            }
            $count['Total Kasus'] = $count['Anemia'] + $count['KEK'] + $count['Risiko Usia'];

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
                    $groupedMonth = $monthData->groupBy('nik_perempuan')->map(function ($group) {
                        return $group->sortByDesc('tanggal_pendampingan')->first();
                    });

                    $totalMonth = $groupedMonth->count();
                    $jumlah = 0;
                    $case = [];

                    foreach ($groupedMonth as $row) {
                        $hbStatus = strtoupper($row->status_hb ?? '');
                        $lilaStatus = strtoupper($row->status_kek ?? '');
                        $riskStatus = strtoupper($row->status_risiko ?? '');

                        if ($status === 'Anemia' && $hbStatus === 'ANEMIA')
                            $jumlah++;
                        if ($status === 'KEK' && $lilaStatus === 'KEK')
                            $jumlah++;
                        if ($status === 'Risiko Usia' && $riskStatus === 'BERISIKO')
                            $jumlah++;
                        if ($status === 'Total Kasus') {
                            if ($hbStatus === 'ANEMIA')
                                $jumlah++;
                            if ($lilaStatus === 'KEK')
                                $jumlah++;
                            if ($riskStatus === 'BERISIKO')
                                $jumlah++;
                        }
                        if ($status === 'Total Calon Pengantin') {
                            $jumlah = $totalMonth;
                        }
                    }

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
                    'Anemia' => 'danger',
                    'KEK' => 'warning',
                    'Risiko Usia' => 'violet',
                    'Total Kasus' => 'success',
                    'Total Calon Pengantin' => 'secondary'
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
            //dd($result);
            return response()->json([
                'total' => $total,
                'counts' => $result,
                'wilayah' => [
                    'provinsi'   => $request->provinsi,
                    'kota'       => $request->kota,
                    'kecamatan'  => $request->kecamatan,
                    'kelurahan'  => $request->kelurahan,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal mengambil data status catin',
                //'data' => $dataRaw,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function tren(Request $request)
    {
        try {

            $wilayah = [
                'kelurahan' => $request->kelurahan ?? null,
                'kecamatan' => $request->kecamatan ?? null,
                'kota' => $request->kota ?? null,
                'provinsi' => $request->provinsi ?? null,
            ];

            $query = Catin::query();

            // Filter default dari user (jika ada)
            if (!empty($wilayah['kelurahan'])) {
                $query->where('kelurahan', $wilayah['kelurahan']);
            }

            // Filter tambahan dari request
            foreach (['provinsi','kota','kecamatan','kelurahan', 'posyandu', 'rw', 'rt'] as $f) {
                if ($request->filled($f)) {
                    $query->where($f, $request->$f);
                }
            }

            // Tentukan periode berdasarkan filter
            if ($request->filled('periode')) {

                $periodeRaw = $request->periode;

                // Jika format "2025-03"
                if (preg_match('/^\d{4}-\d{2}$/', $periodeRaw)) {
                    // parse manual
                    $periode = Carbon::createFromFormat('Y-m-d', $periodeRaw . '-01');
                } else {
                    // format "November 2025"
                    $periode = Carbon::parse('01 ' . $periodeRaw);
                }

                $awal = $periode->copy()->startOfMonth();
                $akhir = $periode->copy()->endOfMonth();

            } else {
                $awal = Carbon::now()->subMonth()->startOfMonth();
                $akhir = Carbon::now()->subMonth()->endOfMonth();
            }

            // periode pembanding (bulan sebelumnya)
            $awalPrev = $awal->copy()->subMonth()->startOfMonth();
            $akhirPrev = $awal->copy()->subMonth()->endOfMonth();

            // ambil data untuk periode utama dan sebelumnya
            $current = (clone $query)
                ->whereBetween('tanggal_pendampingan', [$awal, $akhir])
                ->get();

            $previous = (clone $query)
                ->whereBetween('tanggal_pendampingan', [$awalPrev, $akhirPrev])
                ->get();

            //include detail catin
            $detailCatin = [
                $this->mapDetailCatin($current)
            ];

            // fungsi bantu: hitung status (tanpa intervensi)
            $countStatus = function ($rows) {
                $total = $rows->count();
                $kek = $rows->filter(fn($r) => $this->detectKek($r))->count();
                $anemia = $rows->filter(fn($r) => $this->detectAnemia($r))->count();
                $risti = $rows->filter(fn($r) => $this->detectRisti($r))->count();

                return [
                    'total' => $total,
                    'KEK' => $kek,
                    'Anemia' => $anemia,
                    'Risiko Usia' => $risti,
                ];
            };

            $currCount = $countStatus($current);
            $prevCount = $countStatus($previous);

            $statuses = ['KEK', 'Anemia', 'Risiko Usia'];
            $dataTable = [];

            foreach ($statuses as $status) {
                $jumlah = $currCount[$status] ?? 0;
                $total = $currCount['total'] ?? 0;
                $persen = $total > 0 ? round(($jumlah / $total) * 100, 1) : 0;

                $prevJumlah = $prevCount[$status] ?? 0;
                $prevTotal = $prevCount['total'] ?? 0;
                $prevPersen = $prevTotal > 0 ? round(($prevJumlah / $prevTotal) * 100, 1) : 0;

                // ============================
                // 🔥 HITUNG TREND BERDASARKAN JUMLAH
                // ============================
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



            // jika data kosong, kirim default 0
            if ($currCount['total'] === 0) {
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

                // tabel tren (ringkasan)
                'dataTable_catin' => $dataTable,

                // 🔥 DETAIL CATIN PER STATUS (CURRENT PERIOD)
                'detail_catin_tren' => $detailCatin,

                'periode' => [
                    'current' => [$awal->toDateString(), $akhir->toDateString()],
                    'previous' => [$awalPrev->toDateString(), $akhirPrev->toDateString()],
                ],
            ], 200);

        } catch (\Exception $e) {
            \Log::error('tren catin error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Gagal menghitung tren catin',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    protected function detectKek($row)
    {
        // cek kolom status_gizi_lila atau lila value
        if (isset($row->status_kek) && $row->status_kek) {
            $s = strtolower($row->status_kek);
            return Str::contains($s, ['kek']);
        }
        // fallback: jika LILA numeric threshold (mis. <23 untuk wanita hamil)
        if (isset($row->lila_perempuan) && is_numeric($row->lila_perempuan)) {
            return floatval($row->lila_perempuan) < 23.0;
        }
        return false;
    }

    protected function detectAnemia($row)
    {
        if (isset($row->status_hb) && $row->status_hb) {
            $s = strtolower($row->status_hb);
            return Str::contains($s, ['anemia']);
        }
        if (isset($row->hb_perempuan) && is_numeric($row->hb_perempuan)) {
            return floatval($row->hb_perempuan) < 11.0; // cutoff umum anemia pada ibu hamil
        }
        return false;
    }

    protected function detectRisti($row)
    {
        if (isset($row->status_risiko) && $row->status_risiko) {
            $s = strtolower($row->status_risiko);
            return Str::contains($s, ['berisiko']);
        }
        return false;
    }

    public function indikatorBulanan(Request $request)
    {
        try {
            $query = Catin::query();

            if ($request->filled('provinsi')){
                $query->where('provinsi', $request->provinsi);
            }
            if ($request->filled('kota')){
                $query->where('kota', $request->kota);
            }
            if ($request->filled('kecamatan')){
                $query->where('kecamatan', $request->kecamatan);
            }
            if ($request->filled('kelurahan')){
                $query->where('kelurahan', $request->kelurahan);
            }

            // ✅ Filter tambahan dari frontend
            foreach (['posyandu', 'rw', 'rt'] as $f) {
                if ($request->filled($f))
                    $query->where($f, $request->$f);
            }

            // ✅ Ambil data dalam 12 bulan terakhir
            $startDate = now()->subMonths(11)->startOfMonth();
            $endDate = now()->endOfMonth();

            $query->whereBetween('tanggal_pendampingan', [$startDate, $endDate]);


            $data = $query->get([
                'nik_perempuan',
                'tanggal_pendampingan',
                'status_kek',
                'status_hb',
                'status_risiko'
            ]);

            if ($data->isEmpty()) {
                return response()->json([
                    'labels' => [],
                    'indikator' => [],
                    'wilayah' => [
                        'provinsi' => $request->provinsi,
                        'kota' => $request->kota,
                        'kecamatan' => $request->kecamatan,
                        'kelurahan' => $request->kelurahan,
                    ],
                ]);
            }

            // ✅ Buat label bulan
            $months = collect(range(0, 11))
                ->map(fn($i) => now()->StartOfMonth()->subMonths(value: 11 - $i)->format('M Y'))
                ->values();

            // ✅ Siapkan struktur hasil
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
                    str_contains(strtolower($i->status_kek ?? ''), 'kek')
                )->count();

                $result['Anemia'][$idx] = $rows->filter(
                    fn($i) =>
                    str_contains(strtolower($i->status_hb ?? ''), 'anemia')
                )->count();

                $result['Berisiko'][$idx] = $rows->filter(
                    fn($i) =>
                    str_contains(strtolower($i->status_risiko ?? ''), 'berisiko')
                )->count();
            }

            return response()->json([
                'labels' => $months,
                'indikator' => $result,
                'wilayah' => [
                    'provinsi' => $request->provinsi,
                    'kota' => $request->kota,
                    'kecamatan' => $request->kecamatan,
                    'kelurahan' => $request->kelurahan,
                ],
            ]);

        } catch (\Throwable $th) {
            return response()->json([
                'error' => 'Gagal memuat data indikator bulanan',
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    public function delete($nik)
    {
        try {
            DB::beginTransaction();

            $deleted = false;


            // Catin (perempuan)
            if (Catin::where('nik_perempuan', $nik)->exists()) {
                Catin::where('nik_perempuan', $nik)->delete();
                $deleted = true;
            }

            if (!$deleted) {
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

            $deletedCount = Catin::whereIn('nik_perempuan', $niks)->delete();

            if ($deletedCount === 0) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada data catin yang terhapus'
                ], 404);
            }

            DB::commit();

            \App\Models\Log::create([
                'id_user'  => Auth::id(),
                'context'  => 'Data Catin',
                'activity' => 'Bulk Delete (' . $deletedCount . ' data)',
                'timestamp'=> now(),
            ]);

            return response()->json([
                'success' => true,
                'deleted' => $deletedCount
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            \Log::error('Bulk delete catin gagal', [
                'ids' => $request->ids,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus data catin'
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $user = Auth::user();

            // 1️⃣ Validasi (hanya yang mungkin dikirim FE)
            $validated = $request->validate([
                'nik' => 'required|string',

                'tanggal_pendampingan' => 'required|string',
                'tanggal_menikah' => 'nullable|string',

                'berat_perempuan' => 'nullable|numeric',
                'tinggi_perempuan' => 'nullable|numeric',
                'kadar_hb' => 'nullable|numeric',
                'lila_perempuan' => 'nullable|numeric',
            ]);

            // 2️⃣ Ambil data TERAKHIR
            $last = Catin::where('nik_perempuan', $validated['nik'])
                ->orderBy('tanggal_pendampingan', 'desc')
                ->first();

            // 3️⃣ Helper ambil data: request > last
            $get = fn ($key) =>
                $request->has($key)
                    ? $request->input($key)
                    : $last->{$key};

            // 4️⃣ Build data FINAL
            $data = [
                // Identitas (selalu dari FE)
                'nik_perempuan' => $validated['nik'],

                // Pendampingan
                'tanggal_pendampingan' => $this->convertDate($get('tanggal_pendampingan')),
                'tanggal_pemeriksaan' => $this->convertDate($get('tanggal_pendampingan')),

                // Pemeriksaan
                'berat_perempuan' => $get('berat_perempuan'),
                'tinggi_perempuan' => $get('tinggi_perempuan'),
                'hb_perempuan' => $get('kadar_hb'),
                'lila_perempuan' => $get('lila_perempuan'),

                // Rencana menikah
                'tanggal_rencana_menikah' => $this->convertDate(
                    $request->has('tanggal_menikah')
                        ? $request->tanggal_menikah
                        : $last->tanggal_rencana_menikah
                ),

                // Salin SEMUA field identitas dari data lama
                'nama_petugas' => $last->nama_petugas,
                'nama_perempuan' => $last->nama_perempuan,
                'pekerjaan_perempuan' => $last->pekerjaan_perempuan,
                'usia_perempuan' => $last->usia_perempuan,
                'hp_perempuan' => $last->hp_perempuan,

                'nama_laki' => $last->nama_laki,
                'nik_laki' => $last->nik_laki,
                'pekerjaan_laki' => $last->pekerjaan_laki,
                'usia_laki' => $last->usia_laki,
                'hp_laki' => $last->hp_laki,

                'pernikahan_ke' => $last->pernikahan_ke,

                'provinsi' => $last->provinsi,
                'kota' => $last->kota,
                'kecamatan' => $last->kecamatan,
                'kelurahan' => $last->kelurahan,
                'rw' => $last->rw,
                'rt' => $last->rt,
                'posyandu' => $last->posyandu,

                // Flags
                'terpapar_rokok' => $last->terpapar_rokok,
                'mendapat_ttd' => $last->mendapat_ttd,
                'menggunakan_jamban' => $last->menggunakan_jamban,
                'sumber_air_bersih' => $last->sumber_air_bersih,
                'punya_riwayat_penyakit' => $last->punya_riwayat_penyakit,
                'riwayat_penyakit' => $last->riwayat_penyakit,
                'mendapat_fasilitas_rujukan' => $last->mendapat_fasilitas_rujukan,
                'mendapat_kie' => $last->mendapat_kie,
                'mendapat_bantuan_pmt' => $last->mendapat_bantuan_pmt,

                // Meta
                'created_by' => $user?->id,
            ];

            // 5️⃣ Hitung ulang status
            $data['imt'] = $this->hitungIMT($data['berat_perempuan'], $data['tinggi_perempuan']);
            $data['status_kek'] = $this->statusKEK($data['lila_perempuan']);
            $data['status_hb'] = $this->statusHB($data['hb_perempuan']);
            $data['status_risiko'] = $this->statusRisiko($data['usia_perempuan']);

            // 6️⃣ CREATE BARU (riwayat)
            $catin = Catin::create($data);

            return response()->json([
                'success' => true,
                'message' => 'Pendampingan Catin berhasil disimpan',
                'data' => $catin
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan data',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function update(Request $request, $nik)
    {
        try {
            // 1. Validasi input
            $validated = $request->validate([
                'nik' => 'required|string',
                'nik_laki' => 'nullable|string',
                'nama_perempuan' => 'nullable|string',
                'nama_laki' => 'nullable|string',
                'usia_perempuan' => 'nullable|integer',
                'usia_laki' => 'nullable|integer',
                'tanggal_menikah' => 'nullable|string',
            ]);

            // 2. Update semua data catin
            $catin = Catin::where('nik_perempuan', $nik)->update([
                'nik_perempuan' =>  $this->normalizeNIK($validated['nik']),
                'nik_laki' => $this->normalizeNIK($validated['nik_laki'] ?? null),
                'nama_perempuan' =>  $this->normalizeText($validated['nama_perempuan'] ?? null),
                'nama_laki' =>  $this->normalizeText($validated['nama_laki'] ?? null),
                'usia_perempuan' => $validated['usia_perempuan'] ?? null,
                'usia_laki' => $validated['usia_laki'] ?? null,
                'tanggal_rencana_menikah' => $this->convertDate($validated['tanggal_menikah'] ?? null),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Data <strong>'.$this->normalizeText($validated['nama_perempuan']).'</strong> berhasil diperbarui',
                'data' => $catin
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal update data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function import(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|mimes:csv,txt|max:5120',
        ]);

        try {
            Excel::import(
                new CatinImportPendampingan(auth()->id()),
                $request->file('file')
            );


            return response()->json([
                'success' => true,
                'message' => 'Import data calon pengantin berhasil',
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
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


}

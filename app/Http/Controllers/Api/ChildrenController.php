<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Imports\ChildrenImportKunjungan;
use App\Imports\ChildrenImportPendampingan;
use App\Models\Child;
use App\Models\Cadre;
use App\Models\Kunjungan;
use App\Models\Intervensi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Maatwebsite\Excel\Facades\Excel;

class ChildrenController extends Controller
{
    const bulan = [
        '',
        'Januari',
        'Februari',
        'Maret',
        'April',
        'Mei',
        'Juni',
        'Juli',
        'Agustus',
        'September',
        'Oktober',
        'November',
        'Desember'
    ];

    public function index(Request $request)
    {
        $filters = $request->only([
            'periodeAwal',
            'periodeAkhir',
            'posyandu',
            'rw',
            'rt',
            'bbu',
            'tbu',
            'bbtb',
            'stagnan',
            'intervensi'
        ]);

        // Normalisasi format periode
        if (!empty($filters['periodeAwal'])) {
            try {
                $periodeAwal = explode(' ', $filters['periodeAwal']);
                if (count($periodeAwal) === 1) {
                    $periodeAwal = explode('+', $filters['periodeAwal']);
                }
                $bulanIndex = array_search($periodeAwal[0], self::bulan);
                $filters['periodeAwal'] = Carbon::createFromFormat('Y-m', $periodeAwal[1] . '-' . $bulanIndex)
                    ->startOfMonth()->format('Y-m-d');
            } catch (\Exception $e) {
                $filters['periodeAwal'] = null;
            }
        }

        if (!empty($filters['periodeAkhir'])) {
            try {
                $periodeAkhir = explode(' ', $filters['periodeAkhir']);
                if (count($periodeAkhir) === 1) {
                    $periodeAkhir = explode('+', $filters['periodeAkhir']);
                }
                $bulanIndex = array_search($periodeAkhir[0], self::bulan);
                $filters['periodeAkhir'] = Carbon::createFromFormat('Y-m', $periodeAkhir[1] . '-' . $bulanIndex)
                    ->endOfMonth()->format('Y-m-d');
            } catch (\Exception $e) {
                $filters['periodeAkhir'] = null;
            }
        }

        // ✅ 3. Dapatkan kelurahan user
        $filterProvinsi = $request->provinsi ?? null;
        $filterKota = $request->kota ?? null;
        $filterKecamatan = $request->kecamatan ?? null;
        $filterKelurahan = $request->kelurahan ?? null;

        // DEFAULT: 1 tahun terakhir kalau user tidak apply periode
        if (empty($filters['periodeAwal']) && empty($filters['periodeAkhir'])) {
            $filters['periodeAwal'] = now()->subYear()->startOfDay()->format('Y-m-d');
            $filters['periodeAkhir'] = now()->endOfDay()->format('Y-m-d');
        }

        $kunjungan = $this->getData(
            $filterProvinsi,
            $filterKota,
            $filterKecamatan,
            $filterKelurahan,
            $filters["periodeAwal"] ?? null,
            $filters["periodeAkhir"] ?? null,
            $filters["posyandu"] ?? null,
            $filters["rt"] ?? null,     // benar (rt dulu)
            $filters["rw"] ?? null,     // rw
            $filters["bbu"] ?? null,
            $filters["tbu"] ?? null,
            $filters["bbtb"] ?? null,
            $filters["stagnan"] ?? null,
            $filters["intervensi"] ?? null,
        );
        //$pendampingan = Child::where('kelurahan', $filterKelurahan)->get();
        $periodeAwal = $filters['periodeAwal'] ?? null;
        $periodeAkhir = $filters['periodeAkhir'] ?? null;

        $nikKunjungan = $kunjungan->pluck('nik')->unique();
        $intervensi = Intervensi::query()
            ->whereIn('nik_subjek', $nikKunjungan)
            ->where('status_subjek', 'anak')
            ->when($periodeAwal, fn($q) => $q->whereDate('tgl_intervensi', '>=', $filters["periodeAwal"]))
            ->when($periodeAkhir, fn($q) => $q->whereDate('tgl_intervensi', '<=', $filters["periodeAkhir"]))
            ->orderBy('tgl_intervensi', 'desc')
            ->get()->groupBy("nik_subjek");
        $pendampingan = Child::query()
            ->whereIn('nik_anak', $nikKunjungan)
            ->when($periodeAwal, fn($q) => $q->whereDate('tgl_pendampingan', '>=', $filters["periodeAwal"]))
            ->when($periodeAkhir, fn($q) => $q->whereDate('tgl_pendampingan', '<=', $filters["periodeAkhir"]))
            ->orderBy('tgl_pendampingan', 'desc')
            ->get()->groupBy("nik_anak");
        //dd($pendampingan.$nikKunjungan->tgl_pendampingan);
        $grouped = [];


        // 1️⃣ KUNJUNGAN — sumber utama data anak
        foreach ($kunjungan as $item) {
            $nik = $item->nik;

            if (!isset($grouped[$nik])) {
                $grouped[$nik] = [
                    'id' => $item->id,
                    'nama' => $item->nama_anak ?? '-',
                    'nik' => $item->nik ?? '',
                    'jk' => $item->jk ?? '-',
                    'provinsi' => $item->provinsi ?? '-',
                    'kota' => $item->kota ?? '-',
                    'kecamatan' => $item->kecamatan ?? '-',
                    'kelurahan' => $item->kelurahan ?? '-',
                    'rw' => $item->rw ?? '-',
                    'rt' => $item->rt ?? '-',
                    'kelahiran' => [],
                    'keluarga' => [],
                    'pendampingan' => [],
                    'posyandu' => [],
                    'intervensi' => []
                ];
            }

            $grouped[$nik]['posyandu'][] = [
                'posyandu' => $item->posyandu,
                'tgl_ukur' => $item->tgl_pengukuran->format("Y-m-d"),
                'usia' => $item->usia_saat_ukur,
                'bbu' => $item->bb_u,
                'tbu' => $item->tb_u,
                'bbtb' => $item->bb_tb,
                'bb' => $item->bb,
                'tb' => $item->tb,
                'bb_naik' => $item->naik_berat_badan,
            ];

            $grouped[$nik]['kelahiran'][] = [
                'tgl_lahir' => $item->tgl_lahir ?? '-',
                'bb_lahir' => $item->bb_lahir ?? '-',
                'pb_lahir' => $item->tb_lahir ?? '-',
            ];

            $grouped[$nik]['keluarga'][] = [
                'nama_ayah' => $item->peran === 'Ayah' ? $item->nama_ortu : '-',
                'nama_ibu' => $item->peran === 'Ibu' ? $item->nama_ortu : '-',
                'nama_ortu' => $item->nama_ortu,
                'pekerjaan_ayah' => '-',
                'pekerjaan_ibu' => '-',
                'usia_ayah' => '-',
                'usia_ibu' => '-',
                'anak_ke' => '-',
            ];

            $grouped[$nik]['pendampingan'][] = [
                'tanggal_pendampingan' => optional(
                    $pendampingan->get($nik)?->first()
                )->tgl_pendampingan ?? '-',
            ];

            $problem = 0;
            if ($item->bb_u != null && $item->bb_u !== 'Normal')
                $problem++;
            if ($item->tb_u != null && $item->tb_u !== 'Normal')
                $problem++;
            if ($item->bb_tb != null && $item->bb_tb !== 'Normal')
                $problem++;

            $h = $intervensi[$item->nik] ?? collect();
            // Default: tidak ada intervensi
            $item->intervensi_last_kategori = null;

            if ($h->count() > 0) {
                // Ambil intervensi terbaru
                $last = $h->first();
                $grouped[$nik]['intervensi'][] = [
                    'kader' => $last->petugas ?? '-',
                    'jenis' => $last->kategori ?? '-',
                    'tgl_intervensi' => $last->tgl_intervensi ?? '-',
                    'bantuan' => $last->bantuan ?? '-',
                ];
            }

            if ($problem > 0 && $h->count() == 0) {
                $grouped[$nik]['intervensi'][] = [
                    'kader' => '-',
                    'jenis' => 'Belum Mendapatkan Intervensi',
                    'tgl_intervensi' => '-',
                    'bantuan' => '-',
                ];
            }

            if ($problem === 0 && $h->count() == 0) {
                $grouped[$nik]['intervensi'][] = [
                    'kader' => '-',
                    'jenis' => '-',
                    'tgl_intervensi' => '-',
                    'bantuan' => '-',
                ];
            }

        }

        $filteredData = collect($grouped)->map(function ($anak) {
            return $anak;
        })->values();

        $latestData = $filteredData->map(function ($anak) {
            // kalau tidak ada pengukuran → skip
            if (empty($anak['posyandu']))
                return null;

            // Ambil tgl terbaru
            $latest = collect($anak['posyandu'])->sortByDesc('tgl_ukur')->first();

            return [
                'bbu' => strtolower($latest['bbu'] ?? ''),
                'tbu' => strtolower($latest['tbu'] ?? ''),
                'bbtb' => strtolower($latest['bbtb'] ?? ''),
                'naikBB' => $latest['bb_naik'] ?? null,
            ];
        })->filter(); // buang null

        $data = $latestData;
        $total = $data->count();

        $count = [
            'Stunting' => 0,
            'Wasting' => 0,
            'Underweight' => 0,
            'Normal' => 0,
            'Overweight' => 0,
            'BB Stagnan' => 0,
        ];

        foreach ($data as $a) {

            if (str_contains($a['tbu'], 'stunted')) {
                $count['Stunting']++;
            }

            if (str_contains($a['bbtb'], 'wasted')) {
                $count['Wasting']++;
            }

            if (str_contains($a['bbu'], 'underweight')) {
                $count['Underweight']++;
            }

            if (
                ($a['bbu'] === 'normal') &&
                ($a['tbu'] === 'normal') &&
                ($a['bbtb'] === 'normal')
            ) {
                $count['Normal']++;
            }

            if (str_contains($a['bbtb'], 'overweight') || str_contains($a['bbtb'], 'obesitas')) {
                $count['Overweight']++;
            }

            if (is_null($a['naikBB']) || $a['naikBB'] == 0) {
                $count['BB Stagnan']++;
            }
        }

        $result = [];
        foreach ($count as $title => $value) {

            $color = match ($title) {
                'Stunting' => 'danger',
                'Wasting' => 'warning',
                'Underweight' => 'violet',
                'Normal' => 'success',
                'BB Stagnan' => 'info',
                'Overweight' => 'dark',
            };

            $percent = $total ? round(($value / $total) * 100, 1) : 0;

            $result[] = [
                'title' => $title,
                'value' => $value,
                'percent' => "{$percent}%",
                'color' => $color,
                //'trend'   => $trendCount[$title] ?? [],
            ];
        }

        return response()->json([
            'message' => 'Data anak berhasil dimuat',
            'data_anak' => $filteredData,
            'status' => $result
        ]);

    }

    public function status(Request $request)
    {
        try {
            $filterProvinsi = $request->provinsi ?? null;
            $filterKota = $request->kota ?? null;
            $filterKecamatan = $request->kecamatan ?? null;
            $filterKelurahan = $request->kelurahan ?? null;

            // ================================
            // 2. Tentukan periode
            // ================================
            $filters = $request->only([
                'posyandu',
                'rw',
                'rt',
                'bbu',
                'tbu',
                'bbtb',
                'stagnan',
                'periodeAwal',
                'periodeAkhir'
            ]);

            if ($request->filled('periode')) {
                $tanggal = Carbon::createFromFormat('Y-m', $request->periode);
                $filters['periodeAwal'] = $tanggal->copy()->startOfMonth()->format('Y-m-d');
                $filters['periodeAkhir'] = $tanggal->copy()->endOfMonth()->format('Y-m-d');
            } else {
                $tanggal = now()->subMonth();
                $filters['periodeAwal'] = $tanggal->copy()->startOfMonth()->format('Y-m-d');
                $filters['periodeAkhir'] = $tanggal->copy()->endOfMonth()->format('Y-m-d');
            }


            // ================================
            // 3. Query utama: Kunjungan
            // ================================
            $kunjungan = $this->getData(
                $filterProvinsi ?? null,
                $filterKota ?? null,
                $filterKecamatan ?? null,
                $filterKelurahan ?? null,
                $filters['periodeAwal'],
                $filters['periodeAkhir'],
                $filters["posyandu"],
                $filters["rt"],
                $filters["rw"],
                null,
                null,
                null,
                null,
                null,
            );

            if ($kunjungan->isEmpty()) {
                return response()->json([
                    'total' => 0,
                    'counts' => [
                        ['title' => 'Stunting', 'value' => 0, 'percent' => '0%', 'color' => 'danger', 'trend' => []],
                        ['title' => 'Wasting', 'value' => 0, 'percent' => '0%', 'color' => 'warning', 'trend' => []],
                        ['title' => 'Underweight', 'value' => 0, 'percent' => '0%', 'color' => 'violet', 'trend' => []],
                        ['title' => 'Normal', 'value' => 0, 'percent' => '0%', 'color' => 'success', 'trend' => []],
                        ['title' => 'BB Stagnan', 'value' => 0, 'percent' => '0%', 'color' => 'info', 'trend' => []],
                        ['title' => 'Overweight', 'value' => 0, 'percent' => '0%', 'color' => 'dark', 'trend' => []],
                    ],
                    'kelurahan' => $filterKelurahan,
                ]);
            }

            // ================================
            // 4. Normalize data
            // ================================
            $data = $kunjungan->map(fn($item) => [
                'bbu' => strtolower($item->bb_u ?? ''),
                'tbu' => strtolower($item->tb_u ?? ''),
                'bbtb' => strtolower($item->bb_tb ?? ''),
                'naikBB' => $item->naik_berat_badan,
            ]);

            $total = $data->count();

            // ================================
            // 5. Hitung status gizi
            // ================================
            $count = [
                'Stunting' => 0,
                'Wasting' => 0,
                'Underweight' => 0,
                'Normal' => 0,
                'Overweight' => 0,
                'BB Stagnan' => 0,
            ];

            foreach ($data as $a) {
                if (str_contains($a['tbu'], 'stunted'))
                    $count['Stunting']++;
                if (str_contains($a['bbtb'], 'wasted'))
                    $count['Wasting']++;
                if (str_contains($a['bbu'], 'underweight'))
                    $count['Underweight']++;
                if ($a['bbu'] === 'normal' && $a['tbu'] === 'normal' && $a['bbtb'] === 'normal')
                    $count['Normal']++;
                if (str_contains($a['bbtb'], 'overweight') || str_contains($a['bbtb'], 'obes'))
                    $count['Overweight']++;
                if (is_null($a['naikBB']) || $a['naikBB'] == 0)
                    $count['BB Stagnan']++;
            }

            // ================================
            // 6. HITUNG TREND 3 BULAN TERAKHIR (DINAMIS DARI PERIODE)
            // ================================
            $trendCount = [];

            // Tentukan cut-off
            if ($request->filled('periode')) {
                // periode format: YYYY-MM
                $cutoff = Carbon::createFromFormat('Y-m', $request->periode)->startOfMonth();
            } else {
                $cutoff = now()->startOfMonth();
            }

            foreach ($count as $status => $val) {

                $trend = collect();

                // Loop 3 bulan terakhir: cut-off -2 sampai cut-off
                for ($i = 6; $i >= 0; $i--) {

                    $tgl = $cutoff->copy()->subMonths($i);
                    $awal = $tgl->startOfMonth()->format('Y-m-d');
                    $akhir = $tgl->endOfMonth()->format('Y-m-d');

                    // Ambil kunjungan bulan tersebut
                    /* $kunjunganBulan = Kunjungan::query()
                        ->where('kelurahan', $filterKelurahan)
                        ->whereDate('tgl_pengukuran', '>=', $awal)
                        ->whereDate('tgl_pengukuran', '<=', $akhir)
                        ->get(); */
                    $kunjunganBulan = $this->getData(
                        $filterProvinsi,
                        $filterKota,
                        $filterKecamatan,
                        $filterKelurahan,
                        $awal,
                        $akhir,
                        $filters["posyandu"] ?? null,
                        $filters["rt"] ?? null,
                        $filters["rw"] ?? null,
                        null,
                        null,
                        null,
                        null,
                        null
                    );


                    $totalBulan = $kunjunganBulan->count();
                    $jumlahStatusBulan = 0;

                    foreach ($kunjunganBulan as $row) {
                        $bbu = strtolower($row->bb_u ?? '');
                        $tbu = strtolower($row->tb_u ?? '');
                        $bbtb = strtolower($row->bb_tb ?? '');

                        if ($status === 'Stunting' && str_contains($tbu, 'stunted'))
                            $jumlahStatusBulan++;
                        if ($status === 'Wasting' && str_contains($bbtb, 'wasted'))
                            $jumlahStatusBulan++;
                        if ($status === 'Underweight' && str_contains($bbu, 'underweight'))
                            $jumlahStatusBulan++;
                        if (
                            $status === 'Normal'
                            && $bbu === 'normal' && $tbu === 'normal' && $bbtb === 'normal'
                        )
                            $jumlahStatusBulan++;
                        if (
                            $status === 'Overweight'
                            && (str_contains($bbtb, 'overweight') || str_contains($bbtb, 'obes'))
                        )
                            $jumlahStatusBulan++;
                        if (
                            $status === 'BB Stagnan'
                            && (is_null($row->naik_berat_badan) || $row->naik_berat_badan == 0)
                        )
                            $jumlahStatusBulan++;
                    }

                    $persen = $totalBulan ? round(($jumlahStatusBulan / $totalBulan) * 100, 1) : 0;

                    $trend->push([
                        'bulan' => $tgl->format('M'),
                        'persen' => $persen,
                        'jumlah' => $jumlahStatusBulan
                    ]);
                }

                $trendCount[$status] = $trend;
            }

            // ================================
            // 7. Format output + TREND
            // ================================
            $result = [];
            foreach ($count as $title => $value) {

                $color = match ($title) {
                    'Stunting' => 'danger',
                    'Wasting' => 'warning',
                    'Underweight' => 'violet',
                    'Normal' => 'success',
                    'BB Stagnan' => 'info',
                    'Overweight' => 'dark',
                };

                $percent = $total ? round(($value / $total) * 100, 1) : 0;

                $result[] = [
                    'title' => $title,
                    'value' => $value,
                    'percent' => "{$percent}%",
                    'color' => $color,
                    'trend' => $trendCount[$title] ?? [],
                ];
            }

            return response()->json([
                'total' => $total,
                'counts' => $result,
                'kelurahan' => $filterKelurahan,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal mengambil data status gizi anak',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function infoBoxes(Request $request)
    {
        // ======================================================
        // 2. Atur periode
        // ======================================================
        if ($request->filled('periode')) {
            $tanggal = Carbon::createFromFormat('Y-m', $request->periode);
            $bulanIni = $tanggal->format('Y-m');
            $bulanLalu = $tanggal->copy()->subMonth()->format('Y-m');
        } else {
            $bulanIni = now()->subMonth()->format('Y-m');
            $bulanLalu = now()->subMonths(2)->format('Y-m');
        }

        // ======================================================
        // 3. Query kunjungan (2 bulan)
        // ======================================================
        $query = Kunjungan::query()
            ->whereBetween(DB::raw("DATE_FORMAT(tgl_pengukuran,'%Y-%m')"), [$bulanLalu, $bulanIni]);

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

        $data = $query->get();

        // ======================================================
        // 4. Penampung
        // ======================================================
        $stuntingPerBulan = [];
        $stuntingByDesa = [];

        $giziNow = ['stunting' => 0, 'wasting' => 0, 'underweight' => 0];
        $giziPrev = ['stunting' => 0, 'wasting' => 0, 'underweight' => 0];

        $nikBermasalah = [];
        $dataPending = 0;

        // ======================================================
        // 5. Loop kunjungan
        // ======================================================
        foreach ($data as $child) {

            $ym = Carbon::parse($child->tgl_pengukuran)->format('Y-m');

            // Data pending
            if (
                empty($child->nik) ||
                empty($child->bb) || $child->bb == 0 ||
                empty($child->tb) || $child->tb == 0
            ) {
                $dataPending++;
            }

            // Status gizi
            $isStunting = in_array($child->tb_u, ['Stunted', 'Severely Stunted']);
            $isWasting = in_array($child->bb_tb, ['Wasted', 'Severely Wasted']);
            $isUnder = in_array($child->bb_u, ['Underweight', 'Severely Underweight']);

            // Masukkan ke bulan ini / bulan lalu
            if ($ym === $bulanIni) {
                if ($isStunting)
                    $giziNow['stunting']++;
                if ($isWasting)
                    $giziNow['wasting']++;
                if ($isUnder)
                    $giziNow['underweight']++;
            } elseif ($ym === $bulanLalu) {
                if ($isStunting)
                    $giziPrev['stunting']++;
                if ($isWasting)
                    $giziPrev['wasting']++;
                if ($isUnder)
                    $giziPrev['underweight']++;
            }

            // Perhitungan stunting khusus
            if ($isStunting) {
                $stuntingPerBulan[$ym] = ($stuntingPerBulan[$ym] ?? 0) + 1;

                if ($child->kelurahan) {
                    $stuntingByDesa[$child->kelurahan] =
                        ($stuntingByDesa[$child->kelurahan] ?? 0) + 1;
                }
            }

            // Anak bermasalah untuk PMT
            if (($isStunting || $isWasting || $isUnder) && !empty($child->nik)) {
                $nikBermasalah[] = $child->nik;
            }
        }

        // ======================================================
        // 6. Cek Intervensi PMT
        // ======================================================
        $nikBermasalah = array_unique($nikBermasalah);

        $nikPMT = Intervensi::where('status_subjek', 'anak')
            ->whereIn('nik_subjek', $nikBermasalah)
            ->pluck('nik_subjek')
            ->toArray();

        $intervensiKurang = count(array_diff($nikBermasalah, $nikPMT));

        // ======================================================
        // 7. Kasus Baru (Per Status)
        // ======================================================
        $bulanIniData = $data->filter(
            fn($c) =>
            Carbon::parse($c->tgl_pengukuran)->format('Y-m') == $bulanIni
        );

        $bulanLaluData = $data->filter(
            fn($c) =>
            Carbon::parse($c->tgl_pengukuran)->format('Y-m') == $bulanLalu
        );

        // Nik bermasalah bulan lalu
        $nikPrev = [];
        foreach ($bulanLaluData as $c) {
            if (
                in_array($c->tb_u, ['Stunted', 'Severely Stunted']) ||
                in_array($c->bb_tb, ['Wasted', 'Severely Wasted']) ||
                in_array($c->bb_u, ['Underweight', 'Severely Underweight'])
            ) {
                if ($c->nik)
                    $nikPrev[] = $c->nik;
            }
        }
        $nikPrev = array_unique($nikPrev);

        // Kasus baru per status
        $kasusBaru = [
            'total' => 0,
            'stunting' => 0,
            'wasting' => 0,
            'underweight' => 0,
        ];

        foreach ($bulanIniData as $c) {

            $nik = $c->nik;
            if (!$nik)
                continue;

            $isStunting = in_array($c->tb_u, ['Stunted', 'Severely Stunted']);
            $isWasting = in_array($c->bb_tb, ['Wasted', 'Severely Wasted']);
            $isUnder = in_array($c->bb_u, ['Underweight', 'Severely Underweight']);

            $isMasalah = $isStunting || $isWasting || $isUnder;

            // Baru jika BUKAN bermasalah bulan lalu
            if ($isMasalah && !in_array($nik, $nikPrev)) {

                $kasusBaru['total']++;

                if ($isStunting)
                    $kasusBaru['stunting']++;
                if ($isWasting)
                    $kasusBaru['wasting']++;
                if ($isUnder)
                    $kasusBaru['underweight']++;
            }
        }

        // ======================================================
        // 8. Tren Stunting
        // ======================================================
        $stuntingNow = $stuntingPerBulan[$bulanIni] ?? 0;
        $stuntingPrev = $stuntingPerBulan[$bulanLalu] ?? 0;

        if ($stuntingPrev == 0 && $stuntingNow > 0) {
            $trendStunting = 100;
        } elseif ($stuntingPrev == 0) {
            $trendStunting = 0;
        } else {
            $trendStunting = (($stuntingNow - $stuntingPrev) / $stuntingPrev) * 100;
        }

        // ======================================================
        // 9. Tren Gizi Detail
        // ======================================================
        $trendGizi = [];
        foreach ($giziNow as $key => $valNow) {

            $valPrev = $giziPrev[$key];

            if ($valPrev == 0 && $valNow > 0) {
                $growth = 100;
            } elseif ($valPrev == 0) {
                $growth = 0;
            } else {
                $growth = (($valNow - $valPrev) / $valPrev) * 100;
            }

            $trendGizi[$key] = [
                'now' => $valNow,
                'prev' => $valPrev,
                'trend' => $growth,
            ];
        }

        // ======================================================
        // 10. Desa tertinggi
        // ======================================================
        $desaTertinggi = count($stuntingByDesa)
            ? collect($stuntingByDesa)->sortDesc()->keys()->first()
            : $request->kelurahan;

        // ======================================================
        // 11. Output
        // ======================================================
        return response()->json([
            'boxes' => [
                [
                    'type' => 'danger',
                    'title' => "Stunting " . ($trendStunting >= 0 ? 'naik' : 'turun') . " " . number_format(abs($trendStunting), 1) . "%",
                    'desc' => "
                        Bulan ini: <strong>{$stuntingNow}</strong><br>
                        Bulan lalu: <strong>{$stuntingPrev}</strong><br>
                        Tertinggi di Desa <strong>{$desaTertinggi}</strong>.
                    "
                ],
                [
                    'type' => 'warning',
                    'title' => 'Intervensi',
                    'desc' => "{$intervensiKurang} anak bermasalah gizi belum mendapat Intervensi."
                ],
                [
                    'type' => 'info',
                    'title' => 'Kasus Baru',
                    'desc' => "
                        <strong>{$kasusBaru['stunting']}</strong> kasus baru Stunting<br>
                        <strong>{$kasusBaru['wasting']}</strong> kasus baru Wasting<br>
                        <strong>{$kasusBaru['underweight']}</strong> kasus baru Underweight
                    "
                ],
                [
                    'type' => 'success',
                    'title' => 'Data Pending',
                    'desc' => "{$dataPending} anak memiliki data tidak lengkap (NIK/BB/TB kosong)."
                ],
            ]
        ]);
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

    public function kunjungan()
    {
        // Ambil semua data anak
        $data = Kunjungan::orderBy('nik')->get();

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    public function import_kunjungan(Request $request)
    {
        try {
            $request->validate([
                'file' => [
                    'required',
                    'file',
                    'max:5120',
                    function ($attr, $file, $fail) {
                        $allowed = [
                            'text/csv',
                            'text/plain',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        ];

                        if (!in_array($file->getMimeType(), $allowed)) {
                            $fail('Tipe file tidak valid.');
                        }
                    },
                ],
            ]);

            DB::transaction(function () use ($request) {
                Excel::import(
                    new ChildrenImportKunjungan(auth()->id()),
                    $request->file('file')
                );
            });

            return response()->json([
                'message' => 'Berhasil mengunggah data anak',
            ]);
        } catch (\Throwable $th) {
            return response()->json([
            'message' => 'Gagal import CSV <br> Silahkan periksa kembali format CSV anda ',
            'detail' => $th->getMessage(),
        ], 422);
        }

    }

    public function import_pendampingan_v2(Request $request)
    {
        try {
            DB::transaction(function () use ($request) {
                Excel::import(new ChildrenImportPendampingan(auth()->id()), $request->file('file'));
            });

            return response()->json([
                'message' => 'Berhasil mengunggah data pendampingan anak',
            ], 200);

        } catch (\Throwable $e) {

            return response()->json([
                'message' => 'Gagal Menggunggah data - semua data batal diunggah',
                'detail' => $e->getMessage(),
            ], 422);
        }
    }

    public function import_pendampingan(Request $request)
    {
        // 🔍 Validasi file
        $validator = Validator::make($request->all(), [
            'file' => 'required|mimes:csv,txt|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $file = $request->file('file');

        if (!$file->isValid()) {
            return response()->json(['message' => 'File tidak valid.'], 400);
        }

        $path = $file->getRealPath();
        $handle = fopen($path, 'r');

        $header = null;
        $rows = [];
        $count = 0;

        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
            if (empty($data[0]))
                continue; // skip baris kosong

            if (!$header) {
                $header = $data;
                continue;
            }

            // Pastikan kolom tanggal pendampingan valid
            if (!isset($data[1]) || !preg_match('/\d{4}-\d{2}-\d{2}/', $data[1])) {
                continue; // skip baris yang bukan data
            }

            $count++;

            $rows[] = [
                'petugas' => $data[0] ?? null,
                'tgl_pendampingan' => $this->convertDate($data[1] ?? null),
                'nama_anak' => $data[2] ?? null,
                'nik_anak' => $data[3] ?? null,
                'jk' => $data[4] ?? null,
                'tgl_lahir' => $data[5] ?? null,
                'nama_ayah' => $data[6] ?? null,
                'nik_ayah' => $data[7] ?? null,
                'pekerjaan_ayah' => $data[8] ?? null,
                'usia_ayah' => $data[9] ?? null,
                'nama_ibu' => $data[10] ?? null,
                'nik_ibu' => $data[11] ?? null,
                'pekerjaan_ibu' => $data[12] ?? null,
                'usia_ibu' => $data[13] ?? null,
                'anak_ke' => $data[14] ?? null,
                'riwayat_4t' => $data[15] ?? null,
                'riwayat_kb' => $data[16] ?? null,
                'alat_kontrasepsi' => $data[17] ?? null,
                'provinsi' => $data[18] ?? null,
                'kota' => $data[19] ?? null,
                'kecamatan' => $data[20] ?? null,
                'kelurahan' => $data[21] ?? null,
                'rw' => $data[22] ?? null,
                'rt' => $data[23] ?? null,
                'bb_lahir' => $data[24] ?? null,
                'tb_lahir' => $data[25] ?? null,
                'bb' => $data[26] ?? null,
                'tb' => $data[27] ?? null,
                'lila' => $data[28] ?? null,
                'lika' => $data[29] ?? null,
                'asi' => $data[30] ?? null,
                'imunisasi' => $data[31] ?? null,
                'diasuh_oleh' => $data[32] ?? null,
                'rutin_posyandu' => $data[33] ?? null,
                'riwayat_penyakit_bawaan' => $data[34] ?? null,
                'penyakit_bawaan' => $data[35] ?? null,
                'riwayat_penyakit_6bulan' => $data[36] ?? null,
                'penyakit_6bulan' => $data[37] ?? null,
                'terpapar_asap_rokok' => $data[38] ?? null,
                'penggunaan_jamban_sehat' => $data[39] ?? null,
                'penggunaan_sab' => $data[40] ?? null,
                'apabila_ada_penyakit' => $data[41] ?? null,
                'memiliki_jaminan' => $data[42] ?? null,
                'kie' => $data[43] ?? null,
                'mendapatkan_bantuan' => $data[44] ?? null,
                'catatan' => $data[45] ?? null,
                'no_kk' => $data[46] ?? null,
            ];
        }

        fclose($handle);

        // ✅ Simpan batch (gunakan chunk)
        if (!empty($rows)) {
            foreach (array_chunk($rows, 100) as $chunk) {
                foreach ($chunk as $row) {

                    // Hitung usia berdasarkan tgl_lahir dan tgl_pendampingan
                    $usia = $this->hitungUmurBulan($row['tgl_lahir'], $row['tgl_pendampingan']);

                    // Hitung z-score dummy (bisa diganti nanti pakai WHO standard)
                    $z_bbu = $this->hitungZScore('BB/U', $row['jk'], $usia, $row['bb']);
                    $z_tbu = $this->hitungZScore('TB/U', $row['jk'], $usia, $row['tb']);
                    $z_bbtb = $this->hitungZScore('BB/TB', $row['jk'], $row['tb'], $row['bb']);

                    // Tentukan status berdasarkan z-score
                    $status_bbu = $this->statusBBU($z_bbu);
                    $status_tbu = $this->statusTBU($z_tbu);
                    $status_bbtb = $this->statusBBTB($z_bbtb);

                    // Gabungkan hasil perhitungan
                    $row = array_merge($row, [
                        'usia' => $usia,
                        'zs_bb_u' => $z_bbu,
                        'bb_u' => $status_bbu,
                        'zs_tb_u' => $z_tbu,
                        'tb_u' => $status_tbu,
                        'zs_bb_tb' => $z_bbtb,
                        'bb_tb' => $status_bbtb,
                    ]);

                    // Simpan data pendampingan anak
                    $child = Child::create(attributes: $row);

                    // ✅ Simpan wilayah & posyandu
                    $wilayah = \App\Models\Wilayah::firstOrCreate([
                        'provinsi' => $row['provinsi'],
                        'kota' => $row['kota'],
                        'kecamatan' => $row['kecamatan'],
                        'kelurahan' => $row['kelurahan'],
                    ]);

                    // ✅ Simpan keluarga bila ada no_kk
                    if (!empty($row['no_kk'])) {
                        $keluarga = \App\Models\Keluarga::firstOrCreate(
                            ['no_kk' => $row['no_kk']],
                            [
                                'alamat' => 'desa ' . $row['kelurahan'] . ', kec. ' . $row['kecamatan'] . ', kota/kab. ' . $row['kota'],
                                'rt' => $row['rt'] ?? null,
                                'rw' => $row['rw'] ?? null,
                                'id_wilayah' => $wilayah->id,
                                'is_pending' => false,
                            ]
                        );
                    }

                    // ✅ Log aktivitas
                    \App\Models\Log::create([
                        'id_user' => \Auth::id(),
                        'context' => 'Pendampingan Anak',
                        'activity' => 'Import pendampingan anak ' . ($row['nama_anak'] ?? '-'),
                        'timestamp' => now(),
                    ]);
                }
            }
        }

        return response()->json([
            'message' => "Berhasil import {$count} data pendampingan anak",
            'count' => $count,
        ], 200);
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

            $duplikat = Intervensi::where('nik_subjek', $nik)
                ->whereDate('tgl_intervensi', $tglUkur)
                ->first();

            if ($duplikat) {
                throw new \Exception(
                    "Data atas <strong>{$nik}</strong>, <strong>{$nama}</strong> sudah diunggah pada <strong>"
                    . $duplikat->created_at->format('d-m-Y')."</strong>"
                );
            }

            Intervensi::create([
                'petugas' => $this->normalizeText($row[0] ?? null),
                'tgl_intervensi' => $this->convertDate($row[1]?? null),
                'desa' => $this->normalizeText($row[2] ?? null),
                'nama_subjek' => $this->normalizeText($row[3] ?? null),
                'nik_subjek' => $this->normalizeNik($row[4] ?? null),
                'status_subjek' => 'ANAK',
                'jk' => $this->normalizeText($row[5] ?? null),
                'tgl_lahir' => $this->convertDate($row[6]?? null),
                'nama_wali' => $this->normalizeText($row[7] ?? null),
                'nik_wali' => $this->normalizeNik($row[8] ?? null),
                'status_wali' => $this->normalizeText($row[9] ?? null),
                'rt' => $row[10] ?? null,
                'rw' => $row[11] ?? null,
                'posyandu' => $this->normalizeText($row[12] ?? null),
                'umur_subjek' => $this->hitungUmurBulan(
                        $this->convertDate($row[6] ?? null),
                        $this->convertDate($row[1] ?? null)
                    ),
                'bantuan' => $row[13] ?? null,
                'kategori' => $this->normalizeText($row[14] ?? null),
            ]);

            $count++;
        }

        fclose($handle);

        return response()->json(['message' => "Berhasil unggah data intervensi."]);
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
        //return $diff->y * 12 + $diff->m + ($diff->d / 30);
    }

    /** Hitung Z-Score sesuai jenis pengukuran */
    private function hitungZScore($tipe, $jk, $usiaOrTb, $bb)
    {
        $sex = in_array($jk, ['l', 'laki-laki', 'male', '1']) ? 1 : 2;

        switch ($tipe) {
            case 'BB/U':
                $row = \DB::table('who_weight_for_age')
                    ->where('sex', $sex)
                    ->orderByRaw('ABS(age_month - ?)', [$usiaOrTb])
                    ->first();

                break;

            case 'TB/U':
                $row = \DB::table('who_weight_for_height')
                    ->where('sex', $sex)
                    ->orderByRaw('ABS(height_cm - ?)', [$usiaOrTb])
                    ->first();

                break;

            case 'BB/TB':
                if ($usiaOrTb < 45 || $usiaOrTb > 120) return null;

                $row = \DB::table('who_weight_for_height')
                    ->where('sex', $sex)
                    ->orderByRaw('ABS(height_cm - ?)', [$usiaOrTb])
                    ->first();
                break;

            default:
                return null;
        }

        if (!$row)
            return null;

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

    /** Dummy status berdasar z-score */
    private function statusBBU($z)
    {
        if (is_null($z))
            return null;
        if ($z < -3)
            return 'Severely Underweight';
        if ($z < -2)
            return 'Underweight';
        if ($z <= 1)
            return 'Normal';
        if ($z <= 2)
            return 'Risiko BB Lebih';
        return 'BB Lebih';
    }

    private function statusTBU($z)
    {
        if (is_null($z))
            return null;
        if ($z < -3)
            return 'Severely Stunted';
        if ($z < -2)
            return 'Stunted';
        if ($z <= 2)
            return 'Normal';
        return 'Tinggi';
    }

    private function statusBBTB($z)
    {
        if (is_null($z))
            return null;
        if ($z < -3)
            return 'Severely Wasted';
        if ($z < -2)
            return 'Wasted';
        if ($z <= 1)
            return 'Normal';
        if ($z <= 2)
            return 'Possible Risk of Overweight';
        if ($z <= 3)
            return 'Overweight';
        return 'Obese';
    }

    /** Cek apakah berat badan naik dibanding pengukuran terakhir */
    private function cekNaikBB($nik, $bbSekarang, $tglUkur, $tipe = 'kunjungan')
    {
        // Pilih model berdasarkan tipe
        $model = match ($tipe) {
            'pendampingan' => Child::class,
            default => Kunjungan::class,
        };

        // Cek data terakhir berdasarkan NIK dan tanggal
        $last = $model::where('nik', $nik)
            ->where('tgl_pengukuran', '<', $tglUkur)
            ->orderBy('tgl_pengukuran', 'desc')
            ->first();

        // Jika tidak ada data sebelumnya → null
        if (!$last)
            return null;

        // Bandingkan berat badan
        return $bbSekarang > $last->bb;
    }

    /** Konversi tanggal dari dd/mm/yyyy ke
     * yyy-mm-dd */
    // Irul Custom ConvertDate
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

    /* private function convertDate($date)
    {
        if (!$date)
            return null;
        $parts = preg_split('/[\/\-]/', $date);
        if (count($parts) === 3) {
            return strlen($parts[2]) === 4
                ? "{$parts[2]}-{$parts[1]}-{$parts[0]}"
                : "{$parts[0]}-{$parts[1]}-{$parts[2]}";
        }
        return null;
    } */

    private function mapDetailAnak($rows)
    {
        return $rows
            ->groupBy('nik')
            ->map(function ($items) {
                $main = $items->sortByDesc('tgl_pengukuran')->first();

                $intervensi = Intervensi::where('nik_subjek', $main->nik)
                ->orderBy('tgl_intervensi', 'DESC')
                ->first();

                return [
                    // informasi utama
                    'id' => $main->id,
                    'nama' => $main->nama_anak ?? '-',
                    'nik' => $main->nik ?? '',
                    'jk' => $main->jk ?? '-',
                    'posyandu' => $main->posyandu ?? '-',
                    'usia'     => $main->usia_saat_ukur ?? '-',
                    'tgl_ukur' => optional($main->tgl_pengukuran)->format('Y-m-d') ?? '-',
                    'bbu'      => $main->bb_u ?? '-',
                    'tbu'      => $main->tb_u ?? '-',
                    'bbtb'     => $main->bb_tb ?? '-',
                    'jenis'    => $intervensi->kategori ?? '-',
                    'rw'       => $main->rw ?? '-',
                    'rt'       => $main->rt ?? '-',
                ];
            })
            ->values();
            //dd($rows);
    }

    public function tren(Request $request)
    {
       $query = Kunjungan::query();
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
        if ($request->filled('periode')) {
            $tanggal = Carbon::createFromFormat('Y-m', $request->periode);
            $bulanIni = $tanggal->format('Y-m');
            $bulanLalu = $tanggal->subMonth()->format('Y-m');
        } else {
            // default: bulan ini - 1
            $bulanIni = now()->subMonth()->format('Y-m');
            $bulanLalu = now()->subMonths(2)->format('Y-m');
        }

        $query->where(function ($q) use ($bulanIni, $bulanLalu) {
            $q->whereBetween(\DB::raw("DATE_FORMAT(tgl_pengukuran, '%Y-%m')"), [$bulanLalu, $bulanIni]);
        });

        // 6. Ambil seluruh data Kunjungan yg relevan
        $data = $query->get();

        //dump($data);
        // bikin range bulan ini
        $awalBulanIni  = Carbon::createFromFormat('Y-m', $bulanIni)->startOfMonth();
        $akhirBulanIni = Carbon::createFromFormat('Y-m', $bulanIni)->endOfMonth();

        // clone query biar gak ganggu query utama
        $currentQuery = clone $query;

        $current = $currentQuery
            ->whereBetween('tgl_pengukuran', [$awalBulanIni, $akhirBulanIni])
            ->get();

        // 7. Kirim ke buildTrend dgn bulan yang sudah ditentukan
        $tren = [
            'bb' => $this->buildTrend($data, 'bb_u', [
                'Berat Badan Sangat Kurang (Severely Underweight)' => ["Severely Underweight"],
                'Berat Badan Kurang (Underweight)' => ['Underweight'],
                'Berat Badan Normal' => ['Normal'],
                'Risiko Berat Badan Lebih' => ['Risiko BB Lebih'],
            ], $bulanIni, $bulanLalu),

            'tb' => $this->buildTrend($data, 'tb_u', [
                'Sangat Pendek (Severely Stunted)' => ["Severely Stunted"],
                'Pendek (Stunted)' => ['Stunted'],
                'Normal' => ["Normal"],
                'Tinggi' => ['Tinggi'],
            ], $bulanIni, $bulanLalu),

            'bbtb' => $this->buildTrend($data, 'bb_tb', [
                'Gizi Buruk (Severely Wasted)' => ['Severely Wasted'],
                'Gizi Kurang (Wasted)' => ['Wasted'],
                'Gizi Baik (Normal)' => ['Normal'],
                'Berisiko Gizi Lebih (Possible Risk of Overweight)' => ['Possible risk of Overweight'],
                'Gizi Lebih (Overweight)' => ['Overweight'],
                'Obesitas (Obese)' => ['Obese', 'Obesitas'],
            ], $bulanIni, $bulanLalu),

            'detail' => $this->mapDetailAnak($current),
        ];

        return response()->json($tren);
    }

    private function buildTrend($data, $field, $categories, $currentMonth, $previousMonth)
    {
        // Filter per bulan
        $currentData = $data->filter(
            fn($i) =>
            Carbon::parse($i->tgl_pengukuran)->format('Y-m') === $currentMonth
        );


        $previousData = $data->filter(
            fn($i) =>
            Carbon::parse($i->tgl_pengukuran)->format('Y-m') === $previousMonth
        );


        // Hitung kategori
        $countCategories = function ($collection) use ($field, $categories) {
            $result = [];
            foreach ($categories as $cat => $values) {
                $result[$cat] = $collection->filter(function ($i) use ($field, $values) {
                    return in_array($i->$field, $values);
                })->count();
            }
            return $result;
        };

        $currentCounts = $countCategories($currentData);
        $previousCounts = $countCategories($previousData);

        // Hitung total
        $totalCurrent = array_sum($currentCounts);
        $totalPrevious = array_sum($previousCounts);

        $diffPercent = $totalPrevious == 0
            ? 0
            : round((($totalCurrent - $totalPrevious) / $totalPrevious) * 100, 2);

        return [
            "month" => [
                "current" => $currentMonth,
                "previous" => $previousMonth,
            ],
            "data" => [
                "current" => $currentCounts,
                "previous" => $previousCounts,
            ],
            "total" => [
                "current" => $totalCurrent,
                "previous" => $totalPrevious,
                "diff_percent" => $diffPercent
            ]
        ];
    }

    public function case(Request $request)
    {
        $query = Kunjungan::query();
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

        // 5. Filter periode
        if ($request->filled('periode')) {
            $tanggal = Carbon::createFromFormat('Y-m', $request->periode);
            $query->whereYear('tgl_pengukuran', $tanggal->year)
                ->whereMonth('tgl_pengukuran', $tanggal->month);
        } else {
            $query->whereYear('tgl_pengukuran', now()->subMonth()->year)
                ->whereMonth('tgl_pengukuran', now()->subMonth()->month);
        }

        //dd($query->toSQL(), $request->periode);
        // 6. Ambil seluruh data Kunjungan yg relevan
        $data = $query->get();

        // 7. Hitung daftar anak case
        $nik_case = $data->filter(function ($item) {
            return $item->bb_u !== 'Normal'
                || $item->tb_u !== 'Normal'
                || $item->bb_tb !== 'Normal';
        })->pluck('nik')->unique();

        // 8. Ambil intervensi untuk nik_case
        $intervensi = Intervensi::whereIn('nik_subjek', $nik_case)->get();

        $nik_intervensi = $intervensi->pluck('nik_subjek')->unique();

        // 9. Hitung sudah & belum intervensi
        $sudah_intervensi = $nik_case->intersect($nik_intervensi)->count();
        $belum_intervensi = $nik_case->diff($nik_intervensi)->count();

        // Grouping lama
        $bb_u_tb_u = $data->filter(fn($item) => $item->bb_u !== 'Normal' && $item->tb_u !== 'Normal')->count();
        $tb_u_bb_tb = $data->filter(fn($item) => $item->tb_u !== 'Normal' && $item->bb_tb !== 'Normal')->count();
        $bb_u_bb_tb = $data->filter(fn($item) => $item->bb_u !== 'Normal' && $item->bb_tb !== 'Normal')->count();

        return response()->json([
            'status' => 'success',
            'message' => 'Data anak berhasil dimuat',

            'grouping' => [
                'bb_u_tb_u' => $bb_u_tb_u,
                'tb_u_bb_tb' => $tb_u_bb_tb,
                'bb_u_bb_tb' => $bb_u_bb_tb,
            ],

            'totalCase' => $nik_case->count(),
            'sudahIntervensi' => $sudah_intervensi,
            'belumIntervensi' => $belum_intervensi,
        ]);
    }

    public function intervensi(Request $request)
    {
        // 2️⃣ Ambil posyandu & wilayah
        $posyandu = $request->posyandu;

        $filterKelurahan = $request->kelurahan ?? null;

        // ==========================
        // Tentukan periode
        // ==========================
        if ($request->filled('periode')) {
            $tanggal = Carbon::createFromFormat('Y-m', $request->periode);
        } else {
            $tanggal = now()->subMonth(); // default bulan berjalan -1
        }
        $startDate = $tanggal->copy()->startOfMonth();
        $endDate = $tanggal->copy()->endOfMonth();


        // ==========================
        // A. Query KUNJUNGAN
        // ==========================
        $kunjungan = $this->getData(
            $request->provinsi,
            $request->kota,
            $request->kecamatan,
            $filterKelurahan,
            $startDate->format("Y-m-d"),
            $endDate->format('Y-m-d'),
            $request->posyandu,
            $request->rt,
            $request->rw,
            null,
            null,
            null,
            null,
            null,
        );

        // 🔥 Filter anak dengan **masalah gizi ganda**
        $nik_case = $kunjungan->filter(function ($item) {
            $gizi_ganda = 0;
            if ($item->bb_u != null && str_contains(strtolower($item->bb_u), 'underweight')){
                $gizi_ganda++;
            }
            if ($item->tb_u != null && str_contains(strtolower($item->bb_u), 'stunted')){
                $gizi_ganda++;
            }
            if ($item->bb_tb != null && $item->bb_tb !== 'Normal'){
                $gizi_ganda++;
            }
            return $gizi_ganda >= 2; // minimal 2 parameter bermasalah → gizi ganda
        })->pluck('nik')->unique();

        // ==========================
        // B. Query INTERVENSI
        // ==========================
        $qIntervensi = Intervensi::query();

        if ($filterKelurahan) {
            $qIntervensi->where('desa', $filterKelurahan);
        }
        if ($request->filled('posyandu')) {
            $qIntervensi->where('posyandu', $request->posyandu);
        }
        if ($request->filled('rw')) {
            $qIntervensi->where('rw', $request->rw);
        }
        if ($request->filled('rt')) {
            $qIntervensi->where('rt', $request->rt);
        }

        $qIntervensi->whereYear('tgl_intervensi', $tanggal->year)
            ->whereMonth('tgl_intervensi', $tanggal->month);

        $qIntervensi->whereIn("nik_subjek", $nik_case)->where("status_subjek", "anak");

        $intervensi = $qIntervensi->get();

        // ==========================
        // C. GROUPING NIK
        // ==========================
        $nikGanda = $nik_case;
        $nikIntervensi = $intervensi->pluck('nik_subjek')->unique();

        $punya_keduanya = $nikIntervensi->intersect($nikGanda);
        $hanya_intervensi = $nikIntervensi->diff($nikGanda);
        $hanya_kunjungan = $nikGanda->diff($nikIntervensi);

        // ==========================
        // D. Build DETAIL
        // ==========================
        $mapKunjungan = $kunjungan->keyBy('nik');
        $mapIntervensi = $intervensi->groupBy('nik_subjek');

        $detail_keduanya = $punya_keduanya->map(function ($nik) use ($mapKunjungan, $mapIntervensi) {
            return [
                'nik' => $nik,
                'nama' => $mapKunjungan[$nik]->nama_anak ?? null,
                'kelurahan' => $mapKunjungan[$nik]->kelurahan ?? null,
                'posyandu' => $mapKunjungan[$nik]->posyandu ?? null,
                'data_kunjungan' => $mapKunjungan[$nik] ?? null,
                'data_intervensi' => $mapIntervensi[$nik] ?? [],
            ];
        });

        $detail_hanya_kunjungan = $hanya_kunjungan->map(function ($nik) use ($mapKunjungan) {
            $row = $mapKunjungan[$nik];
            return [
                'nik' => $nik,
                'nama' => $row->nama_anak ?? null,
                'kelurahan' => $row->kelurahan ?? null,
                'posyandu' => $row->posyandu ?? null,
                'data_kunjungan' => $row,
            ];
        });

        $detail_hanya_intervensi = $hanya_intervensi->map(function ($nik) use ($mapIntervensi) {
            $first = $mapIntervensi[$nik]->first();
            return [
                'nik' => $nik,
                'nama' => $first->nama_anak ?? null,
                'kelurahan' => $first->kelurahan ?? null,
                'posyandu' => $first->posyandu ?? null,
                'data_intervensi' => $mapIntervensi[$nik] ?? [],
            ];
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Data intervensi & kunjungan gizi ganda berhasil dimuat',
            'grouping' => [
                'total_case' => $nikGanda->count(),
                'punya_keduanya' => $punya_keduanya->count(),
                'hanya_kunjungan' => $hanya_kunjungan->count(),
                'hanya_intervensi' => $hanya_intervensi->count(),
            ],
            'detail' => [
                'punya_keduanya' => $detail_keduanya->values(),
                'hanya_kunjungan' => $detail_hanya_kunjungan->values(),
                'hanya_intervensi' => $detail_hanya_intervensi->values(),
            ]
        ]);
    }

    public function ringkasan(Request $request)
    {
        $query = Kunjungan::query();


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

        // 4️⃣ Filter periode
        if ($request->filled('periode')) {
            $tanggal = Carbon::createFromFormat('Y-m', $request->periode);
            $query->whereYear('tgl_pengukuran', $tanggal->year)
                ->whereMonth('tgl_pengukuran', $tanggal->month);
        } else {
            $tanggal = now()->subMonth();
            $query->whereYear('tgl_pengukuran', $tanggal->year)
                ->whereMonth('tgl_pengukuran', $tanggal->month);
        }

        // 5️⃣ Ambil data kunjungan bulan tersebut
        $data = $query->get();

        // 6️⃣ Ambil NIK yang masuk "gizi ganda" dan nama tidak null
        $nik_case = $data->filter(function ($item) {
            return ($item->bb_u !== 'Normal' || $item->tb_u !== 'Normal' || $item->bb_tb !== 'Normal');
        })->pluck('nik')->unique();

        // 7️⃣ Ambil data intervensi untuk NIK tersebut
        $intervensi = Intervensi::whereIn('nik_subjek', $nik_case)
            ->whereNotNull('nama_subjek')
            ->get();

        // -------------------------
        // 8️⃣ Hitung summary
        // -------------------------
        $totalGanda = $nik_case->count();
        $sudahIntervensiCount = $intervensi->pluck('nik_subjek')->unique()->count();
        $belumIntervensiCount = $totalGanda - $sudahIntervensiCount;

        // -------------------------
        // 9️⃣ Top 5 posyandu dengan gizi ganda terbanyak
        // -------------------------
        $topPosyandu = $data
            ->filter(
                fn($i) =>
                ($i->bb_u !== 'Normal' || $i->tb_u !== 'Normal' || $i->bb_tb !== 'Normal')
                && !is_null($i->nama) && trim($i->nama) !== ''
            )
            ->groupBy('posyandu')
            ->map(fn($v) => $v->count())
            ->sortDesc()
            ->take(5);

        // -------------------------
        // 🔟 Kasus 3 bulan terakhir yang statusnya tidak berubah
        // -------------------------
        $tgl3bulan = now()->subMonths(3)->startOfMonth();

        $riwayat = Kunjungan::whereIn('nik', $nik_case)
            ->where('tgl_pengukuran', '>=', $tgl3bulan)
            ->orderBy('nik')
            ->orderBy('tgl_pengukuran')
            ->get()
            ->groupBy('nik')
            ->map(function ($rows) {
                $first = $rows->first();
                $last = $rows->last();

                $statusAwal = [
                    $first->bb_u,
                    $first->tb_u,
                    $first->bb_tb
                ];

                $statusAkhir = [
                    $last->bb_u,
                    $last->tb_u,
                    $last->bb_tb
                ];

                $tidakBerubah = ($statusAwal == $statusAkhir);

                return [
                    'nik' => $first->nik,
                    'nama' => $first->nama,
                    'status_awal' => $statusAwal,
                    'status_akhir' => $statusAkhir,
                    'tidak_berubah' => $tidakBerubah
                ];
            })
            ->filter(fn($i) => $i['tidak_berubah'])
            ->values();

        // -------------------------
        // 1️⃣1️⃣ Detail anak + intervensinya
        // -------------------------
        $detail = $data
            ->filter(
                fn($i) =>
                ($i->bb_u !== 'Normal' || $i->tb_u !== 'Normal' || $i->bb_tb !== 'Normal')
                && !is_null($i->nama) && trim($i->nama) !== ''
            )
            ->map(function ($item) use ($intervensi) {
                return [
                    'nik' => $item->nik,
                    'nama' => $item->nama,
                    'umur_bulan' => $item->umur_bulan,
                    'posyandu' => $item->posyandu,
                    'bb_u' => $item->bb_u,
                    'tb_u' => $item->tb_u,
                    'bb_tb' => $item->bb_tb,
                    'intervensi' => $intervensi->where('nik_subjek', $item->nik)->values()
                ];
            })
            ->values();

        // -------------------------
        // 1️⃣2️⃣ List anak sudah / belum intervensi
        // -------------------------
        $sudahIntervensi = $detail->filter(fn($i) => $i['intervensi']->isNotEmpty())->values();
        $belumIntervensi = $detail->filter(fn($i) => $i['intervensi']->isEmpty())->values();

        return response()->json([
            'summary' => [
                'total_gizi_ganda' => $totalGanda,
                'sudah_intervensi' => $sudahIntervensiCount,
                'belum_intervensi' => $belumIntervensiCount,
            ],
            'top_posyandu' => $topPosyandu,
            'tidak_berubah_3_bulan' => $riwayat,
            'detail_anak' => $detail,
            'anak_sudah_intervensi' => $sudahIntervensi,
            'anak_belum_intervensi' => $belumIntervensi,
        ]);
    }

    private function getData(
        ?string $provinsi,
        ?string $kota,
        ?string $kecamatan,
        ?string $kelurahan,
        ?string $periodeAwal,
        ?string $periodeAkhir,
        ?string $posyandu,
        ?string $rt,
        ?string $rw,
        ?array $bbu,
        ?array $tbu,
        ?array $bbtb,
        ?array $stagnan,      // 1, 2, '>2'
        ?array $intervensi
    ) {
        // ---------------------------------------------------------
        // 1. SUBQUERY: ambil tanggal pengukuran terakhir (per anak)
        // ---------------------------------------------------------
        $sub = Kunjungan::selectRaw('trim(nik) as nik, MAX(tgl_pengukuran), MAX(id) as id')
            ->when($provinsi, fn($q) => $q->where('provinsi', strtoupper($provinsi)))
            ->when($kota, fn($q) => $q->where('kota', strtoupper($kota)))
            ->when($kecamatan, fn($q) => $q->where('kecamatan', strtoupper($kecamatan)))
            ->when($kelurahan, fn($q) => $q->where('kelurahan', strtoupper($kelurahan)))
            ->when($periodeAwal, fn($q) =>
                $q->whereDate('tgl_pengukuran', '>=', $periodeAwal))
            ->when($periodeAkhir, fn($q) =>
                $q->whereDate('tgl_pengukuran', '<=', $periodeAkhir))
            ->groupBy(DB::raw("trim(nik)"));

        // ---------------------------------------------------------
        // 2. MAIN QUERY: ambil row terakhir berdasarkan subquery
        // ---------------------------------------------------------
        $latest = Kunjungan::from('kunjungan_anak as ka')
            ->joinSub($sub, 'latest', function ($join) {
                $join->on('ka.id', '=', 'latest.id');
            })
            ->select('ka.*')
            ->when($kelurahan, fn($q) => $q->where('ka.kelurahan', strtoupper($kelurahan)))
            ->when($posyandu, fn($q) => $q->where('ka.posyandu', $posyandu))
            ->when($rt, fn($q) => $q->where('ka.rt', $rt))
            ->when($rw, fn($q) => $q->where('ka.rw', $rw))
            ->when($bbu, fn($q) => $q->whereIn('ka.bb_u', $bbu))
            ->when($tbu, fn($q) => $q->whereIn('ka.tb_u', $tbu))
            ->when($bbtb, fn($q) => $q->whereIn('ka.bb_tb', $bbtb))
            ->get();

        // ---------------------------------------------------------
        // 3. Hitung stagnan per anak kalau filter stagnan dipakai
        // ---------------------------------------------------------
        if ($stagnan) {
            $latest = $this->getStagnanData($latest, $periodeAwal, $periodeAkhir, $stagnan);
        }

        if ($intervensi && count($intervensi) > 0) {
            $latest = $this->getIntervensiData(
                $latest,
                $periodeAwal,
                $periodeAkhir,
                $intervensi
            );
        }


        return $latest;
    }

    public function testGetData(Request $request)
    {
        $filters = $request->only([
            'kelurahan',
            'periodeAwal',
            'periodeAkhir',
            'posyandu',
            'rw',
            'rt',
            'bbu',
            'tbu',
            'bbtb',
            'stagnan',
            'intervensi'
        ]);


        // Normalisasi format periode
        if (!empty($filters['periodeAwal'])) {
            try {
                $periodeAwal = explode(' ', $filters['periodeAwal']);
                if (count($periodeAwal) === 1) {
                    $periodeAwal = explode('+', $filters['periodeAwal']);
                }
                $bulanIndex = array_search($periodeAwal[0], self::bulan);
                $filters['periodeAwal'] = Carbon::createFromFormat('Y-m', $periodeAwal[1] . '-' . $bulanIndex)
                    ->startOfMonth()->format('Y-m-d');
            } catch (\Exception $e) {
                $filters['periodeAwal'] = null;
            }
        }

        if (!empty($filters['periodeAkhir'])) {
            try {
                $periodeAkhir = explode(' ', $filters['periodeAkhir']);
                if (count($periodeAkhir) === 1) {
                    $periodeAkhir = explode('+', $filters['periodeAkhir']);
                }
                $bulanIndex = array_search($periodeAkhir[0], self::bulan);
                $filters['periodeAkhir'] = Carbon::createFromFormat('Y-m', $periodeAkhir[1] . '-' . $bulanIndex)
                    ->endOfMonth()->format('Y-m-d');
            } catch (\Exception $e) {
                $filters['periodeAkhir'] = null;
            }
        }


        $latest = $this->getData(
            $filters["kelurahan"] ?? null,
            $filters["periodeAwal"] ?? null,
            $filters["periodeAkhir"] ?? null,
            $filters["posyandu"] ?? null,
            $filters["rt"] ?? null,     // benar (rt dulu)
            $filters["rw"] ?? null,     // rw
            $filters["bbu"] ?? null,
            $filters["tbu"] ?? null,
            $filters["bbtb"] ?? null,
            $filters["stagnan"] ?? null,
            $filters["intervensi"] ?? null,
        );
        return response()->json([
            "count" => $latest->count(),
            "data" => $latest,
        ]);
    }

    private function getStagnanData($latest, $periodeAwal, $periodeAkhir, $stagnanFilters)
    {
        if (!$stagnanFilters || count($stagnanFilters) === 0) {
            return $latest;
        }

        $nikList = $latest->pluck('nik');

        $all = Kunjungan::whereIn('nik', $nikList)
            ->when($periodeAkhir, fn($q) => $q->whereDate('tgl_pengukuran', '<=', $periodeAkhir))
            ->orderBy('tgl_pengukuran', 'desc')
            ->get()
            ->groupBy('nik');
        //dd($all);

        // Hitung stagnan berdasarkan bulan
        $latest = $latest->map(function ($row) use ($all) {

            $history = $all[$row->nik] ?? collect();
            $row->stagnan_months = $this->calculateStagnanByMonth($history);
            return $row;
        });

        // FILTER MULTI-VALUE
        return $latest->filter(function ($row) use ($stagnanFilters) {

            foreach ($stagnanFilters as $filter) {

                if ($filter == "1 T" && $row->stagnan_months == 1)
                    return true;
                if ($filter == "2 T" && $row->stagnan_months == 2)
                    return true;
                if (trim($filter) == "> 2 T" && $row->stagnan_months >= 3)
                    return true;
            }

            return false;
        })->values();
    }

    private function calculateStagnanByMonth($history)
    {
        $history = $history->sortByDesc('tgl_pengukuran')->values();

        $stagnanMonths = 0;


        for ($i = 0; $i < $history->count() - 1; $i++) {

            $current = $history[$i];
            $next    = $history[$i + 1];

            // STOP condition
            if (is_null($current->naik_berat_badan)) break;
            if ((int)$current->naik_berat_badan === 1) break;

            $currentDate = \Carbon\Carbon::parse($current->tgl_pengukuran);
            $nextDate    = \Carbon\Carbon::parse($next->tgl_pengukuran);

            $monthDiff = $currentDate->diffInMonths($nextDate);

            $stagnanMonths += max($monthDiff, 1);
        }

        return $stagnanMonths;
    }


    private function getIntervensiData($latest, $periodeAwal, $periodeAkhir, $intervensiFilters)
    {
        // Jika tidak ada filter intervensi → lewati saja
        if (!$intervensiFilters || count($intervensiFilters) === 0) {
            return $latest;
        }

        // Ambil list NIK
        $nikList = $latest->pluck('nik')->unique()->values();

        // Ambil semua intervensi anak dalam periode
        $history = Intervensi::query()
            ->whereIn('nik_subjek', $nikList)
            ->where('status_subjek', 'anak')
            ->when($periodeAwal, fn($q) => $q->whereDate('tgl_intervensi', '>=', $periodeAwal))
            ->when($periodeAkhir, fn($q) => $q->whereDate('tgl_intervensi', '<=', $periodeAkhir))
            // ->when($intervensiFilters, fn($q) => $q->whereIn(DB::raw('LOWER(kategori)'), $intervensiLower))
            ->orderBy('tgl_intervensi', 'desc')
            ->get()->groupBy("nik_subjek");

        // Tentukan intervensi terakhir
        $latest = $latest->map(function ($row) use ($history) {

            $problem = 0;
            if ($row->bb_u != null && $row->bb_u !== 'Normal')
                $problem++;
            if ($row->tb_u != null && $row->tb_u !== 'Normal')
                $problem++;
            if ($row->bb_tb != null && $row->bb_tb !== 'Normal')
                $problem++;

            $h = $history[$row->nik] ?? collect();
            // Default: tidak ada intervensi
            $row->intervensi_last_kategori = null;

            if ($h->count() > 0) {
                // Ambil intervensi terbaru
                $last = $h->first();
                $row->intervensi_last_kategori = $last->kategori;
            }

            if ($problem > 0 && $h->count() == 0) {
                $row->intervensi_last_kategori = "belum mendapatkan intervensi";
            }

            return $row;
        });

        $intervensiLower = array_map('strtolower', $intervensiFilters);

        // Filter sesuai kategori pilihan user
        return $latest->filter(function ($row) use ($intervensiLower) {
            return in_array(strtolower($row->intervensi_last_kategori), $intervensiLower);
        })->values();
    }

    public function getDataDoubleProblem(Request $request)
    {
        // ==========================
        // Tentukan periode
        // ==========================
        if ($request->filled('periode')) {
            $tanggal = Carbon::createFromFormat('Y-m-d', $request->periode . "-01")->startOfMonth();
        } else {
            $tanggal = now()->subMonth()->startOfMonth(); // default bulan berjalan -1
        }

        // ==========================
        // A. Query KUNJUNGAN
        // ==========================
        $qKunjungan = Kunjungan::query();

        if ($request->filled('provinsi'))
            $qKunjungan->where('provinsi', $request->provinsi);
        if ($request->filled('kota'))
            $qKunjungan->where('kota', $request->kota);
        if ($request->filled('kecamatan'))
            $qKunjungan->where('kecamatan', $request->kecamatan);
        if ($request->filled('kelurahan'))
            $qKunjungan->where('kelurahan', $request->kelurahan);
        if ($request->filled('posyandu'))
            $qKunjungan->where('posyandu', $request->posyandu);
        if ($request->filled('rw'))
            $qKunjungan->where('rw', $request->rw);
        if ($request->filled('rt'))
            $qKunjungan->where('rt', $request->rt);

        $startDate = $tanggal->copy()->subMonths(2)->startOfMonth();
        $endDate = $tanggal->copy()->endOfMonth();

        $qKunjungan->whereBetween('tgl_pengukuran', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);

        $kunjungan = $qKunjungan->get();

        // Filter anak dengan **masalah gizi ganda**
        $isDoubleProblem = function ($item) {
            $gizi_ganda = 0;
            if ($item->bb_u !== null && $item->bb_u !== 'Normal')
                $gizi_ganda++;
            if ($item->tb_u !== null && $item->tb_u !== 'Normal')
                $gizi_ganda++;
            if ($item->bb_tb !== null && $item->bb_tb !== 'Normal')
                $gizi_ganda++;
            return $gizi_ganda >= 2;
        };

        // ==========================
        // B. Ambil NIK anak yang PERNAH double problem
        //    dalam range periode ini
        // ==========================
        $nik_case = $kunjungan
            ->filter($isDoubleProblem)
            ->pluck('nik')
            ->unique()
            ->values();

        // Kalau tidak ada kasus sama sekali
        if ($nik_case->isEmpty()) {
            return response()->json([
                "count" => [
                    "label" => [],
                    "count" => [],
                ],
                "posyandu" => [
                    "name" => [],
                    "count" => [],
                ],
            ], 200);
        }

        // Batasi kunjungan hanya untuk NIK yang pernah double problem
        $kunjungan = $kunjungan->whereIn('nik', $nik_case);

        // ==========================
        // C. Siapkan label per bulan
        // ==========================
        $labels = [];
        $monthMap = [];

        $monthNames = [
            'Jan',
            'Feb',
            'Mar',
            'Apr',
            'Mei',
            'Jun',
            'Jul',
            'Agu',
            'Sep',
            'Okt',
            'Nov',
            'Des'
        ];

        $cursor = $startDate->copy()->startOfMonth();
        $lastMonthKey = null;

        while ($cursor->lte($endDate)) {

            $key = $cursor->format('Y-m');

            // buat label: Jan 2025, Feb 2025, dst
            $monthIndex = (int) $cursor->format('n') - 1; // 0–11
            $labels[] = $monthNames[$monthIndex];

            // simpan data bulan ini
            $monthMap[$key] = $kunjungan->filter(function ($row) use ($cursor) {
                $tgl = Carbon::parse($row->tgl_pengukuran);
                return $tgl->isSameMonth($cursor);
            });

            $lastMonthKey = $key;
            $cursor->addMonth();
        }

        // ==========================
        // D. Untuk tiap bulan:
        //    - ambil kunjungan terakhir per NIK di bulan tsb
        //    - cek apakah masih double problem
        //    - bentuk set NIK per bulan yang double problem
        // ==========================
        $nikPerMonth = []; // key: 'Y-m' → Collection nik

        foreach ($monthMap as $key => $rows) {
            // Latest kunjungan per NIK di bulan tsb
            $latestPerNik = $rows
                ->sortByDesc('tgl_pengukuran')
                ->groupBy('nik')
                ->map->first();

            // Simpan hanya yang double problem di bulan ini
            $nikPerMonth[$key] = $latestPerNik
                ->filter($isDoubleProblem)
                ->pluck('nik')
                ->values();
        }

        // ==========================
        // E. Intersect dari bulan pertama s/d bulan berjalan
        //    untuk mendapatkan anak yang TIDAK MEMBAIK
        //    (tetap double problem berturut-turut)
        // ==========================
        $counts = [];
        $runningIntersection = null;

        foreach (array_keys($monthMap) as $idx => $key) {
            $currentNik = $nikPerMonth[$key] ?? collect();

            if ($idx === 0) {
                // Bulan pertama: base set = semua NIK yang double problem di bulan pertama
                $runningIntersection = $currentNik->unique();
            } else {
                // Bulan berikutnya: intersect dengan bulan ini
                $runningIntersection = $runningIntersection
                    ->intersect($currentNik)
                    ->values();
            }

            // Jumlah anak yang konsisten double problem dari bulan pertama s/d bulan ini
            $counts[] = $runningIntersection->count();
        }

        // ==========================
        // F. Group by POSYANDU di bulan terakhir
        //    hanya untuk anak yang TIDAK MEMBAIK
        //    (intersection final)
        // ==========================
        $persistNik = $runningIntersection ?? collect(); // NIK yang tetap double problem sampai bulan terakhir

        $dataLastMonth = collect();
        if ($lastMonthKey) {
            $rowsLast = $monthMap[$lastMonthKey] ?? collect();

            // latest per nik di bulan terakhir
            $dataLastMonth = $rowsLast
                ->whereIn('nik', $persistNik)
                ->sortByDesc('tgl_pengukuran')
                ->groupBy('nik')
                ->map->first();
        }

        $groupPosyandu = $dataLastMonth
            ->groupBy('posyandu')
            ->map->count()
            ->sortDesc()->take(5);

        $posyanduNames = $groupPosyandu->keys()->values();
        $posyanduCounts = $groupPosyandu->values()->values();

        // ==========================
        // G. Response
        // ==========================
        return response()->json([
            "count" => [
                "label" => $labels,      // label bulan (Agustus 2025, dst)
                "count" => $counts,      // jumlah anak yang tetap double problem dari awal sampai bulan itu
            ],
            "posyandu" => [
                "name" => $posyanduNames,
                "count" => $posyanduCounts,
            ],
        ], 200);
    }

    /**
     * Helper: ambil count per bulan sesuai kategori.
     */
    private function getCountPerMonth(
        $field,
        $category,
        $jk,
        $startDate,
        $endDate,
        $months,
        $filters
    ) {
        $query = Kunjungan::query()
            ->selectRaw("DATE_FORMAT(tgl_pengukuran, '%Y-%m') as bulan, nik")
            ->whereBetween('tgl_pengukuran', [$startDate, $endDate])
            ->where($field, $category);

        // ==========================
        // 🔑 FILTER WILAYAH
        // ==========================
        if (!empty($filters['provinsi'])) {
            $query->where('provinsi', $filters['provinsi']);
        }

        if (!empty($filters['kota'])) {
            $query->where('kota', $filters['kota']);
        }

        if (!empty($filters['kecamatan'])) {
            $query->where('kecamatan', $filters['kecamatan']);
        }

        if (!empty($filters['kelurahan'])) {
            $query->where('kelurahan', $filters['kelurahan']);
        }

        if (!empty($filters['posyandu'])) {
            $query->where('posyandu', $filters['posyandu']);
        }

        if (!empty($filters['rt'])) {
            $query->where('rt', $filters['rt']);
        }

        if (!empty($filters['rw'])) {
            $query->where('rw', $filters['rw']);
        }

        if ($jk) {
            $query->where('jk', $jk);
        }

        // ==========================
        // DISTINCT NIK PER BULAN
        // ==========================
        $data = $query
            ->groupByRaw("DATE_FORMAT(tgl_pengukuran, '%Y-%m'), nik")
            ->get()
            ->groupBy('bulan');

        $result = $months;

        foreach ($data as $bulan => $rows) {
            $result[$bulan] = $rows->count();
        }

        return array_values($result);
    }

    private function getCountPerMonthByAge(
        $field,
        $category,
        array $ageRange,
        $startDate,
        $endDate,
        $months,
        $filters
    ) {
        [$minAge, $maxAge] = $ageRange;

        $query = Kunjungan::query()
            ->selectRaw("DATE_FORMAT(tgl_pengukuran, '%Y-%m') as bulan, nik")
            ->whereBetween('tgl_pengukuran', [$startDate, $endDate])
            ->whereBetween('usia_saat_ukur', [$minAge, $maxAge])
            ->where($field, $category);

        // ==========================
        // 🔑 FILTER WILAYAH
        // ==========================
        if (!empty($filters['provinsi'])) {
            $query->where('provinsi', $filters['provinsi']);
        }
        if (!empty($filters['kota'])) {
            $query->where('kota', $filters['kota']);
        }
        if (!empty($filters['kecamatan'])) {
            $query->where('kecamatan', $filters['kecamatan']);
        }
        if (!empty($filters['kelurahan'])) {
            $query->where('kelurahan', $filters['kelurahan']);
        }
        if (!empty($filters['posyandu'])) {
            $query->where('posyandu', $filters['posyandu']);
        }
        if (!empty($filters['rt'])) {
            $query->where('rt', $filters['rt']);
        }
        if (!empty($filters['rw'])) {
            $query->where('rw', $filters['rw']);
        }

        $data = $query
            ->groupByRaw("DATE_FORMAT(tgl_pengukuran, '%Y-%m'), nik")
            ->get()
            ->groupBy('bulan');

        $result = $months;

        foreach ($data as $bulan => $rows) {
            $result[$bulan] = $rows->count();
        }

        return array_values($result);
    }

    private function getTidakNaikPerMonthByAge(
        array $ageRange,
        $startDate,
        $endDate,
        $months,
        $filters
    ) {
        [$minAge, $maxAge] = $ageRange;

        $query = Kunjungan::query()
            ->selectRaw("DATE_FORMAT(tgl_pengukuran, '%Y-%m') as bulan, nik")
            ->whereBetween('tgl_pengukuran', [$startDate, $endDate])
            ->whereBetween('usia_saat_ukur', [$minAge, $maxAge])
            ->where('naik_berat_badan', 1);

        // ==========================
        // 🔑 FILTER WILAYAH
        // ==========================
        if (!empty($filters['provinsi'])) {
            $query->where('provinsi', $filters['provinsi']);
        }
        if (!empty($filters['kota'])) {
            $query->where('kota', $filters['kota']);
        }
        if (!empty($filters['kecamatan'])) {
            $query->where('kecamatan', $filters['kecamatan']);
        }
        if (!empty($filters['kelurahan'])) {
            $query->where('kelurahan', $filters['kelurahan']);
        }
        if (!empty($filters['posyandu'])) {
            $query->where('posyandu', $filters['posyandu']);
        }
        if (!empty($filters['rt'])) {
            $query->where('rt', $filters['rt']);
        }
        if (!empty($filters['rw'])) {
            $query->where('rw', $filters['rw']);
        }

        $data = $query
            ->groupByRaw("DATE_FORMAT(tgl_pengukuran, '%Y-%m'), nik")
            ->get()
            ->groupBy('bulan');

        $result = $months;

        foreach ($data as $bulan => $rows) {
            $result[$bulan] = $rows->count();
        }

        return array_values($result);
    }

    private function getCountPerMonthIndikator(
        $field,
        $category,
        $startDate,
        $endDate,
        $months,
        $filters
    ) {
        $query = Kunjungan::query()
            ->selectRaw("DATE_FORMAT(tgl_pengukuran, '%Y-%m') as bulan, nik")
            ->whereBetween('tgl_pengukuran', [$startDate, $endDate])
            ->where($field, $category);

        // ==========================
        // 🔑 FILTER WILAYAH
        // ==========================
        if (!empty($filters['provinsi'])) {
            $query->where('provinsi', $filters['provinsi']);
        }
        if (!empty($filters['kota'])) {
            $query->where('kota', $filters['kota']);
        }
        if (!empty($filters['kecamatan'])) {
            $query->where('kecamatan', $filters['kecamatan']);
        }
        if (!empty($filters['kelurahan'])) {
            $query->where('kelurahan', $filters['kelurahan']);
        }
        if (!empty($filters['posyandu'])) {
            $query->where('posyandu', $filters['posyandu']);
        }
        if (!empty($filters['rt'])) {
            $query->where('rt', $filters['rt']);
        }
        if (!empty($filters['rw'])) {
            $query->where('rw', $filters['rw']);
        }

        $data = $query
            ->groupByRaw("DATE_FORMAT(tgl_pengukuran, '%Y-%m'), nik")
            ->get()
            ->groupBy('bulan');

        $result = $months;

        foreach ($data as $bulan => $rows) {
            $result[$bulan] = $rows->count();
        }

        return array_values($result);
    }

    private function getTidakNaikPerMonthIndikator(
        $startDate,
        $endDate,
        $months,
        $filters
    ) {
        $query = Kunjungan::query()
            ->selectRaw("DATE_FORMAT(tgl_pengukuran, '%Y-%m') as bulan, nik")
            ->whereBetween('tgl_pengukuran', [$startDate, $endDate])
            ->where('naik_berat_badan', 1);

        // ==========================
        // 🔑 FILTER WILAYAH
        // ==========================
        if (!empty($filters['provinsi'])) {
            $query->where('provinsi', $filters['provinsi']);
        }
        if (!empty($filters['kota'])) {
            $query->where('kota', $filters['kota']);
        }
        if (!empty($filters['kecamatan'])) {
            $query->where('kecamatan', $filters['kecamatan']);
        }
        if (!empty($filters['kelurahan'])) {
            $query->where('kelurahan', $filters['kelurahan']);
        }
        if (!empty($filters['posyandu'])) {
            $query->where('posyandu', $filters['posyandu']);
        }
        if (!empty($filters['rt'])) {
            $query->where('rt', $filters['rt']);
        }
        if (!empty($filters['rw'])) {
            $query->where('rw', $filters['rw']);
        }

        $data = $query
            ->groupByRaw("DATE_FORMAT(tgl_pengukuran, '%Y-%m'), nik")
            ->get()
            ->groupBy('bulan');

        $result = $months;

        foreach ($data as $bulan => $rows) {
            $result[$bulan] = $rows->count();
        }

        return array_values($result);
    }

    public function show($nik)
    {
        try {

            // === 1. Data dasar anak ===
            $anak = Kunjungan::where('nik', $nik)->first();

            if (!$anak) {
                return response()->json([
                    'message' => 'Data anak tidak ditemukan',
                ], 404);
            }

            // === 2. Riwayat Posyandu ===
            $posyandu = Kunjungan::where('nik', $nik)
                ->orderBy('tgl_pengukuran', 'ASC')
                ->get()
                ->map(function ($item) {
                    return [
                        'tgl_ukur' => $item->tgl_pengukuran ?? null,
                        'jk' => $item->jk,
                        'bb' => $item->bb,
                        'tb' => $item->tb,
                        'bbu' => $item->bb_u,
                        'tbu' => $item->tb_u,
                        'bbtb' => $item->bb_tb,
                        'zs_bbu' => $item->zs_bb_u,
                        'zs_tbu' => $item->zs_tb_u,
                        'zs_bbtb' => $item->zs_bb_tb,
                        'usia' => $item->usia_saat_ukur,
                    ];
                });

            // === 3. Riwayat Intervensi ===
            $intervensi = Intervensi::where('nik_subjek', $nik)
                ->orderBy('tgl_intervensi', 'ASC')
                ->get()
                ->map(function ($row) {
                    return [
                        'tgl_intervensi' => $row->tgl_intervensi,
                        'kader' => $row->petugas ?? '-',
                        'jenis' => $row->kategori ?? '-',
                    ];
                });

            // === 4. Riwayat Pendampingan ===
            $pendampingan = Child::where('nik_anak', $nik)
                ->orderBy('tgl_pendampingan', 'ASC')
                ->get()
                ->map(function ($row) {
                    return [
                        'tanggal' => $row->tgl_pendampingan,
                        'kader' => $row->petugas,
                        'usia' => $row->usia,
                        'bb_lahir' => $row->bb_lahir,
                        'tb_lahir' => $row->tb_lahir,
                        'uk_bb' => $row->bb,
                        'uk_tb' => $row->tb,
                        'bb' => $row->bb_u,
                        'tb' => $row->tb_u,
                        'bbu' => $row->zs_bb_u,
                        'tbu' => $row->zs_tb_u,
                        'bbtb' => $row->zs_bb_tb,
                    ];
                });

            // === 5. Data Kelahiran ===
            $kelahiran =
                [
                    'nik' => $anak->nik,
                    'tgl_lahir' => $anak->tgl_lahir,
                    'bb_lahir' => $anak->bb_lahir,
                    'pb_lahir' => $anak->tb_lahir,
                    //'persalinan'     => $k->jenis_persalinan,
                ];

            // === 6. Data Keluarga (ayah/ibu) ===
            $keluarga = Child::where('nik_anak', $nik)->first();

            $keluargaData = $keluarga ? [
                [
                    'nama_ayah' => $keluarga->nama_ayah,
                    'nik_ayah' => $keluarga->nik_ayah,
                    'pekerjaan_ayah' => $keluarga->pekerjaan_ayah,
                    'usia_ayah' => $keluarga->usia_ayah,
                    'nama_ibu' => $keluarga->nama_ibu,
                    'nik_ibu' => $keluarga->nik_ibu,
                    'pekerjaan_ibu' => $keluarga->pekerjaan_ibu,
                    'usia_ibu' => $keluarga->usia_ibu,
                    'no_telp' => $keluarga->no_telp,
                ]
            ] : [];

            // === 7. Bentuk response sesuai kebutuhan FE ===
            return response()->json([
                'id' => $anak->id,
                'nik' => $anak->nik,
                'nama' => $anak->nama_anak,
                'gender' => $anak->jk,
                'rt' => $anak->rt,
                'rw' => $anak->rw,
                'kelurahan' => $anak->kelurahan,
                'kecamatan' => $anak->kecamatan,
                'kota' => $anak->kota,
                'provinsi' => $anak->provinsi,
                'nama_posyandu' => $anak->posyandu,
                'raw' => [
                    'posyandu' => $posyandu,
                    'intervensi' => $intervensi,
                    'pendampingan' => $pendampingan,
                    'kelahiran' => $kelahiran,
                    'keluarga' => $keluargaData,
                ]
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'message' => 'Gagal mengambil detail anak',
                'error' => $e->getMessage()
            ], 500);

        }
    }

    // ENDPOINT DETAIL
    public function detail_tren(Request $request)
    {
        $filters = [
            'provinsi' => $request->provinsi,
            'kota' => $request->kota,
            'kecamatan' => $request->kecamatan,
            'kelurahan' => $request->kelurahan,
            'posyandu' => $request->posyandu,
            'rt' => $request->rt,
            'rw' => $request->rw,
            'periode' => $request->periode,
            'tipe' => $request->tipe
        ];

        $mapKategori = [
            'bbu' => [
                'field' => 'bb_u',
                'cats' => [
                    'Severely Underweight',
                    'Underweight',
                    'Normal',
                    'Risiko BB Lebih'
                ]
            ],
            'tbu' => [
                'field' => 'tb_u',
                'cats' => [
                    'Severely Stunted',
                    'Stunted',
                    'Normal',
                    'Tinggi'
                ]
            ],
            'bbtb' => [
                'field' => 'bb_tb',
                'cats' => [
                    'Severely Wasted',
                    'Wasted',
                    'Normal',
                    'Possible Risk of Overweight',
                    'Overweight',
                    'Obese'
                ]
            ]
        ];

        $tipe = $filters['tipe'];
        if (!isset($mapKategori[$tipe])) {
            return response()->json(['error' => 'Tipe tidak valid'], 400);
        }

        $field = $mapKategori[$tipe]['field'];
        $categories = $mapKategori[$tipe]['cats'];

        //dd($categories);
        if (empty($filters['periode'])) {
            // ✅ default: H-1 vs H-2 bulan berjalan
            $endDate = now()->subMonth()->endOfMonth();
            $startDate = now()->subMonths(2)->startOfMonth();
        } else {
            // periode = bulan acuan (YYYY-MM)
            $base = Carbon::createFromFormat('Y-m', $filters['periode']);

            $startDate = $base->copy()->startOfMonth();
            $endDate = $base->copy()->addMonth()->endOfMonth();
        }

        // ✅ generate bulan (YYYY-MM)
        $period = CarbonPeriod::create(
            $startDate->copy()->startOfMonth(),
            '1 month',
            $endDate->copy()->startOfMonth()
        );


        $months = [];
        foreach ($period as $p) {
            $months[$p->format('Y-m')] = 0;
        }

        $result = [
            'L' => [],
            'P' => [],
            'total' => [],
            'start' => $startDate,
            'end' => $endDate
        ];

        foreach ($categories as $cat) {
            /* $result['L'][$cat] = $this->getCountPerMonth($field, $cat, 'L', $startDate, $endDate, $months);
            $result['P'][$cat] = $this->getCountPerMonth($field, $cat, 'P', $startDate, $endDate, $months);
            $result['total'][$cat] = $this->getCountPerMonth($field, $cat, null, $startDate, $endDate, $months); */
            $result['L'][$cat] = $this->getCountPerMonth(
                $field,
                $cat,
                'L',
                $startDate,
                $endDate,
                $months,
                $filters
            );

            $result['P'][$cat] = $this->getCountPerMonth(
                $field,
                $cat,
                'P',
                $startDate,
                $endDate,
                $months,
                $filters
            );

            $result['total'][$cat] = $this->getCountPerMonth(
                $field,
                $cat,
                null,
                $startDate,
                $endDate,
                $months,
                $filters
            );

        }

        $result['trend'] = [];
        foreach ($categories as $cat) {
            $values = array_values($result['total'][$cat]);
            $latest = $values[1] ?? 0;
            $prev = $values[0] ?? 0;

            $result['trend'][$cat] = $latest - $prev;
        }

        // ===============================
        // ✅ SUMMARY SESUAI UI (PER GENDER)
        // ===============================
        $summaryIndex = empty($filters['periode']) ? 1 : 0;
        $monthKeys = array_keys($months);
        $summaryMonth = $monthKeys[$summaryIndex] ?? null;

        $tidakNaik = [
            'L' => 0,
            'P' => 0
        ];

        if ($summaryMonth) {
            foreach (['L', 'P'] as $jk) {
                $tidakNaik[$jk] = Kunjungan::query()
                    ->where('jk', $jk)
                    ->where('naik_berat_badan', 1)
                    ->whereRaw("DATE_FORMAT(tgl_pengukuran, '%Y-%m') = ?", [$summaryMonth])
                    ->distinct('nik')
                    ->count('nik');
            }
        }

        $genderSummary = [
            'L' => [
                'label' => 'Laki - Laki',
                'total' => 0,
                'categories' => []
            ],
            'P' => [
                'label' => 'Perempuan',
                'total' => 0,
                'categories' => []
            ]
        ];

        foreach (['L', 'P'] as $jk) {
            foreach ($categories as $cat) {

                // 🔑 ambil HANYA 1 bulan sesuai rule
                $value = $result[$jk][$cat][$summaryIndex] ?? 0;

                $genderSummary[$jk]['categories'][] = [
                    'name' => $cat,
                    'value' => $value
                ];

                $genderSummary[$jk]['total'] += $value;
            }
            // ✅ TAMBAH KATEGORI TIDAK NAIK
            $genderSummary[$jk]['categories'][] = [
                'name' => 'Tidak Naik',
                'value' => $tidakNaik[$jk] ?? 0
            ];
        }
        /* $genderSummary = [
            'L' => [
                'label' => 'Laki - Laki',
                'total' => 0,
                'categories' => []
            ],
            'P' => [
                'label' => 'Perempuan',
                'total' => 0,
                'categories' => []
            ]
        ];

        foreach (['L', 'P'] as $jk) {
            foreach ($categories as $cat) {
                $sum = array_sum($result[$jk][$cat] ?? []);

                $genderSummary[$jk]['categories'][] = [
                    'name'  => $cat,
                    'value' => $sum
                ];

                $genderSummary[$jk]['total'] += $sum;
            }
        } */

        return response()->json([
            "data" => [
                ...$result,
                "gender_summary" => $genderSummary
            ]
        ], 200);
    }

    public function detail_umur(Request $request)
    {
        $filters = $request->only([
            'provinsi',
            'kota',
            'kecamatan',
            'kelurahan',
            'posyandu',
            'rt',
            'rw',
            'periode',
            'tipe'
        ]);

        $mapKategori = [
            'bbu' => [
                'field' => 'bb_u',
                'cats' => ['Severely Underweight', 'Underweight', 'Normal', 'Risiko BB Lebih']
            ],
            'tbu' => [
                'field' => 'tb_u',
                'cats' => ['Severely Stunted', 'Stunted', 'Normal', 'Tinggi']
            ],
            'bbtb' => [
                'field' => 'bb_tb',
                'cats' => ['Severely Wasted', 'Wasted', 'Normal', 'Possible Risk of Overweight', 'Overweight', 'Obese']
            ]
        ];

        if (!isset($mapKategori[$filters['tipe']])) {
            return response()->json(['error' => 'Tipe tidak valid'], 400);
        }

        $field = $mapKategori[$filters['tipe']]['field'];
        $categories = $mapKategori[$filters['tipe']]['cats'];

        // ======================
        // 📆 PERIODE (1 BULAN)
        // ======================
        if (empty($filters['periode'])) {
            $startDate = now()->subMonth()->startOfMonth();
            $endDate = now()->subMonth()->endOfMonth();
        } else {
            $base = Carbon::createFromFormat('Y-m', $filters['periode']);
            $startDate = $base->copy()->startOfMonth();
            $endDate = $base->copy()->endOfMonth();
        }

        $months = [
            $startDate->format('Y-m') => 0
        ];

        // ======================
        // 🎯 RANGE UMUR (BULAN)
        // ======================
        $ageRanges = [
            '0-5' => [0, 5],
            '6-11' => [6, 11],
            '12-17' => [12, 17],
            '18-23' => [18, 23],
            '24-35' => [24, 35],
            '36-47' => [36, 47],
            '48-60' => [48, 60],
        ];

        $result = [
            'start' => $startDate,
            'end' => $endDate
        ];

        foreach ($ageRanges as $label => $range) {

            // ======================
            // STATUS GIZI
            // ======================
            foreach ($categories as $cat) {
                $result[$label][$cat] = $this->getCountPerMonthByAge(
                    $field,
                    $cat,
                    $range,
                    $startDate,
                    $endDate,
                    $months,
                    $filters // ✅ TAMBAH INI
                );
            }

            // ======================
            // 🚨 TIDAK NAIK BB
            // ======================
            $result[$label]['TIDAK NAIK'] = $this->getTidakNaikPerMonthByAge(
                $range,
                $startDate,
                $endDate,
                $months,
                $filters // ✅ TAMBAH INI
            );
        }

        return response()->json(['detail_umur' => $result], 200);
    }

    public function detail_indikator(Request $request)
    {
        $filters = $request->only([
            'provinsi',
            'kota',
            'kecamatan',
            'kelurahan',
            'posyandu',
            'rt',
            'rw',
            'tipe'
        ]);

        $mapKategori = [
            'bbu' => [
                'field' => 'bb_u',
                'cats' => ['Severely Underweight', 'Underweight', 'Normal', 'Risiko BB Lebih']
            ],
            'tbu' => [
                'field' => 'tb_u',
                'cats' => ['Severely Stunted', 'Stunted', 'Normal', 'Tinggi']
            ],
            'bbtb' => [
                'field' => 'bb_tb',
                'cats' => ['Severely Wasted', 'Wasted', 'Normal', 'Possible Risk of Overweight', 'Overweight', 'Obese']
            ]
        ];

        if (!isset($mapKategori[$filters['tipe']])) {
            return response()->json(['error' => 'Tipe tidak valid'], 400);
        }

        $field = $mapKategori[$filters['tipe']]['field'];
        $categories = $mapKategori[$filters['tipe']]['cats'];

        // ======================
        // 📆 PERIODE 12 BULAN
        // ======================
        $endDate = now()->startOfMonth();          // bulan ini
        $startDate = now()->subMonths(11)->startOfMonth(); // 11 bulan ke belakang

        // ======================
        // 🗓️ LIST BULAN
        // ======================
        $period = CarbonPeriod::create($startDate, '1 month', $endDate);

        $months = [];
        foreach ($period as $p) {
            $months[$p->format('Y-m')] = 0;
        }
        //dd(array_keys($months));
        $result = [
            'start' => $startDate,
            'end' => $endDate,
            'months' => array_keys($months),
            'data' => []
        ];

        // ======================
        // 📊 KATEGORI UTAMA
        // ======================
        foreach ($categories as $cat) {
            $result['data'][$cat] = $this->getCountPerMonthIndikator(
                $field,
                $cat,
                $startDate,
                $endDate,
                $months,
                $filters //
            );
        }

        // ======================
        // 🚨 KHUSUS BBU: TIDAK NAIK
        // ======================
        if ($filters['tipe'] === 'bbu') {
            $result['data']['TIDAK NAIK'] = $this->getTidakNaikPerMonthIndikator(
                $startDate,
                $endDate,
                $months,
                $filters //
            );
        }

        return response()->json(['indikator' => $result], 200);
    }

    // CRUD
    public function delete($nik)
    {
        try {
            DB::beginTransaction();

            $deletedKunjungan = false;
            $deletedPendampingan = false;
            $deletedIntervensi = false;

            // Kunjungan
            if (Kunjungan::where('nik', $nik)->exists()) {
                Kunjungan::where('nik', $nik)->delete();
                $deletedKunjungan = true;
            }

            // Intervensi
            if (Intervensi::where('nik_subjek', $nik)->exists()) {
                Intervensi::where('nik_subjek', $nik)->delete();
                $deletedIntervensi = true;
            }

            // Child / Pendampingan
            if (Child::where('nik_anak', $nik)->exists()) {
                Child::where('nik_anak', $nik)->delete();
                $deletedPendampingan = true;
            }

            \App\Models\Log::create([
                'id_user' => \Auth::id(),
                'context' => 'Data Anak',
                'activity' => 'Hapus data anak ' . ($nik ?? '-'),
                'timestamp' => now(),
            ]);

            if (!$deletedKunjungan) {
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

    public function store(Request $request)
    {
        try {
            $user = Auth::user();

            // 1. Validasi input
            $validated = $request->validate([
                'nik' => 'required|string',
                'tgl_pengukuran' => 'required|date',
                'tgl_lahir' => 'required|date',
                'bb' => 'required|numeric',
                'tb' => 'required|numeric',
                'lika' => 'nullable|numeric',
                'gender' => 'required|string',
            ]);
            $usia = $this->hitungUmurBulan($validated['tgl_lahir'], $validated['tgl_pengukuran']);
            $jk = $validated['gender'] == 'Perempuan' ? 'p' : 'l';
            //$jk = strtolower($validated['gender']);
            $z_bbu = $this->hitungZScore('BB/U', $jk, $usia, $validated['bb']);
            $z_tbu = $this->hitungZScore('TB/U', $jk, $usia, $validated['tb']);
            $z_bbtb = $this->hitungZScore('BB/TB', $jk, $validated['tb'], $validated['bb']);

            // Tentukan status berdasarkan z-score
            $status_bbu = $this->statusBBU($z_bbu);
            $status_tbu = $this->statusTBU($z_tbu);
            $status_bbtb = $this->statusBBTB($z_bbtb);

            // Cek apakah berat naik
            $naikBB = $this->cekNaikBB($validated['nik'], $validated['bb'], $validated['tgl_pengukuran'], 'kunjungan');
            $kunjungan = Kunjungan::where('nik', $validated['nik'])
                ->orderBy('tgl_pengukuran', 'desc')
                ->first();

            // 2. Simpan data ke tabel kunjungan
            $data = Kunjungan::create([
                'petugas' => $user->name,
                'nik' => $validated['nik'],
                'nama_anak' => $request->nama_anak,
                'jk' => $jk,
                'tgl_lahir' => Carbon::parse($validated['tgl_lahir']),
                'bb_lahir' => $kunjungan->bb_lahir,
                'tb_lahir' => $kunjungan->tb_lahir,
                'nama_ortu' => $kunjungan->nama_ortu,
                'peran' => $kunjungan->peran,
                'nik_ortu' => $kunjungan->nik_ortu,
                'alamat' => $kunjungan->alamat,
                'provinsi' => $kunjungan->provinsi,
                'kota' => $kunjungan->kota,
                'kecamatan' => $kunjungan->kecamatan,
                'kelurahan' => $kunjungan->kelurahan,
                'rw' => $kunjungan->rw,
                'rt' => $kunjungan->rt,
                'puskesmas' => $kunjungan->puskesmas,
                'posyandu' => $request->unit_posyandu ? $request->unit_posyandu : $kunjungan->posyandu,
                'tgl_pengukuran' => Carbon::parse($validated['tgl_pengukuran']),
                'usia_saat_ukur' => $usia,
                'bb' => $validated['bb'],
                'tb' => $validated['tb'],
                'lika' => $validated['lika'],
                'bb_u' => $status_bbu,
                'zs_bb_u' => $z_bbu,
                'tb_u' => $status_tbu,
                'zs_tb_u' => $z_tbu,
                'bb_tb' => $status_bbtb,
                'zs_bb_tb' => $z_bbtb,
                'naik_berat_badan' => $naikBB,
                'diasuh_oleh' => $kunjungan->diasuh_oleh,
                'asi' => $kunjungan->asi,
                'imunisasi' => $kunjungan->imunisasi,
                'rutin_posyandu' => $kunjungan->rutin_posyandu,
                'penyakit_bawaan' => $kunjungan->penyakit_bawaan,
                'penyakit_6bulan' => $kunjungan->penyakit_6bulan,
                'terpapar_asap_rokok' => $kunjungan->terpapar_asap_rokok,
                'penggunaan_jamban_sehat' => $kunjungan->penggunaan_jamban_sehat,
                'penggunaan_sab' => $kunjungan->penggunaan_sab,
                'memiliki_jaminan' => $kunjungan->memiliki_jaminan,
                'kie' => $kunjungan->kie,
                'mendapatkan_bantuan' => $kunjungan->mendapatkan_bantuan,
                'catatan' => 'Perekaman data '.$request->nama_anak.' pada '.Carbon::parse($validated['tgl_pengukuran']).' dengan hasil '.$status_bbtb.' secara manual input data',
                'kpsp' => $kunjungan->kpsp,
                'no_kk' => $kunjungan->no_kk
            ]);

            \App\Models\Log::create([
                'id_user' => \Auth::id(),
                'context' => 'Data Anak',
                'activity' => 'Rekam data anak ' . ($request->nama_anak ?? '-'),
                'timestamp' => now(),
            ]);

            return response()->json([
                'message' => 'Data '.$request->nama_anak.' berhasil disimpan',
                'data' => $data
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal menyimpan data '.$request->nama_anak,
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
                'nama_ortu' => 'nullable|string',
                'bb' => 'nullable|numeric',
                'tb' => 'nullable|numeric',
                'lika' => 'nullable|numeric',
                'gender' => 'nullable|string',
            ]);

            if ($request->isEmptyNIK) {
                // 2. Cari data berdasarkan ID
                $data = Kunjungan::where('id', $request->id)->update([
                    'nik' => $request->nik,
                    'nama_ortu' => $validated['nama_ortu'],
                    'bb' => $validated['bb'],
                    'tb' => $validated['tb'],
                ]);
            }else {
                // 2. Cari data berdasarkan NIK
                $data = Kunjungan::where('nik', $nik)->update([
                    'nik' => $request->nik,
                    'nama_ortu' => $validated['nama_ortu'],
                    'bb' => $validated['bb'],
                    'tb' => $validated['tb'],
                ]);
            }


            \App\Models\Log::create([
                'id_user' => \Auth::id(),
                'context' => 'Data Anak',
                'activity' => 'Ubah data anak ' . ($nik ?? '-'),
                'timestamp' => now(),
            ]);

            return response()->json([
                'message' => 'Data berhasil diperbarui',
                'data' => $data
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal update data',
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

            $deletedKunjungan = Kunjungan::whereIn('nik', $niks)->delete();
            $deletedIntervensi = Intervensi::whereIn('nik_subjek', $niks)->delete();
            $deletedPendampingan = Child::whereIn('nik_anak', $niks)->delete();

            DB::commit();

            \App\Models\Log::create([
                'id_user'  => Auth::id(),
                'context'  => 'Data Anak',
                'activity' => 'Bulk Delete (' . (
                    $deletedKunjungan +
                    $deletedIntervensi +
                    $deletedPendampingan
                ) . ' data)',
                'timestamp'=> now(),
            ]);

            return response()->json([
                'success' => true,
                'deleted' => [
                    'kunjungan'     => $deletedKunjungan,
                    'intervensi'    => $deletedIntervensi,
                    'pendampingan'  => $deletedPendampingan,
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

}

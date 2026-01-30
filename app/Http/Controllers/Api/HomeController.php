<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Pregnancy;
use App\Models\Kunjungan;
use Carbon\Carbon;
use App\Models\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class HomeController extends Controller
{
    public function getBumil()
    {
        try {
            $query = Pregnancy::query();

            $startDate = now()->subMonths(11)->startOfMonth();
            $endDate = now()->endOfMonth();

            $query->whereBetween('tanggal_pemeriksaan_terakhir', [$startDate, $endDate]);

            $data = $query->get([
                'nik_ibu',
                'tanggal_pemeriksaan_terakhir',
                'status_gizi_lila',
                'status_gizi_hb',
                'status_risiko_usia'
            ]);

            $data = $data->groupBy('nik_ibu')->map(function ($group) {
                return $group->sortByDesc('tanggal_pemeriksaan_terakhir')->first();
            });

            if ($data->isEmpty()) {
                return response()->json([
                    'labels' => [],
                    'indikator' => [],
                    'countBumil' => ''
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
                return Carbon::parse($item->tanggal_pemeriksaan_terakhir)->format('Y-m');
            });

            //dd($groupedByMonth);
            // Hitung
            foreach ($groupedByMonth as $monthKey => $rows) {
                $label = Carbon::createFromFormat('Y-m-d', $monthKey."-01")->format('M Y');
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

            $indikatorChart = [
                'KEK' => [
                    'data'  => $result['KEK'],
                    'color' => '#880804ff',
                ],
                'Anemia' => [
                    'data'  => $result['Anemia'],
                    'color' => '#b49900ff',
                ],
                'Risiko Tinggi' => [
                    'data'  => $result['Berisiko'],
                    'color' => '#006341',
                ],
            ];
            $dataLatest = $data->whereBetween('tanggal_pemeriksaan_terakhir', [now()->startOfMonth(), $endDate]);
            return response()->json([
                'labels' => $months,
                'indikator' => $indikatorChart,
                'countBumil' => $dataLatest->count()
            ]);

        } catch (\Throwable $th) {
            return response()->json([
                'error' => 'Gagal memuat data indikator bulanan',
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    public function getAnak()
    {
        try {

            // 1. Ambil data kunjungan
            $data = Kunjungan::select(
                'nik',
                'tgl_pengukuran',
                'usia_saat_ukur',
                'bb_u',
                'tb_u',
                'bb_tb'
            )->get();

            if ($data->isEmpty()) {
                return response()->json([]);
            }

            // 2. Ambil record terbaru per anak
            $latestData = $data->groupBy('nik')->map(function ($rows) {
                return $rows->sortByDesc('tgl_pengukuran')->first();
            });

            // 3. Kelompok umur
            $umurGroups = [
                '0-5'   => [0, 5],
                '6-11'  => [6, 11],
                '12-17' => [12, 17],
                '18-23' => [18, 23],
                '24-29' => [24, 29],
                '30-35' => [30, 35],
                '36-41' => [36, 41],
                '42-47' => [42, 47],
                '48-60' => [48, 60],
            ];

            $groupLabels = array_keys($umurGroups);

            // 4. Status
            // BB/U
            $statusList_bbu = [
                'Severely Underweight',
                'Underweight',
                'Normal',
                'Risiko bb lebih'
            ];

            // TB/U
            $statusList_tbu = [
                'Severely Stunted',
                'Stunted',
                'Normal',
                'Tinggi'
            ];

            // BB/TB
            $statusList_bbtb = [
                'Severely Wasted',
                'Wasted',
                'Normal',
                'Possible risk of Overweight',
                'Overweight',
                'Obesitas'
            ];

            // 5. Warna status
            // BBU
            $statusColors_bbu = [
                'Severely Underweight' => '#c0392b',
                'Underweight'          => '#e67e22',
                'Normal'               => '#27ae60',
                'Risiko bb lebih'      => '#2980b9',
            ];
            // TBU
            $statusColors_tbu = [
                'Severely Stunted' => '#c0392b',
                'Stunted'          => '#e67e22',
                'Normal'               => '#27ae60',
                'Tinggi'      => '#2980b9',
            ];
            // BBTB
            $statusColors_bbtb = [
                'Severely Wasted' => '#c0392b',
                'Wasted'          => '#e67e22',
                'Normal'          => '#27ae60',
                'Possible risk of Overweight'      => '#2980b9',
                'Overweight'      => '#9529b9ff',
                'Obesitas'        => '#2f2f2fff',
            ];

            // 6. Struktur final chart
            $bbu = [];
            $tbu = [];
            $bbtb = [];
            foreach ($statusList_bbu as $status) {
                $bbu[$status] = [
                    'data'  => array_fill(0, count($groupLabels), 0),
                    'color' => $statusColors_bbu[$status],
                ];
            }

            foreach ($statusList_tbu as $status) {
                $tbu[$status] = [
                    'data'  => array_fill(0, count($groupLabels), 0),
                    'color' => $statusColors_tbu[$status],
                ];
            }

            foreach ($statusList_bbtb as $status) {
                $bbtb[$status] = [
                    'data'  => array_fill(0, count($groupLabels), 0),
                    'color' => $statusColors_bbtb[$status],
                ];
            }

            // 7. Isi data berdasarkan umur + status
            foreach ($latestData as $anak) {

                $umur = intval($anak->usia_saat_ukur);
                $status_bbu = strtolower(trim($anak->bb_u));
                $status_tbu = strtolower(trim($anak->tb_u));
                $status_bbtb = strtolower(trim($anak->bb_tb));
                // cari index umur group
                $groupIndex = null;
                foreach ($umurGroups as $label => $range) {
                    [$min, $max] = $range;
                    if ($umur >= $min && $umur <= $max) {
                        $groupIndex = array_search($label, $groupLabels);
                        break;
                    }
                }

                if ($groupIndex === null) continue;

                // masukkan nilai ke status yg cocok
                foreach ($statusList_bbu as $s) {
                    if ($status_bbu === strtolower($s)) {
                        $bbu[$s]['data'][$groupIndex]++;
                    }
                }

                foreach ($statusList_tbu as $s) {
                    if ($status_tbu === strtolower($s)) {
                        $tbu[$s]['data'][$groupIndex]++;
                        //dd($tbu);
                    }
                }

                foreach ($statusList_bbtb as $s) {
                    if ($status_bbtb === strtolower($s)) {
                        $bbtb[$s]['data'][$groupIndex]++;
                        //dd($tbu);
                    }
                }
            }

            return response()->json([
                'labels'      => array_keys($umurGroups),
                'bbu'         => $bbu,
                'tbu'         => $tbu,
                'bbtb'         => $bbtb,
            ]);
            //return response()->json($chartData);

        } catch (\Throwable $th) {
            return response()->json([
                'error' => 'Gagal memuat data anak',
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    public function getIndikatorAnak()
    {
        try {
            $query = Kunjungan::query();

            $startDate = now()->subMonths(11)->startOfMonth();
            $endDate = now()->endOfMonth();

            $query->whereBetween('tgl_pengukuran', [$startDate, $endDate]);
            //dd($query);
            $data = $query->get([
                'nik',
                'tgl_pengukuran',
                'usia_saat_ukur',
                'bb_u',
                'tb_u',
                'bb_tb'
            ]);

            $data = $data->groupBy('nik')->map(function ($group) {
                return $group->sortByDesc('tgl_pengukuran')->first();
            });

            //dd($data);
            if ($data->isEmpty()) {
                return response()->json([
                    'labels' => [],
                    'indikator' => [],
                ]);
            }

            $months = collect(range(0, 11))
                ->map(fn($i) => now()->startOfMonth()->subMonths(11 - $i)->format('M Y'))
                ->values();

            // BB/U
            $indikatorList_bbu = [
                'Severely Underweight',
                'Underweight',
                'Normal',
                'Risiko bb lebih'
            ];

            // TB/U
            $indikatorList_tbu = [
                'Severely Stunted',
                'Stunted',
                'Normal',
                'Tinggi'
            ];

            // BB/TB
            $indikatorList_bbtb = [
                'Severely Wasted',
                'Wasted',
                'Normal',
                'Possible risk of Overweight',
                'Overweight',
                'Obesitas'
            ];

            $bbu = [];
            $tbu = [];
            $bbtb = [];
            foreach ($indikatorList_bbu as $indikator) {
                $bbu[$indikator] = array_fill(0, 12, 0);
            }
            foreach ($indikatorList_tbu as $indikator) {
                $tbu[$indikator] = array_fill(0, 12, 0);
            }
            foreach ($indikatorList_bbtb as $indikator) {
                $bbtb[$indikator] = array_fill(0, 12, 0);
            }
            // Group per bulan, ambil semua record
            $groupedByMonth = $data->groupBy(function ($item) {
                return Carbon::parse($item->tgl_pengukuran)->format('Y-m');
            });

            // Hitung
            foreach ($groupedByMonth as $monthKey => $rows) {
                $label = Carbon::createFromFormat('Y-m-d', $monthKey."-01")->format('M Y');
                $idx = $months->search($label);
                if ($idx === false)
                    continue;

                $bbu['Severely Underweight'][$idx] = $rows->filter(
                    fn($i) =>
                    str_contains(strtolower($i->bb_u ?? ''), 'severely underweight')
                )->count();

                $bbu['Underweight'][$idx] = $rows->filter(
                    fn($i) =>
                    str_contains(strtolower($i->bb_u ?? ''), 'underweight')
                )->count();

                $bbu['Normal'][$idx] = $rows->filter(
                    fn($i) =>
                    str_contains(strtolower($i->bb_u ?? ''), 'normal')
                )->count();

                $bbu['Risiko bb lebih'][$idx] = $rows->filter(
                    fn($i) =>
                    str_contains(strtolower($i->bb_u ?? ''), 'risiko bb lebih')
                )->count();
            }
            foreach ($groupedByMonth as $monthKey => $rows) {
                $label = Carbon::createFromFormat('Y-m-d', $monthKey."-01")->format('M Y');
                $idx = $months->search($label);
                if ($idx === false)
                    continue;

                $tbu['Severely Stunted'][$idx] = $rows->filter(
                    fn($i) =>
                    str_contains(strtolower($i->tb_u ?? ''), 'severely stunted')
                )->count();

                $tbu['Stunted'][$idx] = $rows->filter(
                    fn($i) =>
                    str_contains(strtolower($i->tb_u ?? ''), 'stunted')
                )->count();

                $tbu['Normal'][$idx] = $rows->filter(
                    fn($i) =>
                    str_contains(strtolower($i->tb_u ?? ''), 'normal')
                )->count();

                $tbu['Tinggi'][$idx] = $rows->filter(
                    fn($i) =>
                    str_contains(strtolower($i->tb_u ?? ''), 'tinggi')
                )->count();
            }
            foreach ($groupedByMonth as $monthKey => $rows) {
                $label = Carbon::createFromFormat('Y-m-d', $monthKey."-01")->format('M Y');
                $idx = $months->search($label);
                if ($idx === false)
                    continue;

                $bbtb['Severely Wasted'][$idx] = $rows->filter(
                    fn($i) =>
                    str_contains(strtolower($i->bb_tb ?? ''), 'severely wasted')
                )->count();

                $bbtb['Wasted'][$idx] = $rows->filter(
                    fn($i) =>
                    str_contains(strtolower($i->bb_tb ?? ''), 'wasted')
                )->count();

                $bbtb['Normal'][$idx] = $rows->filter(
                    fn($i) =>
                    str_contains(strtolower($i->bb_tb ?? ''), 'normal')
                )->count();
                $bbtb['Possible risk of Overweight'][$idx] = $rows->filter(
                    fn($i) =>
                    str_contains(strtolower($i->bb_tb ?? ''), 'possible risk of overweight')
                )->count();
                $bbtb['Overweight'][$idx] = $rows->filter(
                    fn($i) =>
                    str_contains(strtolower($i->bb_tb ?? ''), 'overweight')
                )->count();
                $bbtb['Obesitas'][$idx] = $rows->filter(
                    fn($i) =>
                    str_contains(strtolower($i->bb_tb ?? ''), 'obesitas')
                )->count();
            }

            $indikatorChart_bbu = [
                'Severely Underweight' => [
                    'data'  => $bbu['Severely Underweight'],
                    'color' => '#880804ff',
                ],
                'Underweight' => [
                    'data'  => $bbu['Underweight'],
                    'color' => '#b49900ff',
                ],
                'Normal' => [
                    'data'  => $bbu['Normal'],
                    'color' => '#006341',
                ],
                'Risiko BB Lebih' => [
                    'data'  => $bbu['Risiko bb lebih'],
                    'color' => '#002963ff',
                ],
            ];
            $indikatorChart_tbu = [
                'Severely Stunted' => [
                    'data'  => $tbu['Severely Stunted'],
                    'color' => '#880804ff',
                ],
                'Stunted' => [
                    'data'  => $tbu['Stunted'],
                    'color' => '#b49900ff',
                ],
                'Normal' => [
                    'data'  => $tbu['Normal'],
                    'color' => '#006341',
                ],
                'Tinggi' => [
                    'data'  => $tbu['Tinggi'],
                    'color' => '#002963ff',
                ],
            ];
            $indikatorChart_bbtb = [
                'Severely Wasted' => [
                    'data'  => $bbtb['Severely Wasted'],
                    'color' => '#880804ff',
                ],
                'Wasted' => [
                    'data'  => $bbtb['Wasted'],
                    'color' => '#b49900ff',
                ],
                'Normal' => [
                    'data'  => $bbtb['Normal'],
                    'color' => '#006341',
                ],
                'Possible risk of Overweight' => [
                    'data'  => $bbtb['Possible risk of Overweight'],
                    'color' => '#002963ff',
                ],
                'Overweight' => [
                    'data'  => $bbtb['Overweight'],
                    'color' => '#4a0063ff',
                ],
                'Obesitas' => [
                    'data'  => $bbtb['Obesitas'],
                    'color' => '#313132ff',
                ],

            ];

            $dataLatest = $data->whereBetween('tgl_pengukuran', [now()->startOfMonth(), $endDate]);
            return response()->json([
                'labels' => $months,
                'bbu' => $indikatorChart_bbu,
                'tbu' => $indikatorChart_tbu,
                'bbtb' => $indikatorChart_bbtb
            ]);

        } catch (\Throwable $th) {
            return response()->json([
                'error' => 'Gagal memuat data indikator bulanan',
                'message' => $th->getMessage(),
            ], 500);
        }
    }

}

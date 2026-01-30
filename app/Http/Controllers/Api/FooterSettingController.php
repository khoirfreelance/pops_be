<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FooterSetting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class FooterSettingController extends Controller
{
    /* =========================
       PUBLIC
    ========================== */
    public function show()
    {
        $footer = FooterSetting::first();

        return response()->json([
            'data' => $footer
        ]);
    }

    /* =========================
       ADMIN SAVE
    ========================== */
    public function store(Request $request)
    {
        $footer = FooterSetting::first();

        if (!$footer) {
            $footer = new FooterSetting();
        }

        // upload logo
        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('footer', 'public');

            $footer->logo_path = $path;
            $footer->logo_url  = asset('storage/' . $path);
        }

        $footer->save();

        return response()->json([
            'message' => 'Footer saved',
            'data' => $footer
        ]);
    }

    /*ambil ini untuk heatmap*/
    public function statusByProvinsi(Request $request)
    {
        $bulanLalu = Carbon::now()->subMonth();
        $rows = DB::table('kunjungan_anak')
            ->select(
                'provinsi',
                DB::raw('COUNT(nik) as total_anak'),

                DB::raw("SUM(CASE WHEN tb_u LIKE '%Stunted%' THEN 1 ELSE 0 END) as stunting"),
                DB::raw("SUM(CASE WHEN bb_tb LIKE '%Wasted%' THEN 1 ELSE 0 END) as wasting"),
                DB::raw("SUM(CASE WHEN bb_u LIKE '%Underweight%' THEN 1 ELSE 0 END) as underweight"),
                DB::raw("SUM(CASE WHEN bb_tb LIKE '%Overweight%' OR bb_tb LIKE '%Obes%' THEN 1 ELSE 0 END) as overweight"),
                DB::raw("SUM(CASE WHEN naik_berat_badan IS NULL OR naik_berat_badan = 0 THEN 1 ELSE 0 END) as bb_stagnan")
            )
            ->whereNotNull('provinsi')
            ->whereMonth('tgl_pengukuran', $bulanLalu->month)
            ->whereYear('tgl_pengukuran', $bulanLalu->year)
            ->groupBy('provinsi')
            ->orderBy('provinsi')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $total = max($row->total_anak, 1); // hindari divide by zero

            $result[$row->provinsi] = [
                'Stunting'        => round(($row->stunting / $total) * 100, 1) . '%',
                'Wasting'         => round(($row->wasting / $total) * 100, 1) . '%',
                'Underweight'     => round(($row->underweight / $total) * 100, 1) . '%',
                'BB Stagnan'      => round(($row->bb_stagnan / $total) * 100, 1) . '%',
                'Overweight'      => round(($row->overweight / $total) * 100, 1) . '%',
                'Total Anak Balita' => (int) $total,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $result
        ]);
    }

    public function statusByKelurahan(Request $request)
    {
        $bulanLalu = Carbon::now()->subMonth();
        $rows = DB::table('kunjungan_anak')
            ->select(
                'provinsi',
                'kota',
                'kelurahan',
                DB::raw('COUNT(nik) as total_anak'),

                DB::raw("SUM(CASE WHEN tb_u LIKE '%Stunted%' THEN 1 ELSE 0 END) as stunting"),
                DB::raw("SUM(CASE WHEN bb_tb LIKE '%Wasted%' THEN 1 ELSE 0 END) as wasting"),
                DB::raw("SUM(CASE WHEN bb_u LIKE '%Underweight%' THEN 1 ELSE 0 END) as underweight"),
                DB::raw("SUM(CASE WHEN bb_tb LIKE '%Overweight%' OR bb_tb LIKE '%Obes%' THEN 1 ELSE 0 END) as overweight"),
                DB::raw("SUM(CASE WHEN naik_berat_badan IS NULL OR naik_berat_badan = 0 THEN 1 ELSE 0 END) as bb_stagnan")
            )
            ->whereNotNull('provinsi')
            ->whereNotNull('kota')
            ->whereNotNull('kelurahan')
            ->whereMonth('tgl_pengukuran', $bulanLalu->month)
            ->whereYear('tgl_pengukuran', $bulanLalu->year)
            ->groupBy('provinsi', 'kota', 'kelurahan')
            ->orderBy('provinsi')
            ->orderBy('kota')
            ->orderBy('kelurahan')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $total = max($row->total_anak, 1);

            $result[$row->provinsi][] = [
                'Kota'       => $row->kota,
                'Desa'       => $row->kelurahan,
                'Stunting'    => round(($row->stunting / $total) * 100, 1) . '%',
                'Wasting'     => round(($row->wasting / $total) * 100, 1) . '%',
                'Underweight' => round(($row->underweight / $total) * 100, 1) . '%',
                'BB Stagnan'  => round(($row->bb_stagnan / $total) * 100, 1) . '%',
                'Overweight'  => round(($row->overweight / $total) * 100, 1) . '%',
                'Total Anak Balita' => (int) $total,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $result
        ]);
    }


    public function statusByKecamatan(Request $request)
    {
        $bulanLalu = Carbon::now()->subMonth();
        $rows = DB::table('kunjungan_anak')
            ->select(
                'provinsi',
                'kecamatan',
                DB::raw('COUNT(nik) as total_anak'),

                DB::raw("SUM(CASE WHEN tb_u LIKE '%Stunted%' THEN 1 ELSE 0 END) as stunting"),
                DB::raw("SUM(CASE WHEN bb_tb LIKE '%Wasted%' THEN 1 ELSE 0 END) as wasting"),
                DB::raw("SUM(CASE WHEN bb_u LIKE '%Underweight%' THEN 1 ELSE 0 END) as underweight"),
                DB::raw("SUM(CASE WHEN bb_tb LIKE '%Overweight%' OR bb_tb LIKE '%Obes%' THEN 1 ELSE 0 END) as overweight"),
                DB::raw("SUM(CASE WHEN naik_berat_badan IS NULL OR naik_berat_badan = 0 THEN 1 ELSE 0 END) as bb_stagnan")
            )
            ->whereNotNull('provinsi')
            ->whereNotNull('kecamatan')
            ->whereMonth('tgl_pengukuran', $bulanLalu->month)
            ->whereYear('tgl_pengukuran', $bulanLalu->year)
            ->groupBy('provinsi', 'kecamatan')
            ->orderBy('provinsi')
            ->orderBy('kecamatan')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $total = max($row->total_anak, 1);

            $result[$row->provinsi][] = [
                'Desa'       => $row->kecamatan,
                'Stunting'    => round(($row->stunting / $total) * 100, 1) . '%',
                'Wasting'     => round(($row->wasting / $total) * 100, 1) . '%',
                'Underweight' => round(($row->underweight / $total) * 100, 1) . '%',
                'BB Stagnan'  => round(($row->bb_stagnan / $total) * 100, 1) . '%',
                'Overweight'  => round(($row->overweight / $total) * 100, 1) . '%',
                'Total Anak Balita' => (int) $total,
            ];
        }


        return response()->json([
            'success' => true,
            'data' => $result
        ]);
    }

}

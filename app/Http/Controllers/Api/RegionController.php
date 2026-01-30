<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wilayah;
use Illuminate\Http\Request;

class RegionController extends Controller
{
    public function index(Request $request)
    {
        $rows = Wilayah::query()
            ->orderBy('provinsi')
            ->orderBy('kota')
            ->orderBy('kecamatan')
            ->orderBy('kelurahan')
            ->get();

        $grouped = $rows
            ->groupBy(fn ($item) =>
                "{$item->provinsi}|{$item->kota}|{$item->kecamatan}"
            )
            ->map(function ($items, $key) {
                [$prov, $kota, $kec] = explode('|', $key);

                return [
                    'provinsi'  => $prov,
                    'kota'      => $kota,
                    'kecamatan' => $kec,
                    'label'     => "{$prov} - {$kota} - {$kec}",
                    'options'   => $items->map(fn ($row) => [
                        'id'         => $row->id,
                        'provinsi'   => $row->provinsi,
                        'kota'       => $row->kota,
                        'kecamatan'  => $row->kecamatan,
                        'kelurahan'  => $row->kelurahan,
                        'label'      => $row->kelurahan,
                    ])->values()
                ];
            })
            ->values();

        return response()->json([
            'status' => true,
            'data'   => $grouped
        ]);
    }

    public function getProvinsi()
    {
        $provinsi = Wilayah::select('provinsi as nama')
            ->distinct()
            ->orderBy('nama')
            ->get();

        return response()->json($provinsi);
    }

    public function getKota(Request $request)
    {
        $kota = Wilayah::select('kota as nama')
            ->where('provinsi', $request->provinsi)
            ->distinct()
            ->orderBy('nama')
            ->get();

        return response()->json($kota);
    }

    public function getKecamatan(Request $request)
    {
        $kecamatan = Wilayah::select('kecamatan as nama')
            ->where('provinsi', $request->provinsi)
            ->where('kota', $request->kota)
            ->distinct()
            ->orderBy('nama')
            ->get();

        return response()->json($kecamatan);
    }

    public function getKelurahan(Request $request)
    {
        $kelurahan = Wilayah::select('kelurahan as nama', 'id as idWilayah')
            ->where('provinsi', $request->provinsi)
            ->where('kota', $request->kota)
            ->where('kecamatan', $request->kecamatan)
            ->distinct()
            ->orderBy('kelurahan')
            ->get();

        return response()->json($kelurahan);
    }
}

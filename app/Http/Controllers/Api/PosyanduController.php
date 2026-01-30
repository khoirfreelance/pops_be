<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Posyandu;

class PosyanduController extends Controller
{
    public function getPosyandu()
    {
        $posyandu = Posyandu::select('id','nama_posyandu as nama')
            ->distinct()
            ->orderBy('nama')
            ->get();

        return response()->json($posyandu);
    }

    // Ambil semua posyandu berdasarkan id_wilayah
    public function getByWilayah($id_wilayah)
    {
        try {
            $posyandu = Posyandu::where('id_wilayah', $id_wilayah)->get();

            if ($posyandu->isEmpty()) {
                return response()->json([
                    'message' => 'Tidak ada posyandu untuk wilayah ini.'
                ], 404);
            }

            return response()->json($posyandu, 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Terjadi kesalahan saat mengambil data posyandu.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

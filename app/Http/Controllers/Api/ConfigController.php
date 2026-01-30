<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Config; // model untuk tb_config
use Illuminate\Support\Facades\Auth;

class ConfigController extends Controller
{
    public function index()
    {
        $userId = Auth::id();

        $config = Config::where('name', 'site_config')
                        ->where('id_user', $userId)
                        ->first();

        if (!$config) {
            // Jika belum ada config untuk user ini
            return response()->json([
                'message' => 'Belum ada konfigurasi untuk user ini',
                'data' => null
            ], 200);
        }

        // Decode JSON jadi array
        $value = json_decode($config->value, true);

        // Kembalikan data logo & background langsung
        return response()->json([
            'message' => 'Konfigurasi ditemukan',
            'data'    => $value
        ]);
    }


    public function store(Request $request)
    {
        $userId = Auth::id();

        // Upload file logo (opsional)
        $logoPath = null;
        if ($request->hasFile('logo') && $request->file('logo')->isValid()) {
            $logoPath = $request->file('logo')->store('logos', 'public');
        }

        // Upload file background (opsional)
        $bgPath = null;
        if ($request->hasFile('background') && $request->file('background')->isValid()) {
            $bgPath = $request->file('background')->store('backgrounds', 'public');
        }

        // Ambil konfigurasi lama (jika ada)
        $existingConfig = Config::where('name', 'site_config')
            ->where('id_user', $userId)
            ->first();

        // Decode value lama (kalau ada)
        $oldValue = $existingConfig ? json_decode($existingConfig->value, true) : [];

        // Gabungkan data lama dan baru (supaya kalau logo tidak diupload, tidak jadi null)
        $data = [
            'logo'         => $logoPath ? asset('storage/' . $logoPath) : ($oldValue['logo'] ?? null),
            'background'   => $bgPath ? asset('storage/' . $bgPath) : ($oldValue['background'] ?? null),
            'logoWidth'    => $request->input('logoWidth'),
            'colorTheme'   => $request->input('colorTheme'),
            'footerColumn' => $request->input('footerColumn'),
            'maintenance'  => $request->input('maintenance'),
            'app'  => $request->input('app'),
        ];

        // Simpan atau update
        Config::updateOrCreate(
            [
                'name'    => 'site_config',
                'id_user' => $userId,
            ],
            [
                'value'       => json_encode($data),
                'modified_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Konfigurasi tersimpan',
            'data'    => $data,
        ]);
    }

}

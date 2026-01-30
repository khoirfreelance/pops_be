<?php

namespace App\Http\Controllers\Api;

use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Models\Bride;
use App\Models\Catin;
use App\Models\Pendampingan;
use Illuminate\Http\Request;
use App\Models\Log;
use Illuminate\Support\Facades\Auth;

class BrideController extends Controller
{
    /**
     * Tampilkan semua data pernikahan lengkap dengan pasangan dan pendampingan
     */
    public function index(Request $request)
    {
        $query = Bride::with([
            'catin' => function ($q) {
                $q->select('id', 'id_pasangan', 'nama', 'nik', 'peran', 'pekerjaan', 'tgl_lahir', 'usia', 'no_hp', 'is_pending')
                ->where('is_pending', 0);
            },
            'catin.pasangan' => function ($q) {
                $q->select('id', 'id_pasangan', 'nama', 'nik', 'peran', 'pekerjaan', 'tgl_lahir', 'usia', 'no_hp', 'is_pending')
                ->where('is_pending', 0);
            },
            'catin.pendampingan' => function ($q) {
                $q->select(
                    'id',
                    'jenis',
                    'id_subjek',
                    'tgl_pendampingan',
                    'dampingan_ke',
                    'catatan',
                    'bb',
                    'tb',
                    'lk',
                    'lila',
                    'lika',
                    'hb',
                    'usia',
                    'anemia',
                    'kek',
                    'terpapar_rokok',
                    'jamban_sehat',
                    'punya_jaminan',
                    'keluarga_teredukasi',
                    'mendapatkan_bantuan',
                    'riwayat_penyakit',
                    'ket_riwayat_penyakit',
                    'id_petugas',
                    'is_pending',
                    'created_at'
                )->where('is_pending', 0);
            }
        ])
        // ✅ hanya ambil Bride yang punya catin aktif
        ->whereHas('catin', function ($q) {
            $q->where('is_pending', 0);
        });

        // Filter opsional
        if ($request->has('nama')) {
            $query->whereHas('catin', function ($q) use ($request) {
                $q->where('nama', 'like', "%{$request->nama}%");
            });
        }

        if ($request->has('tgl_rencana_menikah')) {
            $query->whereDate('tgl_rencana_menikah', $request->tgl_rencana_menikah);
        }

        return response()->json($query->orderByDesc('id')->get());
    }

    /**
     * Simpan data pernikahan baru
     * Sekaligus buat 2 data catin (pria & perempuan)
     */
    public function store(Request $request)
    {
        return DB::transaction(function () use ($request) {
            $isPending_perempuan = empty($request->input('nik_perempuan')) ? 1 : 0;
            $isPending_pria = empty($request->input('nik_pria')) ? 1 : 0;
            $isPending = ($isPending_perempuan == 1 || $isPending_pria == 1) ? 1 : 0;

            // simpan catin perempuan
            $catinP = Catin::create([
                'nama' => $request->input('nama_perempuan'),
                'nik' => $request->input('nik_perempuan'),
                'pekerjaan' => $request->input('pekerjaan_perempuan'),
                'tgl_lahir' => $request->input('tgl_lahir_perempuan'),
                'usia' => $request->input('usia_perempuan'),
                'no_hp' => $request->input('hp_perempuan'),
                'is_pending' => $isPending_perempuan,
                'peran' => 'istri'
            ]);

            // simpan catin pria
            $catinL = Catin::create([
                'nama' => $request->input('nama_pria'),
                'nik' => $request->input('nik_pria'),
                'pekerjaan' => $request->input('pekerjaan_pria'),
                'tgl_lahir' => $request->input('tgl_lahir_pria'),
                'usia' => $request->input('usia_pria'),
                'no_hp' => $request->input('hp_pria'),
                'peran' => 'suami',
                'is_pending' => $isPending_pria,
                'id_pasangan' => $catinP->id
            ]);

            // update pasangan perempuan
            $catinP->update(['id_pasangan' => $catinL->id]);

            // simpan data pernikahan
            $bride = Bride::create([
                'id_catin' => $catinP->id,
                'tgl_daftar' => now(),
                'tgl_rencana_menikah' => $request->tgl_rencana_menikah,
                'rencana_tinggal' => $request->rencana_tinggal,
                'catatan' => $request->catatan,
                'is_pending' => $isPending,
            ]);

            $pendampingan = Pendampingan::create([
                'jenis' => 'Calon Pengantin',
                'id_subjek' => $catinP->id,
                'tgl_pendampingan' => $request->tgl_pendampingan,
                'dampingan_ke' => ($isPending == 1) ? 1 : $request->dampingan_ke,
                'catatan'=> $request->catatan,
                'bb' => $request->bb,
                'tb'=> $request->tb,
                'lila'=> $request->lila,
                'hb'=> $request->hb,
                'usia'=> $request->usia_perempuan,
                'anemia'=> $request->status_hb,
                'kek'=> $request->status_gizi,
                'terpapar_rokok'=> $request->catin_terpapar_rokok,
                'punya_jaminan'=> $request->fasilitas_rujukan,
                'keluarga_teredukasi'=> $request->edukasi,
                'mendapatkan_bantuan'=> $request->pmt,
                'riwayat_penyakit'=> $request->punya_riwayat_penyakit,
                'ket_riwayat_penyakit'=> $request->riwayat_penyakit,
                'id_petugas'=> Auth::id(),
                'is_pending' => $isPending,
                'id_wilayah' => $request->id_wilayah,
                'rw' => $request->rw,
                'rt' => $request->rt,
            ]);

            Log::create([
                'id_user'  => Auth::id(),
                'context'  => 'calon pengantin',
                'activity' => 'create',
                'timestamp'=> now(),
            ]);

            return response()->json([
                'message' => 'Data pernikahan berhasil disimpan',
                'data' => [
                    'pernikahan' => $bride,
                    'catin_perempuan' => $catinP,
                    'catin_pria' => $catinL
                ]
            ]);
        });
    }

    /**
     * Cek jumlah pendampingan berdasarkan NIK pasangan catin
     */
    public function checkDampinganKe(Request $request)
    {
        $nikP = $request->query('nik_perempuan');
        $nikL = $request->query('nik_pria');

        if (!$nikP || !$nikL) {
            return response()->json(['error' => 'NIK belum lengkap'], 400);
        }

        // Ambil data catin perempuan dan pria
        $catinP = Catin::where('nik', $nikP)->first();
        $catinL = Catin::where('nik', $nikL)->first();

        if (!$catinP || !$catinL) {
            return response()->json([
                'exists' => false,
                'dampingan_ke' => 1,
                'message' => 'Belum ada data pasangan di database'
            ]);
        }

        // Pastikan mereka pasangan valid
        $isCouple = (
            ($catinP->id_pasangan === $catinL->id) ||
            ($catinL->id_pasangan === $catinP->id)
        );

        if (!$isCouple) {
            return response()->json([
                'exists' => false,
                'dampingan_ke' => 1,
                'message' => 'Data ditemukan tapi bukan pasangan valid'
            ]);
        }

        // Hitung total pendampingan sebelumnya
        $total = Pendampingan::where('jenis', 'catin')
            ->whereIn('id_subjek', [$catinP->id, $catinL->id])
            ->count();

        return response()->json([
            'exists' => true,
            'dampingan_ke' => $total + 1,
            'message' => 'Pasangan ditemukan'
        ]);
    }

    public function search(Request $request)
    {
        $nik = $request->nik;

        if (!$nik) {
            return response()->json(['message' => 'NIK wajib diisi.'], 400);
        }

        $query = Bride::with([
            'catin',
            'catin.pasangan',
            'catin.pendampingan',
            'catin' => function ($q) {
                $q->select('id', 'id_pasangan', 'nama', 'nik', 'peran', 'pekerjaan', 'tgl_lahir', 'usia', 'no_hp');
            },
            'catin.pasangan' => function ($q) {
                $q->select('id', 'id_pasangan', 'nama', 'nik', 'peran', 'pekerjaan', 'tgl_lahir', 'usia', 'no_hp');
            },
            'catin.pendampingan' => function ($q) {
                $q->select(
                    'id',
                    'jenis',
                    'id_subjek',
                    'tgl_pendampingan',
                    'dampingan_ke',
                    'catatan',
                    'bb',
                    'tb',
                    'lk',
                    'lila',
                    'lika',
                    'hb',
                    'usia',
                    'anemia',
                    'kek',
                    'terpapar_rokok',
                    'jamban_sehat',
                    'punya_jaminan',
                    'keluarga_teredukasi',
                    'mendapatkan_bantuan',
                    'riwayat_penyakit',
                    'ket_riwayat_penyakit',
                    'id_petugas',
                    'created_at'
                );
            }
        ])
        ->whereHas('catin', function ($q) use ($nik) {
            $q->where('nik', $nik);
        })
        ->orWhereHas('catin.pasangan', function ($q) use ($nik) {
            $q->where('nik', $nik);
        });

        $result = $query->first();

         // ❌ kalau gak ada hasil, kirim 404
        if (!$result) {
            return response()->json(['message' => 'Data tidak ditemukan.'], 404);
        }

        // ✅ kalau ada, kirim data
        return response()->json($result);
    }

    public function pendingData()
    {
        $query = Bride::with([
            'catin' => function ($q) {
                $q->select('id', 'id_pasangan', 'nama', 'nik', 'peran', 'pekerjaan', 'tgl_lahir', 'usia', 'no_hp', 'is_pending')
                ->where('is_pending', 1);
            },
            'catin.pasangan' => function ($q) {
                $q->select('id', 'id_pasangan', 'nama', 'nik', 'peran', 'pekerjaan', 'tgl_lahir', 'usia', 'no_hp', 'is_pending')
                ->where('is_pending', 1);
            },
            'catin.pendampingan' => function ($q) {
                $q->select(
                    'id',
                    'jenis',
                    'id_subjek',
                    'tgl_pendampingan',
                    'dampingan_ke',
                    'catatan',
                    'bb',
                    'tb',
                    'lk',
                    'lila',
                    'lika',
                    'hb',
                    'usia',
                    'anemia',
                    'kek',
                    'terpapar_rokok',
                    'jamban_sehat',
                    'punya_jaminan',
                    'keluarga_teredukasi',
                    'mendapatkan_bantuan',
                    'riwayat_penyakit',
                    'ket_riwayat_penyakit',
                    'id_petugas',
                    'is_pending',
                    'created_at'
                )->where('is_pending', 1);
            }
        ])
        // ✅ hanya ambil Bride yang punya catin aktif
        ->whereHas('catin', function ($q) {
            $q->where('is_pending', 1);
        });

        return response()->json($query->orderByDesc('id')->get());
    }

    public function pending($id)
    {
        // Ambil bride beserta relasi catin, pasangan, dan pendampingan
        $bride = Bride::with([
            'catin.pasangan',
            'catin.pendampingan' => function ($q) {
                $q->latest('tgl_pendampingan')->take(1);
            },
        ])->find($id);

        if (!$bride) {
            return response()->json(['message' => 'Data keluarga tidak ditemukan'], 404);
        }

        $catin = $bride->catin;
        $pasangan = $catin?->pasangan;
        $pendampingan = $catin?->pendampingan?->first();

        // Tentukan mana perempuan dan mana pria
        if ($catin && $catin->peran === 'istri') {
            $perempuan = $catin;
            $pria = $pasangan;
        } else {
            $perempuan = $pasangan;
            $pria = $catin;
        }

        // Bentuk struktur sesuai form frontend
        $data = [
            'id' => $bride->id,

            // ========== DATA CATIN PEREMPUAN ==========
            'nama_perempuan' => $perempuan?->nama ?? '',
            'nik_perempuan' => $perempuan?->nik ?? '',
            'pekerjaan_perempuan' => $perempuan?->pekerjaan ?? '',
            'tgl_lahir_perempuan' => $perempuan?->tgl_lahir ?? '',
            'usia_perempuan' => $perempuan?->usia ?? null,
            'hp_perempuan' => $perempuan?->no_hp ?? '',

            // ========== DATA CATIN PRIA ==========
            'nama_pria' => $pria?->nama ?? '',
            'nik_pria' => $pria?->nik ?? '',
            'pekerjaan_pria' => $pria?->pekerjaan ?? '',
            'tgl_lahir_pria' => $pria?->tgl_lahir ?? '',
            'usia_pria' => $pria?->usia ?? null,
            'hp_pria' => $pria?->no_hp ?? '',

            // ========== DATA PERNIKAHAN ==========
            'tgl_rencana_menikah' => $bride->tgl_rencana_menikah ?? '',
            'rencana_tinggal' => $bride->rencana_tinggal ?? '',

            // ========== DATA PENDAMPINGAN ==========
            'dampingan_ke' => $pendampingan?->dampingan_ke ?? '',
            'tgl_pendampingan' => $pendampingan?->tgl_pendampingan ?? '',
            'bb' => $pendampingan?->bb ?? null,
            'tb' => $pendampingan?->tb ?? null,
            'lila' => $pendampingan?->lila ?? null,
            'hb' => $pendampingan?->hb ?? null,
            'imt' => $pendampingan?->imt ?? null,

            // ========== STATUS DAN KONDISI CATIN ==========
            'status_hb' => $pendampingan?->anemia ?? '',
            'status_gizi' => $pendampingan?->kek ?? '',
            'catin_terpapar_rokok' => $pendampingan?->terpapar_rokok ?? '',
            //'catin_ttd' => $pendampingan?->catin_ttd ?? '',
            'punya_riwayat_penyakit' => $pendampingan?->riwayat_penyakit ? 'ya' : 'tidak',
            'riwayat_penyakit' => $pendampingan?->ket_riwayat_penyakit ?? '',

            // ========== FASILITAS DAN EDUKASI ==========
            'fasilitas_rujukan' => $pendampingan?->punya_jaminan ?? '',
            'edukasi' => $pendampingan?->keluarga_teredukasi ?? '',
            'pmt' => $pendampingan?->mendapatkan_bantuan ?? '',
        ];

        return response()->json($data);
    }

    // =============================
    // UPDATE DATA CATIN
    // =============================
    public function update(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {

            $bride = Bride::find($id);
            if (!$bride) {
                return response()->json(['message' => 'Data pernikahan tidak ditemukan'], 404);
            }

            // ambil data catin perempuan & pria lewat relasi
            $catinPerempuan = Catin::find($bride->id_catin);
            $catinPria = $catinPerempuan ? Catin::find($catinPerempuan->id_pasangan) : null;

            // hitung flag pending
            $isPending_perempuan = empty($request->input('nik_perempuan')) ? 1 : 0;
            $isPending_pria = empty($request->input('nik_pria')) ? 1 : 0;
            $isPending = ($isPending_perempuan == 1 || $isPending_pria == 1) ? 1 : 0;

            // 🔹 Update pernikahan
            $bride->update([
                'tgl_rencana_menikah' => $request->tgl_rencana_menikah,
                'rencana_tinggal' => $request->rencana_tinggal,
                'catatan' => $request->catatan,
                'is_pending' => $isPending,
            ]);

            // 🔹 Update catin perempuan
            if ($catinPerempuan) {
                $catinPerempuan->update([
                    'nama' => $request->nama_perempuan,
                    'nik' => $request->nik_perempuan,
                    'pekerjaan' => $request->pekerjaan_perempuan,
                    'tgl_lahir' => $request->tgl_lahir_perempuan,
                    'usia' => $request->usia_perempuan,
                    'no_hp' => $request->hp_perempuan,
                    'is_pending' => $isPending_perempuan,
                ]);
            }

            // 🔹 Update catin pria
            if ($catinPria) {
                $catinPria->update([
                    'nama' => $request->nama_pria,
                    'nik' => $request->nik_pria,
                    'pekerjaan' => $request->pekerjaan_pria,
                    'tgl_lahir' => $request->tgl_lahir_pria,
                    'usia' => $request->usia_pria,
                    'no_hp' => $request->hp_pria,
                    'is_pending' => $isPending_pria,
                ]);
            }

            if ($catinPerempuan && $catinPria) {
                $catinPerempuan->update(['id_pasangan' => $catinPria->id]);
                $catinPria->update(['id_pasangan' => $catinPerempuan->id]);
            }


            // 🔹 Update atau buat pendampingan (berdasarkan id_subjek = id catin perempuan)
            $pendampingan = Pendampingan::firstOrNew([
                'id_subjek' => $catinPerempuan?->id,
                'jenis' => 'Calon Pengantin',
            ]);

            $pendampingan->fill([
                'jenis' => 'Calon Pengantin',
                'tgl_pendampingan' => $request->tgl_pendampingan,
                'dampingan_ke' => $isPending ? 1 : ($request->input('dampingan_ke', 1)),
                'catatan' => $request->catatan,
                'bb' => $request->bb,
                'tb' => $request->tb,
                'lila' => $request->lila,
                'hb' => $request->hb,
                'usia' => $request->usia_perempuan,
                'anemia' => $request->status_hb,
                'kek' => $request->status_gizi,
                'terpapar_rokok' => $request->catin_terpapar_rokok,
                'punya_jaminan' => $request->fasilitas_rujukan,
                'keluarga_teredukasi' => $request->edukasi,
                'mendapatkan_bantuan' => $request->pmt,
                'riwayat_penyakit' => $request->punya_riwayat_penyakit,
                'ket_riwayat_penyakit' => $request->riwayat_penyakit,
                'id_petugas' => Auth::id(),
                'is_pending' => $isPending,
                'id_wilayah' => $request->id_wilayah,
                'rw' => $request->rw,
                'rt' => $request->rt,

            ]);
            $pendampingan->save();

            // 🔹 Logging aktivitas
            Log::create([
                'id_user'  => Auth::id(),
                'context'  => 'calon pengantin',
                'activity' => 'update',
                'timestamp'=> now(),
            ]);

            return response()->json([
                'message' => 'Data pernikahan berhasil diperbarui',
                'data' => [
                    'pernikahan' => $bride,
                    'catin_perempuan' => $catinPerempuan,
                    'catin_pria' => $catinPria,
                    'pendampingan' => $pendampingan
                ]
            ]);
        });
    }

}

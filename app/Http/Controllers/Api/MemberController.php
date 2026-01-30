<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Cadre;
use App\Models\TPK;
use App\Models\DampinganKeluarga;
use App\Models\User;
use App\Models\Log;
use Illuminate\Support\Facades\Auth;

class MemberController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        //dd($user);
        $cadres = Cadre::with(['tpk', 'user']);
        if ($user->role != 'SUPER ADMIN') {

            $cadres->wherehas('user', function ($q) use ($user) {
                $q->where('id_wilayah', $user->id_wilayah);
            });
        }
        $cadres = $cadres->get();

        $data = $cadres->map(function ($cadre) {
            return [
                'id'            => $cadre->id,
                'no_tpk'        => $cadre->tpk->no_tpk ?? null,
                'nama'          => $cadre->user->name ?? null,
                'action'        => null,
            ];
        });

        \App\Models\Log::create([
            'id_user'  => \Auth::id(),
            'context'  => 'Anggota TPK',
            'activity' => 'view',
            'timestamp'=> now(),
        ]);

        return response()->json($data);
    }

    public function store(Request $request)
    {
        // simpan wilayah
        $wilayah = \App\Models\Wilayah::firstOrCreate([
            'provinsi' => $request->provinsi,
            'kota' => $request->kota,
            'kecamatan' => $request->kecamatan,
            'kelurahan' => $request->kelurahan,
        ]);

        // simpan TPK
        $tpk = \App\Models\TPK::firstOrCreate([
            'no_tpk' => $request->no_tpk ?: null,
            'id_wilayah' => $wilayah->id,
        ]);

        Log::create([
            'id_user'  => Auth::id(),
            'context'  => 'Anggota TPK',
            'activity' => 'store',
            'timestamp'=> now(),
        ]);

        return response()->json([
            'message' => 'Cadre berhasil ditambahkan'
        ]);
    }

    public function getTPK()
    {
        $tpk = TPK::select('no_tpk')
            ->distinct()
            ->orderBy('no_tpk')
            ->get();

        return response()->json($tpk);
    }

    public function getUser()
    {
        $user = User::select('nik','name')
            ->where('is_pending',0)
            ->distinct()
            ->orderBy('nik')
            ->get();

        return response()->json($user);
    }

    public function assign(Request $request)
    {
        $id = $request->id;
        $no_tpk = $request->no_tpk === '__new__' ? $request->no_tpk_new : $request->no_tpk;

        $cadre = Cadre::findOrFail($id);
        $user = $cadre->user()->where('nik', $request->nik)->first();
        //$tpk = TPK::firstOrCreate('no_tpk',$no_tpk)->first();
        $wilayah = \App\Models\Wilayah::firstOrCreate([
            'provinsi' => $request->provinsi,
            'kota' => $request->kota,
            'kecamatan' => $request->kecamatan,
            'kelurahan' => $request->kelurahan,
        ]);

        $tpk = TPK::firstOrCreate([
            'no_tpk' => $no_tpk,
            'id_wilayah' => $wilayah->id,
        ]);

        $cadre->update([
            'id_tpk' => $tpk->id,
            'id_user' => $user->id,
            'status' => 'Kader',
        ]);

        Log::create([
            'id_user'  => Auth::id(),
            'context'  => 'Anggota TPK',
            'activity' => 'assign',
            'timestamp'=> now(),
        ]);

        return response()->json([
            'message' => 'Anggota berhasil ditambahkan'
        ]);
    }

    public function memberTPK($no_tpk = null)
    {

        // 🔥 normalize string "null"/"undefined"
        if ($no_tpk === 'null' || $no_tpk === 'undefined' || $no_tpk === '') {
            $no_tpk = null;
        }

        $query = Cadre::with(['tpk', 'user', 'posyandu.wilayah']);

        if ($no_tpk !== null) {
            // hanya kader dengan no_tpk tsb
            $query->whereHas('tpk', function ($q) use ($no_tpk) {
                $q->where('no_tpk', $no_tpk);
            });
        } else {
            // hanya non-kader
            $query->whereNull('id_tpk');
        }

        $cadre = $query->get();

        $data = $cadre->map(function ($c) {
            return [
                'id'            => $c->id,
                'no_tpk'        => $c->tpk->no_tpk ?? null,
                'nama'          => $c->user->name ?? null,
                'nik'           => $c->user->nik ?? null,
                'status'        => $c->status ?? null,
                'phone'         => $c->user->phone ?? null,
                'email'         => $c->user->email ?? null,
                'role'          => $c->user->role ?? null,
                'unit_posyandu' => $c->posyandu->nama_posyandu ?? null,
                'provinsi'      => $c->posyandu->wilayah->provinsi ?? null,
                'kota'          => $c->posyandu->wilayah->kota ?? null,
                'kecamatan'     => $c->posyandu->wilayah->kecamatan ?? null,
                'kelurahan'     => $c->posyandu->wilayah->kelurahan ?? null,
            ];
        });

        //dd($data);

        return response()->json($data);
    }


    public function family($id)
    {
        $dampingan = DampinganKeluarga::with([
                'keluarga.kepala',
                'pregnancy',
                'catin',
                'anak'
            ])
            ->where('id_tpk', $id)
            ->get();
        //dd($dampingan);
        $data = $dampingan->map(fn ($d) => [
            'id' => $d->id,
            'jenis' => $d->jenis,
            'no_kk' => $d->keluarga?->no_kk ?? $d->catin?->nik_perempuan,
            'rt' => $d->keluarga?->rt ?? $d->catin?->rt,
            'rw' => $d->keluarga?->rw ?? $d->catin?->rw,
            'kepala_keluarga' => $d->keluarga?->kepala->nama ?? $d->catin?->nama_perempuan,
            'id_pendampingan' => $d->id_pendampingan,

            'sasaran' =>
                $d->anak?->nama_anak
                ?? $d->pregnancy?->nama_ibu
                ?? $d->catin?->nama_perempuan,

            'tgl_pendampingan' =>
                $d->anak?->tgl_pendampingan
                ?? \Carbon\Carbon::parse($d->pregnancy?->tanggal_pendampingan)?->format('Y-m-d')
                ?? \Carbon\Carbon::parse($d->catin?->tanggal_pendampingan)?->format('Y-m-d'),
            'anak' => $d->anak,
            'bumil' => $d->pregnancy,
            'catin' => $d->catin,

        ]);

        return response()->json($data);
    }

}

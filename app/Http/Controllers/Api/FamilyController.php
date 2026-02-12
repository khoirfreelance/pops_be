<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use App\Models\Keluarga;
use App\Models\Wilayah;
use App\Models\AnggotaKeluarga;
use Illuminate\Support\Facades\Auth;
use App\Models\Log;
use App\Models\User;
use App\Models\Cadre;
use Illuminate\Http\Request;
use Carbon\Carbon;

class FamilyController extends Controller
{
    protected array $wilayahUser = [];
    protected string $posyanduUser = '';
    protected string $posyanduUserID = '';
    protected string $rtPosyandu = '';
    protected string $rwPosyandu = '';

    public function index()
    {

        $user = Auth::user();

        $keluargas = Keluarga::with('kepala', 'wilayah');
        /* if ($user->role != 'SUPER ADMIN') {

            $keluargas->wherehas('wilayah', function ($q) use ($user) {
                $q->where('id', $user->id_wilayah);
            });
        } */

        switch ($user->role) {
            case 'SUPER ADMIN':
                /* $keluargas->wherehas('wilayah', function ($q) use ($user) {
                    $q->where('id', $user->id_wilayah);
                }); */
                break;
            case 'ADMIN':
                $keluargas->wherehas('wilayah', function ($q) use ($user) {
                    $q->where('id', $user->id_wilayah);
                });
                break;
        }

        $keluargas = $keluargas->get();

        $data = $keluargas->map(function ($k) {
            return [
                'id'      => $k->id,
                'no_kk'   => $k->no_kk,
                'alamat'  => $k->alamat,
                'rt'      => $k->rt,
                'rw'      => $k->rw,

                // data kepala keluarga langsung diurai
                'nama_kepala'     => $k->kepala ? $k->kepala->nama : null,
                'nik_kepala'      => $k->kepala ? $k->kepala->nik : null,
                'tgl_lahir'       => $k->kepala && $k->kepala->tanggal_lahir
                                    ? Carbon::parse($k->kepala->tanggal_lahir)->format('Y-m-d')
                                    : null,

                'pendidikan'      => $k->kepala ? $k->kepala->pendidikan : null,
                'status_hubungan' => $k->kepala ? $k->kepala->status_hubungan : null,
                'is_pending' => $k->kepala ? $k->kepala->is_pending : null,
                // ✅ WILAYAH
                'wilayah' => $k->wilayah ? [
                    'id'        => $k->wilayah->id,
                    'provinsi'  => $k->wilayah->provinsi,
                    'kota'      => $k->wilayah->kota,
                    'kecamatan' => $k->wilayah->kecamatan,
                    'kelurahan' => $k->wilayah->kelurahan,
                ] : null,
                // jumlah anggota keluarga
                'jml_anggota'     => $k->anggota ? $k->anggota
                                        ->where('is_pending', 0)
                                        ->count() : 0,
            ];
        });

        return response()->json($data->values());
    }

    public function store(Request $request)
    {
        // cek apakah no_kk sudah ada
        $keluarga = Keluarga::where('no_kk', $request->no_kk)->first();

        // cek is_pending untuk keluarga
        $isPendingKeluarga = empty($request->no_kk) ? 1 : 0;

        // cek is_pending untuk anggota
        $isPendingAnggota = empty($request->nik) ? 1 : 0;
        $status = $isPendingKeluarga || $isPendingAnggota == 0 ? 'Pending':'Saved';

        if (!$keluarga) {
            // simpan wilayah
            $wilayah = \App\Models\Wilayah::firstOrCreate([
                'provinsi' => $this->normalizeText($request->provinsi),
                'kota' =>  $this->normalizeText($request->kota),
                'kecamatan' =>  $this->normalizeText($request->kecamatan),
                'kelurahan' =>  $this->normalizeText($request->kelurahan),
            ]);

            // buat keluarga baru
            $keluarga = Keluarga::create([
                'no_kk' => $request->no_kk,
                'alamat' => $request->alamat,
                'rt' => $request->rt,
                'rw' => $request->rw,
                'id_wilayah' => $wilayah->id,
                'is_pending' => $isPendingKeluarga,
            ]);
        }

        // tambah anggota keluarga (bisa kepala/istri/anak dsb.)
        $keluarga->anggota()->create([
            'nik' => $request->nik,
            'no_kk' => $request->no_kk,
            'nama' =>  $this->normalizeText($request->nama),
            'tempat_lahir' =>  $this->normalizeText($request->tempat_lahir),
            'tanggal_lahir' => $request->tgl_lahir,
            'jenis_kelamin' =>  $this->normalizeText($request->gender),
            'pendidikan' =>  $this->normalizeText($request->pendidikan),
            'pekerjaan' =>  $this->normalizeText($request->pekerjaan),
            'status_hubungan' =>  $this->normalizeText($request->status_hubungan),
            'agama' =>  $this->normalizeText($request->agama),
            'status_perkawinan' =>  $this->normalizeText($request->status_perkawinan),
            'kewarganegaraan' =>  $this->normalizeText($request->kewarganegaraan),
            'is_pending' => $isPendingAnggota,
        ]);

        Log::create([
            'id_user'  => Auth::id(),
            'context'  => 'keluarga',
            'activity' => 'store',
            'timestamp'=> now(),
        ]);

        return response()->json(['message' => 'Data berhasil disimpan', 'status' => $status]);
    }

    public function checkNoKK(Request $request)
    {
        $keluarga = Keluarga::with(['kepala', 'anggota', 'wilayah'])
            ->where('no_kk', $request->no_kk)
            ->first();

        if ($keluarga) {
            return response()->json([
                'exists' => true,
                'keluarga' => [
                    'id' => $keluarga->id,
                    'no_kk' => $keluarga->no_kk,
                    'alamat' => $keluarga->alamat,
                    'rt' => $keluarga->rt,
                    'rw' => $keluarga->rw,
                    'id_wilayah' => $keluarga->id_wilayah,
                    'kepala' => $keluarga->kepala?->nama,
                    // expand wilayah
                    'provinsi'  => $keluarga->wilayah?->provinsi,
                    'kota'      => $keluarga->wilayah?->kota,
                    'kecamatan' => $keluarga->wilayah?->kecamatan,
                    'kelurahan' => $keluarga->wilayah?->kelurahan,
                ],
            ]);
        }

        return response()->json(['exists' => false]);
    }

    public function detail($id)
    {
        $keluarga = Keluarga::with(['wilayah', 'anggota'])
                    ->where('id', $id)
                    ->firstOrFail();

        return response()->json([
            'id'       => $keluarga->id,
            'no_kk'    => $keluarga->no_kk,
            'alamat'   => $keluarga->alamat,
            'rt'       => $keluarga->rt,
            'rw'       => $keluarga->rw,

            // wilayah
            'wilayah' => [
                'provinsi'  => $keluarga->wilayah?->provinsi,
                'kota'      => $keluarga->wilayah?->kota,
                'kecamatan' => $keluarga->wilayah?->kecamatan,
                'kelurahan' => $keluarga->wilayah?->kelurahan,
            ],

            // anggota keluarga (kepala + lainnya)
            'anggota' => $keluarga->anggota->map(function ($a) {
                return [
                    'id'               => $a->id,
                    'nik'              => $a->nik,
                    'nama'             => $a->nama,
                    'tempat_lahir'     => $a->tempat_lahir,
                    'tanggal_lahir'    => $a->tanggal_lahir,
                    'jenis_kelamin'    => $a->jenis_kelamin,
                    'pendidikan'       => $a->pendidikan,
                    'pekerjaan'        => $a->pekerjaan,
                    'status_hubungan'  => $a->status_hubungan,
                    'agama'            => $a->agama,
                    'status_perkawinan'=> $a->status_perkawinan,
                    'kewarganegaraan'  => $a->kewarganegaraan,
                ];
            }),
        ]);
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

    private function normalizeText($value)
    {
        return $value ? strtoupper(trim($value)) : null;
    }

    private function headerMap(): array
    {
        return [
            'no. kk' => 'no_kk',
            'alamat' => 'alamat',
            'rt' => 'rt',
            'rw' => 'rw',
            'provinsi' => 'provinsi',
            'kota/kabupaten' => 'kota',
            'kecamatan' => 'kecamatan',
            'kelurahan/desa' => 'kelurahan',
            'nik' => 'nik',
            'nama' => 'nama',
            'tgl lahir' => 'tanggal_lahir',
            'jenis kelamin (l/p)' => 'jenis_kelamin',
            'status hubungan (kepala keluarga/ibu/anak/saudara/dll)' => 'status_hubungan',
            'pekerjaan' => 'pekerjaan',
            'agama' => 'agama',
            'status perkawinan (kawin/belum kawin)' => 'status_perkawinan',
            'kewarganegaraan (wni/wna)' => 'kewarganegaraan',
        ];
    }

    private function validateEnum($value, array $allowed, $field, $rowNumber)
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = strtoupper(trim($value));

        if (!in_array($value, $allowed)) {
            throw new \Exception(
                "Baris {$rowNumber}: nilai <strong>{$value}</strong> tidak valid untuk kolom <strong>{$field}</strong>.<br>"
                . "Nilai yang diperbolehkan: <strong>" . implode(', ', $allowed) . "</strong>"
            );
        }

        return $value;
    }

    private function allowedEnums()
    {
        return [
            'jenis_kelamin' => ['L', 'P'],
            'status_hubungan' => [
                'KEPALA KELUARGA',
                'ISTRI',
                'ANAK',
                'FAMILI LAIN',
            ],
            'status_perkawinan' => [
                'KAWIN',
                'BELUM KAWIN',
                'CERAI HIDUP',
                'CERAI MATI',
            ],
            'kewarganegaraan' => ['WNI', 'WNA'],
            'agama' => [
                'ISLAM',
                'KRISTEN',
                'KATOLIK',
                'HINDU',
                'BUDDHA',
                'KONGHUCU',
                'LAINNYA'
            ],
        ];
    }

    public function import(Request $request)
    {
        // =========================
        // VALIDASI FILE
        // =========================
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

        $file = $request->file('file');
        $path = $file->getRealPath();

        // =========================
        // BACA CSV
        // =========================
        $delimiter = $this->detectDelimiter($path);
        $rows = array_map(
            fn ($row) => str_getcsv($row, $delimiter),
            file($path)
        );

        if (count($rows) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'File CSV kosong atau hanya berisi header.'
            ], 422);
        }

        // =========================
        // HEADER MAPPING (FINAL)
        // =========================
        $rawHeader = $rows[0];
        $map = $this->headerMap();
        $header = [];

        foreach ($rawHeader as $h) {
            // NORMALISASI HEADER CSV
            $clean = trim($h);
            $clean = preg_replace('/^\xEF\xBB\xBF/', '', $clean); // hapus BOM
            $clean = strtolower($clean);

            if (!isset($map[$clean])) {
                throw new \Exception(
                    "Header CSV <strong>{$h}</strong> tidak dikenali."
                );
            }

            // ⬇️ PAKAI HASIL NORMALISASI
            $header[] = $map[$clean];
        }

        unset($rows[0]);

        // =========================
        // ENUM CONFIG
        // =========================
        $enums = $this->allowedEnums();
        $rowNumber = 1;
        $saved = 0;
        $pending = 0;

        DB::beginTransaction();
        try {
            foreach ($rows as $row) {

                // =========================
                // INTRO
                // =========================
                $wilayahData = $this->resolveWilayahFromRow($row);

                $rowNumber++;

                if (count($row) !== count($header)) {
                    throw new \Exception(
                        "Baris {$rowNumber}: jumlah kolom tidak sesuai header."
                    );
                }

                $row = array_combine($header, $row);
                //dd($row);

                // =====================
                // NORMALISASI DATA
                // =====================
                $row['no_kk'] = $this->normalizeNik($row['no_kk'] ?? null);
                $row['nik'] = $this->normalizeNik($row['nik'] ?? null);
                $row['nama'] = $this->normalizeText($row['nama'] ?? null);
                $row['alamat'] = $row['alamat'] ?? null;

                $row['tanggal_lahir'] = !empty($row['tanggal_lahir'])
                    ? $this->convertDate($row['tanggal_lahir'])
                    : null;

                // =====================
                // ENUM VALIDATION
                // =====================
                foreach ($enums as $field => $allowed) {
                    if (array_key_exists($field, $row)) {
                        $row[$field] = $this->validateEnum(
                            $row[$field],
                            $allowed,
                            strtoupper(str_replace('_', ' ', $field)),
                            $rowNumber
                        );
                    }
                }

                // =========================
                // 5. Wilayah & Posyandu
                // =========================
                /* $wilayah = Wilayah::firstOrCreate([
                    'provinsi'  => $this->normalizeText($row['provinsi']),
                    'kota'      => $this->normalizeText($row['kota']),
                    'kecamatan' => $this->normalizeText($row['kecamatan']),
                    'kelurahan' => $this->normalizeText($row['kelurahan']),
                ]); */

                // =====================
                // KELUARGA
                // =====================
                $isPendingKeluarga = empty($row['no_kk']) ? 1 : 0;

                $keluarga = Keluarga::firstOrCreate(
                    ['no_kk' => $row['no_kk']],
                    [
                        'alamat' => $row['alamat'],
                        'rt' => $row['rt'] ?? null,
                        'rw' => $row['rw'] ?? null,
                        'id_wilayah' => $wilayahData['id'] ?? null,
                        'is_pending' => $isPendingKeluarga,
                    ]
                );

                // =====================
                // ANGGOTA KELUARGA
                // =====================
                $isPendingAnggota = empty($row['nik']) ? 1 : 0;

                AnggotaKeluarga::updateOrCreate(
                    ['nik' => $row['nik']],
                    [
                        'id_keluarga' => $keluarga->id,
                        'nama' => $this->normalizeText($row['nama']),
                        'tempat_lahir' => $row['kota'],
                        'tanggal_lahir' => $row['tanggal_lahir'],
                        'jenis_kelamin' => $row['jenis_kelamin'],
                        'status_hubungan' => $this->normalizeText($row['status_hubungan']),
                        'agama' => $this->normalizeText($row['agama']),
                        'pekerjaan' => $this->normalizeText($row['pekerjaan']),
                        'status_perkawinan' => $this->normalizeText($row['status_perkawinan']),
                        'kewarganegaraan' => $this->normalizeText($row['kewarganegaraan']),
                        'is_pending' => $isPendingAnggota,
                    ]
                );

                ($isPendingKeluarga || $isPendingAnggota) ? $pending++ : $saved++;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                //'data'    => $rows,
                'message' => 'Import CSV berhasil.',
                'summary' => [
                    'saved' => $saved,
                    'pending' => $pending,
                    'total' => $saved + $pending,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal import data, silahkan check dan bandingkan kembali format csv dengan contoh yang diberikan.',
                'row' => $rowNumber,
                'error' => $e->getMessage()
            ], 422);
        }
    }

    protected function loadWilayahUser(): void
    {
        $user = Auth::user();

        if (!$user || !$user->id_wilayah) {
            $this->wilayahUser = [
                'id' => null,
                'provinsi' => null,
                'kota' => null,
                'kecamatan' => null,
                'kelurahan' => null,
            ];
            return;
        }

        $zona = Wilayah::find($user->id_wilayah);

        $this->wilayahUser = [
            'id' => $zona->id ?? null,
            'provinsi' => $zona->provinsi ?? null,
            'kota' => $zona->kota ?? null,
            'kecamatan' => $zona->kecamatan ?? null,
            'kelurahan' => $zona->kelurahan ?? null,
        ];
    }
    protected function resolveWilayahFromRow(array $row): array
    {
        $user = Auth::user();

        // =========================
        // BUKAN SUPER ADMIN
        // =========================
        if (!$user || $user->role !== 'Super Admin') {
            return [
                'id'        => $this->wilayahUser['id'],
                'provinsi'  => $this->wilayahUser['provinsi'],
                'kota'      => $this->wilayahUser['kota'],
                'kecamatan' => $this->wilayahUser['kecamatan'],
                'kelurahan' => $this->wilayahUser['kelurahan'],
            ];
        }

        // =========================
        // SUPER ADMIN
        // =========================
        $prov = $this->normalizeWilayahKey($row[4]);
        $kota = $this->normalizeWilayahKey($row[5]);
        $kec  = $this->normalizeWilayahKey($row[6]);
        $kel  = $this->normalizeWilayahKey($row[7]);

        $wilayah = Wilayah::where('provinsi', $prov)
            ->where('kota', $kota)
            ->where('kecamatan', $kec)
            ->where('kelurahan', $kel)
            ->first();

        if ($wilayah) {
            return [
                'id'        => $wilayah->id,
                'provinsi'  => $wilayah->provinsi,
                'kota'      => $wilayah->kota,
                'kecamatan' => $wilayah->kecamatan,
                'kelurahan' => $wilayah->kelurahan,
            ];
        }

        // =========================
        // SUPER ADMIN → CREATE BARU
        // =========================
        $wilayah = Wilayah::create([
            'provinsi'  => $this->normalizeText($row['provinsi']),
            'kota'      => $this->normalizeText($row['kota']),
            'kecamatan' => $this->normalizeText($row['kecamatan']),
            'kelurahan' => $this->normalizeText($row['kelurahan']),
        ]);

        return [
            'id'        => $wilayah->id,
            'provinsi'  => $wilayah->provinsi,
            'kota'      => $wilayah->kota,
            'kecamatan' => $wilayah->kecamatan,
            'kelurahan' => $wilayah->kelurahan,
        ];
    }

    protected function normalizeWilayahKey(?string $value): ?string
    {
        if (!$value) return null;

        $value = strtoupper(trim($value));

        $replace = [
            'KABUPATEN ' => 'KAB ',
            'KAB. ' => 'KAB ',
            'KOTA ' => 'KOTA ',
            '  ' => ' ',
        ];

        return str_replace(array_keys($replace), array_values($replace), $value);
    }
    /* public function pendingData()
    {
        $keluargas = Keluarga::with(['kepala', 'anggota'])
            ->where('is_pending', 1)
            ->orWhereHas('anggota', function ($q) {
                $q->where('is_pending', 1);
            })
            ->get();

        $data = collect();

        foreach ($keluargas as $k) {
            if ($k->is_pending) {
                // === tampilkan kepala keluarga ===
                $data->push([
                    'id'      => $k->id,
                    'no_kk'   => $k->no_kk,
                    'alamat'  => $k->alamat,
                    'rt'      => $k->rt,
                    'rw'      => $k->rw,

                    'nama'    => $k->kepala?->nama,
                    'nik'     => $k->kepala?->nik,
                    'tgl_lahir' => $k->kepala && $k->kepala->tanggal_lahir
                        ? $k->kepala->tempat_lahir.', '.Carbon::parse($k->kepala->tanggal_lahir)->format('d-m-Y')
                        : null,
                    'pendidikan'      => $k->kepala?->pendidikan,
                    'status_hubungan' => $k->kepala?->status_hubungan,
                    'is_pending'      => 1,
                    'tipe'            => 'keluarga',
                ]);
            } else {
                // === tampilkan anggota pending ===
                foreach ($k->anggota->where('is_pending', 1) as $a) {
                    $data->push([
                        'id'      => $a->id,
                        'no_kk'   => $k->no_kk,
                        'alamat'  => $k->alamat,
                        'rt'      => $k->rt,
                        'rw'      => $k->rw,

                        'nama'    => $a->nama,
                        'nik'     => $a->nik,
                        'tgl_lahir' => $a->tanggal_lahir
                            ? $a->tempat_lahir.', '.Carbon::parse($a->tanggal_lahir)->format('d-m-Y')
                            : null,
                        'pendidikan'      => $a->pendidikan,
                        'status_hubungan' => $a->status_hubungan,
                        'is_pending'      => 1,
                        'tipe'            => 'anggota',
                    ]);
                }
            }
        }

        return response()->json($data->values());
    }

    public function pending($id, Request $request)
    {
        $tipe = $request->query('tipe');

        if ($tipe === 'keluarga') {
            // Cari keluarga berdasarkan ID
            $keluarga = Keluarga::with(['wilayah', 'anggota' => function ($q) {
                    $q->where('status_hubungan', 'Kepala Keluarga');
                }])
                ->find($id);

            if (!$keluarga) {
                return response()->json(['message' => 'Data keluarga tidak ditemukan'], 404);
            }

        } elseif ($tipe === 'anggota') {
            // Cari anggota berdasarkan ID
            $anggota = AnggotaKeluarga::with('keluarga.wilayah')
                ->where('id', $id)
                ->where('is_pending', 1)
                ->first();

            if (!$anggota) {
                return response()->json(['message' => 'Data anggota tidak ditemukan'], 404);
            }

            // Ambil keluarganya + filter anggota pending
            $keluarga = $anggota->keluarga->load(['anggota' => function ($q) {
                $q->where('is_pending', 1);
            }]);

        } else {
            return response()->json(['message' => 'Tipe tidak valid'], 400);
        }

        return response()->json([
            'tipe'     => $tipe,
            'id'       => $keluarga->id,
            'no_kk'    => $keluarga->no_kk,
            'alamat'   => $keluarga->alamat,
            'rt'       => $keluarga->rt,
            'rw'       => $keluarga->rw,

            // wilayah
            'provinsi'  => $keluarga->wilayah?->provinsi,
            'kota'      => $keluarga->wilayah?->kota,
            'kecamatan' => $keluarga->wilayah?->kecamatan,
            'kelurahan' => $keluarga->wilayah?->kelurahan,

            // anggota keluarga pending
            'anggota' => $keluarga->anggota->map(function ($a) {
                return [
                    'id'               => $a->id,
                    'nik'              => $a->nik,
                    'nama'             => $a->nama,
                    'tempat_lahir'     => $a->tempat_lahir,
                    'tanggal_lahir'    => $a->tanggal_lahir,
                    'jenis_kelamin'    => $a->jenis_kelamin,
                    'pendidikan'       => $a->pendidikan,
                    'pekerjaan'        => $a->pekerjaan,
                    'status_hubungan'  => $a->status_hubungan,
                    'agama'            => $a->agama,
                    'status_perkawinan'=> $a->status_perkawinan,
                    'kewarganegaraan'  => $a->kewarganegaraan,
                ];
            }),
        ]);
    } */

    public function update(Request $request, $id)
    {
        //dd($request);
        if ($request->tipe === 'anggota') {
            $isPendingAnggota = empty($request->nik) ? 1 : 0;

            $anggota = AnggotaKeluarga::findOrFail($id);
            //dd($request);
            $anggota->update([
                'nik'               => $request->nik,
                'nama'              => $this->normalizeText($request->nama),
                'tempat_lahir'      => $this->normalizeText($request->tempat_lahir),
                'tanggal_lahir'     => $this->convertDate($request->tgl_lahir),
                'jenis_kelamin'     => $request->gender,
                'status_hubungan'   => $this->normalizeText($request->status_hubungan),
                'agama'             => $this->normalizeText($request->agama),
                'pendidikan'        => $this->normalizeText($request->pendidikan),
                'pekerjaan'         => $this->normalizeText($request->pekerjaan),
                'status_perkawinan' => $this->normalizeText($request->status_perkawinan),
                'kewarganegaraan'   => $this->normalizeText($request->kewarganegaraan),
                'is_pending'        => $isPendingAnggota
            ]);
        }else {
            $keluarga = Keluarga::findOrFail($id);

            // cek is_pending keluarga
            $isPendingKeluarga = empty($request->no_kk) ? 1 : 0;

            // simpan wilayah (hanya kalau ada input wilayah)
            $wilayah = \App\Models\Wilayah::firstOrCreate([
                'provinsi'  => $request->provinsi,
                'kota'      => $request->kota,
                'kecamatan' => $request->kecamatan,
                'kelurahan' => $request->kelurahan,
            ]);

            // === 1. Update keluarga dulu ===
            $keluarga->update([
                'no_kk'      => $request->no_kk,
                'alamat'     => $request->alamat,
                'rt'         => $request->rt,
                'rw'         => $request->rw,
                'id_wilayah' => $wilayah->id,
                'is_pending' => $isPendingKeluarga,
            ]);
        }

        Log::create([
            'id_user'  => Auth::id(),
            'context'  => $request->tipe === 'anggota' ? 'Anggota Keluarga' :'Keluarga',
            'activity' => 'update',
            'timestamp'=> now(),
        ]);

        return response()->json([
            'message' => 'Data '.$request->no_kk || $request->nama.' berhasil diubah'
        ]);

        //dd($isPendingKeluarga);
        /*


        if ($tipe === 'keluarga') {


            // === 2. Kalau ada anggota yg diupdate ===
            if ($request->anggota_id) {
                $isPendingAnggota = empty($request->nik) ? 1 : 0;
                $anggota = $keluarga->anggota()->where('id', $request->anggota_id)->first();
                if ($anggota) {
                    $anggota->update([
                        'nik'              => $request->nik,
                        'nama'             => $request->nama,
                        'tempat_lahir'     => $request->tempat_lahir,
                        'tanggal_lahir'    => $request->tanggal_lahir,
                        'jenis_kelamin'    => $request->jenis_kelamin,
                        'pendidikan'       => $request->pendidikan,
                        'pekerjaan'        => $request->pekerjaan,
                        'status_hubungan'  => $request->status_hubungan,
                        'agama'            => $request->agama,
                        'status_perkawinan'=> $request->status_perkawinan,
                        'kewarganegaraan'  => $request->kewarganegaraan,
                        'is_pending'       => $isPendingAnggota,
                    ]);
                }
            }

        } elseif ($tipe === 'anggota') {
            // === 1. Update anggota dulu ===
            if ($request->anggota_id) {
                $isPendingAnggota = empty($request->nik) ? 1 : 0;
                $anggota = $keluarga->anggota()->where('id', $request->anggota_id)->first();
                if ($anggota) {
                    $anggota->update([
                        'nik'              => $request->nik,
                        'nama'             => $request->nama,
                        'tempat_lahir'     => $request->tempat_lahir,
                        'tanggal_lahir'    => $request->tanggal_lahir,
                        'jenis_kelamin'    => $request->jenis_kelamin,
                        'pendidikan'       => $request->pendidikan,
                        'pekerjaan'        => $request->pekerjaan,
                        'status_hubungan'  => $request->status_hubungan,
                        'agama'            => $request->agama,
                        'status_perkawinan'=> $request->status_perkawinan,
                        'kewarganegaraan'  => $request->kewarganegaraan,
                        'is_pending'       => $isPendingAnggota,
                    ]);
                }
            }

            // === 2. Kalau ada data keluarga yg ikut diupdate ===
            if ($request->filled(['no_kk', 'alamat'])) {
                $keluarga->update([
                    'no_kk'      => $request->no_kk,
                    'alamat'     => $request->alamat,
                    'rt'         => $request->rt,
                    'rw'         => $request->rw,
                    'id_wilayah' => $wilayah->id,
                    'is_pending' => $isPendingKeluarga,
                ]);
            }
        }

        */
    }

    public function delete($id)
    {
        try {
            DB::beginTransaction();

            $deleted = false;


            // Keluarga
            if (Keluarga::where('id', $id)->exists()) {
                Keluarga::where('id', $id)->delete();
                $deleted = true;
            }

            // Anggota Keluarga
            if (AnggotaKeluarga::where('id_keluarga', $id)->exists()) {
                AnggotaKeluarga::where('id_keluarga', $id)->delete();
                $deleted = true;
            }

            if (!$deleted) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Data keluarga tidak ditemukan.'
                ], 404);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Data keluarga berhasil dihapus.'
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

    public function delAnggota($id)
    {
        try {
            DB::beginTransaction();

            $deleted = false;

            // Anggota Keluarga
            if (AnggotaKeluarga::where('id', $id)->exists()) {
                AnggotaKeluarga::where('id', $id)->delete();
                $deleted = true;
            }

            if (!$deleted) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Data keluarga tidak ditemukan.'
                ], 404);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Data keluarga berhasil dihapus.'
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

            $ids = $request->ids;

            Keluarga::whereIn('id', $ids)->delete();

            DB::commit();

            return response()->json([
                'success' => true
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Bulk delete keluarga gagal', [
                'ids' => $request->ids,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus data keluarga'
            ], 500);
        }
    }

    public function bulkDeleteAng(Request $request)
    {
        try {
            DB::beginTransaction();

            $ids = $request->ids; // array anggota IDs

            $anggota = AnggotaKeluarga::whereIn('id', $ids)->get();

            $keluargaIds = $anggota
                ->pluck('id_keluarga')
                ->filter()       // jaga-jaga null
                ->unique()
                ->values();

            // delete anggota
            AnggotaKeluarga::whereIn('id', $ids)->delete();

            // delete keluarga terkait
            if ($keluargaIds->isNotEmpty()) {
                Keluarga::whereIn('id', $keluargaIds)->delete();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'deleted_anggota' => $ids,
                'deleted_keluarga' => $keluargaIds
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Bulk delete anggota gagal', [
                'ids' => $request->ids,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus data anggota keluarga'
            ], 500);
        }
    }

}


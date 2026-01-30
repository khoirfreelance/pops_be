<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Log;

class LogController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $logs = Log::with('user');
        if ($user->role != 'SUPER ADMIN') {

            $logs->wherehas('user', function ($q) use ($user) {
                $q->where('id_wilayah', $user->id_wilayah);
            });
        }
        $logs = $logs->get();

        $data = $logs->map(function ($log) {
            return [
                'nama'      => $log->user ? $log->user->name : null,
                'nik'       => $log->user ? $log->user->nik : null,
                'context'   => $log->context,
                'activity'  => $log->activity,
                'timestamp' => $log->timestamp,
            ];
        });

        return response()->json($data->values());
    }
}

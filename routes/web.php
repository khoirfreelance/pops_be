<?php
/*
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

Route::post('/api/login', function (Request $request) {
    $credentials = $request->only('email', 'password');

    if (Auth::attempt($credentials)) {
        $user = Auth::user();
        return response()->json([
            'status' => true,
            'message' => 'Login berhasil',
            'user' => $user,
            'token' => $user->createToken('api-token')->plainTextToken, // perlu Sanctum kalau mau
        ]);
    }

    return response()->json([
        'status' => false,
        'message' => 'Email atau password salah',
    ], 401);
}); */


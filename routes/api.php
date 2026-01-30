<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConfigController;
use App\Http\Controllers\Api\FamilyController;
use App\Http\Controllers\Api\RegionController;
use App\Http\Controllers\Api\LogController;
use App\Http\Controllers\Api\CadreController;
use App\Http\Controllers\Api\PosyanduController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\BrideController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ChildrenController;
use App\Http\Controllers\Api\PregnancyController;
use App\Http\Controllers\Api\CatinController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\SliderSettingController;
use App\Http\Controllers\Api\SliderImageController;
use App\Http\Controllers\Api\FooterSettingController;
use App\Http\Controllers\Api\FooterSocialLinkController;


// Auth Endpoint
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('config', ConfigController::class)->only(['index','store']);
});

// Dashboard Endpoint
Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
Route::get('/posyandu/{id}/wilayah', [DashboardController::class, 'getPosyanduWilayah']);

// Children Endpoint
Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('children', ChildrenController::class)->only(['index','store']);
    //Route::get('/children/{nik}', [ChildrenController::class, 'show']);
    Route::delete('/children/{nik}', [ChildrenController::class, 'delete']);
    Route::put('/children/{nik}', [ChildrenController::class, 'update']);
    Route::get('/children/status', [ChildrenController::class, 'status']);

    Route::get('/children/tren', [ChildrenController::class, 'tren']);
    Route::get('/children/case', [ChildrenController::class, 'case']);
    Route::get('/children/info-boxes', [ChildrenController::class, 'infoBoxes']);
    Route::get('/children/intervensi', [ChildrenController::class, 'intervensi']);
    Route::get('/children/index_kunjungan', [ChildrenController::class, 'kunjungan']);
    Route::get('/children/ringkasan', [ChildrenController::class, 'ringkasan']);
    Route::post('/children/import_kunjungan', [ChildrenController::class, 'import_kunjungan']);
    Route::post('/children/import_pendampingan', [ChildrenController::class, 'import_pendampingan_v2']);
    Route::post('/children/import_intervensi', [ChildrenController::class, 'import_intervensi']);
    Route::get('/children/get-data', [ChildrenController::class, 'testGetData']);
    Route::get('/children/get-children-double-problem', [ChildrenController::class, 'getDataDoubleProblem']);
    Route::get('/children/{nik}', [ChildrenController::class, 'show']);
    Route::post('/children/bulk-delete', [ChildrenController::class, 'bulkDelete']);
    // ENDPOINT DETAIL SELENGKAPNYA
    Route::get('/detail-tren', [ChildrenController::class, 'detail_tren']);
    Route::get('/detail-umur', [ChildrenController::class, 'detail_umur']);
    Route::get('/detail-indikator', [ChildrenController::class, 'detail_indikator']);
});

// Family Endpoint
Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('family', FamilyController::class)->only(['index','store']);
    Route::get('/family/detail/{id}', [FamilyController::class, 'detail']);
    Route::get('/family/check', [FamilyController::class, 'checkNoKK']);
    Route::post('/family/import', [FamilyController::class, 'import']);
    Route::get('/family/pending', [FamilyController::class, 'pendingData']);
    Route::get('/family/{id}/pending', [FamilyController::class, 'pending']);
    Route::delete('/family/anggota/{id}', [FamilyController::class, 'delAnggota']);
    Route::put('/family/{id}', [FamilyController::class, 'update']);
    Route::delete('/family/{id}', [FamilyController::class, 'delete']);
    Route::post('/family/bulk-delete', [FamilyController::class, 'bulkDelete']);
    Route::post('/family/bulk-delete-anggota', [FamilyController::class, 'bulkDeleteAng']);
});

// Cadre Endpoint
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/cadre/pending', [CadreController::class, 'pendingData']);
    Route::apiResource('cadre', CadreController::class)
        ->only(['index','store','show','update'])
        ->where(['cadre' => '[0-9]+']);

    Route::put('/cadre/deactive/{id}', [CadreController::class, 'deactive']);
    Route::put('/cadre/active/{id}', [CadreController::class, 'active']);
    Route::put('/cadre/delete/{id}', [CadreController::class, 'delete']);
    Route::post('/cadre/bulk-delete', [CadreController::class, 'bulkDelete']);
});

// Posyandu Endpoint
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/posyandu', [PosyanduController::class, 'getPosyandu']);
    Route::get('/posyandu/{id_wilayah}', [PosyanduController::class, 'getByWilayah']);
});

// Member Endpoint
Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('member', MemberController::class)
        ->only(['index','store','show'])
        ->where(['member' => '[0-9]+']);
    Route::get('/member/tpk', [MemberController::class, 'getTPK']);
    Route::get('/member/user', [MemberController::class, 'getUser']);
    Route::post('/member/assign', [MemberController::class, 'assign']);
    Route::get('/member/tpk/{no_tpk?}', [MemberController::class, 'memberTPK']);
    Route::get('/member/family/{id}', [MemberController::class, 'family']);
});

// Bride Endpoint
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/bride/import', [CatinController::class, 'import']);
    Route::get('/bride/status', [CatinController::class, 'status']);
    Route::get('/bride/tren', [CatinController::class, 'tren']);
    Route::get('/bride/indikator-bulanan', [CatinController::class, 'indikatorBulanan']);
    Route::get('/bride/{nik_perempuan}', [CatinController::class, 'show']);
    Route::apiResource('bride', CatinController::class)
        ->only(['index']);
    Route::apiResource('bride', CatinController::class)->only(['index','store']);
    Route::delete('/bride/{nik}', [CatinController::class, 'delete']);
    Route::put('/bride/{nik}', [CatinController::class, 'update']);
    Route::post('/bride/bulk-delete', [CatinController::class, 'bulkDelete']);
});

// Pregnancy Endpoint
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/pregnancy/import', [PregnancyController::class, 'import']);
    Route::post('/pregnancy/import_intervensi', [PregnancyController::class, 'import_intervensi']);
    Route::get('/pregnancy', [PregnancyController::class, 'index']);
    Route::apiResource('/pregnancy', PregnancyController::class)->only(['index','store']);
    Route::delete('/pregnancy/{nik}', [PregnancyController::class, 'delete']);
    Route::put('/pregnancy/{nik}', [PregnancyController::class, 'update']);
    Route::get('/pregnancy/case', [PregnancyController::class, 'case']);
    Route::get('/pregnancy/intervensi', [PregnancyController::class, 'intervensi']);
    Route::get('/pregnancy/status', [PregnancyController::class, 'status']);
    Route::get('/pregnancy/tren', [PregnancyController::class, 'tren']);
    Route::get('/pregnancy/intervensi-summary', [PregnancyController::class, 'intervensiSummary']);
    Route::get('/pregnancy/indikator-bulanan', [PregnancyController::class, 'indikatorBulanan']);
    Route::get('/pregnancy/{nik_ibu}', [PregnancyController::class, 'show']);
    Route::post('/pregnancy/bulk-delete', [PregnancyController::class, 'bulkDelete']);
});

// Region Endpoint
//Route::apiResource('region', RegionController::class)->only(['index','store']);
Route::get('/region', [RegionController::class, 'index']);
Route::get('/region/provinsi', [RegionController::class, 'getProvinsi']);
Route::get('/region/kota', [RegionController::class, 'getKota']);
Route::get('/region/kecamatan', [RegionController::class, 'getKecamatan']);
Route::get('/region/kelurahan', [RegionController::class, 'getKelurahan']);

// Log Endpoint
Route::middleware('auth:sanctum')->get('/log', [LogController::class, 'index']);

// User Endpoint
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [CadreController::class, 'me']);
    Route::put('/user/profile', [CadreController::class, 'updateProfile']);
    Route::put('/user/change-password', [CadreController::class, 'changePassword']);
    Route::get('/user/region', [CadreController::class, 'wilayahByUser']);
});
//Route::middleware('auth:sanctum')->get('/user/region', [CadreController::class, 'wilayahByUser']);

// Home Endpoint
Route::get('/home/pregnancy', [HomeController::class, 'getBumil']);
Route::get('/home/children', [HomeController::class, 'getAnak']);
Route::get('/home/indicator', [HomeController::class, 'getIndikatorAnak']);

/* =========================
   PUBLIC (HOMEPAGE)
========================== */
Route::get('/public/slider-setting', [SliderSettingController::class, 'show']);
Route::get('/public/slider-images', [SliderImageController::class, 'index']);
Route::get('/public/footer', [FooterSettingController::class, 'show']);
Route::get('/public/footer-social', [FooterSocialLinkController::class, 'index']);
Route::get('/public/heatmap', [FooterSettingController::class, 'statusByProvinsi']);
Route::get('/public/heatmap-kelurahan', [FooterSettingController::class, 'statusByKelurahan']);
Route::get('/public/heatmap-kecamatan', [FooterSettingController::class, 'statusByKecamatan']);
/* =========================
   ADMIN ONLY
========================== */
Route::middleware('auth:sanctum')->group(function () {

    // slider text
    Route::get('/slider-setting', [SliderSettingController::class, 'show']);
    Route::post('/slider-setting', [SliderSettingController::class, 'store']);

    // slider images
    Route::get('/slider-images', [SliderImageController::class, 'index']);
    Route::post('/slider-images', [SliderImageController::class, 'store']);
    Route::delete('/slider-images/{id}', [SliderImageController::class, 'destroy']);

    Route::post('/footer', [FooterSettingController::class, 'store']);
    Route::get('/footer', [FooterSettingController::class, 'show']);

    Route::get('/footer-social', [FooterSocialLinkController::class, 'index']);
    Route::put('/footer-social/{id}', [FooterSocialLinkController::class, 'update']);
});

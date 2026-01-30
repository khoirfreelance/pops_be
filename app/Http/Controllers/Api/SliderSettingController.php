<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SliderSetting;

class SliderSettingController extends Controller
{
    /**
     * GET /api/slider-setting
     * Public - Homepage
     */
    // ADMIN
    public function show()
    {
        return response()->json([
            'data' => SliderSetting::first()
        ]);
    }

    // ADMIN (UPSERT)
    public function store(Request $request)
    {
        $data = SliderSetting::updateOrCreate(
            ['id' => 1],
            $request->only([
                'main_title',
                'title',
                'description',
                'subdescription'
            ])
        );

        return response()->json([
            'message' => 'Slider setting disimpan',
            'data' => $data
        ]);
    }

    // PUBLIC (HOMEPAGE)
    public function public()
    {
        return response()->json([
            'data' => SliderSetting::first()
        ]);
    }
}

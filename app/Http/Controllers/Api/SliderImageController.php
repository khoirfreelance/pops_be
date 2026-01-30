<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SliderImage;
use Illuminate\Support\Facades\Storage;

class SliderImageController extends Controller
{
    /**
     * GET - public (homepage)
     */
    /*public function index()
    {
        return response()->json([
            'data' => SliderImage::where('is_active', 1)
                ->orderBy('sort_order')
                ->get()
        ]);
    }*/

    /**
     * POST - admin only
     */
    public function store(Request $request)
    {
        $request->validate([
            'image' => 'required|image|max:51200'
        ]);

        $path = $request->file('image')->store('slider', 'public');

        $image = SliderImage::create([
            'image_path' => $path,
            'image_url'  => asset('storage/' . $path),
            'sort_order' => SliderImage::max('sort_order') + 1,
            'is_active'  => 1,
        ]);

        return response()->json([
            'message' => 'Image slider berhasil diupload',
            'data' => $image
        ]);
    }

    // ADMIN
    public function index()
    {
        return response()->json([
            'data' => SliderImage::latest()->get()
        ]);
    }

    // PUBLIC
    public function public()
    {
        return response()->json([
            'data' => SliderImage::latest()->get()
        ]);
    }

    /**
     * DELETE - admin only
     */
    public function destroy($id)
    {
        $image = SliderImage::findOrFail($id);

        Storage::disk('public')->delete($image->image_path);
        $image->delete();

        return response()->json([
            'message' => 'Image slider berhasil dihapus'
        ]);
    }
}

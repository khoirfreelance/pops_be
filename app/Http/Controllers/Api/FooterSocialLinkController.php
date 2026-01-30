<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FooterSocialLink;

class FooterSocialLinkController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => FooterSocialLink::orderBy('sort_order')->get()
        ]);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'url' => 'required|string',
            'is_active' => 'required|boolean'
        ]);

        $link = FooterSocialLink::findOrFail($id);

        $link->update([
            'url' => $request->url,
            'is_active' => $request->is_active,
        ]);

        return response()->json([
            'message' => 'Social link updated',
            'data' => $link
        ]);
    }
}


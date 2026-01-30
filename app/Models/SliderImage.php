<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SliderImage extends Model
{
    protected $table = 'slider_images';

    protected $fillable = [
        'image_path',
        'image_url',
        'sort_order',
        'is_active',
    ];
}

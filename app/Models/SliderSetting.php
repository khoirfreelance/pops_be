<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SliderSetting extends Model
{
    protected $table = 'slider_settings';

    protected $fillable = [
        'main_title',
        'title',
        'description',
        'subdescription',
    ];
}

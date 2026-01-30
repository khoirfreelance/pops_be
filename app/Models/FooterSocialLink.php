<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FooterSocialLink extends Model
{
    protected $table = 'footer_social_links';

    protected $fillable = [
        'type',
        'label',
        'url',
        'is_active',
        'sort_order',
    ];
}

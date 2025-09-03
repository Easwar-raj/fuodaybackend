<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Recognitions extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'web_user_id',
        'title',
        'count',
        'image_url',
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class FeedbackReplies extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'admin_user_id',
        'company_name',
        'date',
        'feedback_id',
        'from_id',
        'from_name',
        'to_id',
        'to_name',
        'reply',
    ];

    protected $casts = [
        'date' => 'date'
    ];
}

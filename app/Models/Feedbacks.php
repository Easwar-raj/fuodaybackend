<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Feedbacks extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'admin_user_id',
        'company_name',
        'date',
        'to_id',
        'to_name',
        'to_designation',
        'from_id',
        'from_name',
        'requested_by_id',
        'requested_by_name',
        'overall_ratings',
        'review_ratings',
        'comments',
    ];

    protected $casts = [
        'date' => 'date'
    ];

    public function webUser()
    {
        return $this->belongsTo(WebUser::class, 'web_user_id');
    }
}

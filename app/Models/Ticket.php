<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Ticket extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'web_user_id',
        'emp_name',
        'emp_id',
        'ticket',
        'category',
        'assigned_to_id',
        'assigned_to',
        'assigned_by',
        'priority',
        'date',
        'status',
        'system_type'
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];

    public function webUser()
    {
        return $this->belongsTo(WebUser::class);
    }
}

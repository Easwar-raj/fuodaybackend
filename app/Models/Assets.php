<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Assets extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'web_user_id',
        'emp_name',
        'emp_id',
        'department',
        'components',
        'serial_number',
        'status',
    ];

    public function employee()
    {
        return $this->belongsTo(WebUser::class, 'web_user_id');
    }
}

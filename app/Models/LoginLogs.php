<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoginLogs extends Model
{
    use HasFactory;

    protected $fillable = [
        'web_user_id',
        'email',
        'name',
        'emp_id',
        'role',
        'ip_address',
        'user_agent',
        'status',
        'date',
        'time'
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];

    public function webUser()
    {
        return $this->belongsTo(WebUser::class);
    }
}
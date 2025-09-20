<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class PasswordResetOtp extends Model
{
    use HasFactory;

    protected $table = 'password_reset_otps';

    protected $fillable = [
        'email',
        'otp',
        'expires_at',
        'is_used'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'is_used' => 'boolean'
    ];

    /**
     * Check if OTP is expired
     */
    public function isExpired()
    {
        return Carbon::now()->greaterThan($this->expires_at);
    }

    /**
     * Check if OTP is valid (not used and not expired)
     */
    public function isValid()
    {
        return !$this->is_used && !$this->isExpired();
    }

    /**
     * Mark OTP as used
     */
    public function markAsUsed()
    {
        $this->update(['is_used' => true]);
    }
}

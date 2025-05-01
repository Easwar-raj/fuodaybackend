<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SectionSelection extends Model
{
    use HasFactory;

    protected $fillable = ['admin_user_id', 'section_name', 'parent_id'];

    public function adminUser()
    {
        return $this->belongsTo(AdminUser::class);
    }
}

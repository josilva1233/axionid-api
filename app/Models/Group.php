<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    // 1. Permite preencher o name e o creator_id
    protected $fillable = ['name', 'creator_id'];

    // 2. Relação com usuários (N para N)
    public function users()
    {
        return $this->belongsToMany(User::class, 'group_user')
                    ->withPivot('role')
                    ->withTimestamps();
    }

    // 3. Relação específica para Admins (usada no seu controller)
    public function admins()
    {
        return $this->users()->wherePivot('role', 'admin');
    }
    
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }
}
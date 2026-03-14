<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'label'];

    /**
     * Relacionamento: Um papel tem muitas permissões
     */
    public function permissions()
    {
        return $this->belongsToMany(Permission::class);
    }

    /**
     * Relacionamento: Um papel pertence a muitos usuários
     */
    public function users()
    {
        return $this->belongsToMany(User::class);
    }
}
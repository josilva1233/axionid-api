<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',  // ex: 'users.delete'
        'label', // ex: 'Excluir Usuários'
    ];

    /**
     * Relacionamento: Uma permissão pode estar em vários papéis (Roles)
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }
}
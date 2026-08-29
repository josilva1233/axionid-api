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
        'description' // ex: 'Permite que o usuário exclua outros usuários do sistema'
    ];

    /**
     * Relacionamento: Uma permissão pode estar em vários papéis (Roles)
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * 🔥 Relacionamento: Usuários que têm esta permissão diretamente
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'user_permissions');
    }

    /**
     * 🔥 Relacionamento: Grupos que têm esta permissão
     */
    public function groups()
    {
        return $this->belongsToMany(Group::class, 'group_permissions');
    }
}
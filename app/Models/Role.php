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
// Dentro de app/Models/Group.php
public function permissions()
{
    // Isso cria o relacionamento entre Grupos e Permissões
    return $this->belongsToMany(Permission::class, 'group_permission', 'group_id', 'permission_id');
}

    /**
     * Relacionamento: Um papel pertence a muitos usuários
     */
    public function users()
    {
        return $this->belongsToMany(User::class);
    }
     





}


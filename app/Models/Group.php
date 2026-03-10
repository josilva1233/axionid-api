<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    use HasFactory;

    /**
     * Atributos que podem ser preenchidos em massa.
     */
    protected $fillable = [
        'name',
        'creator_id',
    ];

    /**
     * Relacionamento: Usuários que pertencem ao grupo.
     * Define o acesso à tabela pivô 'group_user' e ao campo 'role'.
     */
    public function users()
    {
        return $this->belongsToMany(User::class)
                    ->withPivot('role')
                    ->withTimestamps();
    }

    /**
     * Relacionamento: O usuário que criou o grupo originalmente.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * Escopo/Método para filtrar apenas os administradores do grupo.
     */
    public function admins()
    {
        return $this->users()->wherePivot('role', 'admin');
    }
}
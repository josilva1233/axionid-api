<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Atributos que podem ser preenchidos em massa.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'cpf_cnpj',           
        'profile_completed',
        'is_admin',
        'google_id',
        'govbr_id', // <-- ADICIONE ESTA LINHA para permitir o nível de acesso
    ];

    /**
     * Atributos que devem ser escondidos em respostas JSON.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Conversão de tipos (Casts).
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'profile_completed' => 'boolean', // Garante retorno true/false no JSON
            'is_admin' => 'boolean',          // Garante retorno true/false no JSON
        ];
    }

    /**
     * Relacionamento com Endereço.
     */
    public function address()
    {
        return $this->hasOne(Address::class);
    }

    public function groups()
    {
    // O campo 'role' na tabela pivô define se é 'admin' ou 'member'
       return $this->belongsToMany(Group::class)->withPivot('role')->withTimestamps();
    }

    // Dentro do Model User em app/Models/User.php

public function roles()
{
    return $this->belongsToMany(Role::class);
}

/**
 * Checa se o usuário tem uma permissão específica
 */
public function hasPermission(string $permissionName): bool
{
    // O usuário é Admin Total? Se sim, nem precisa checar o resto
    if ($this->is_admin) {
        return true;
    }

    // Procura a permissão dentro de todos os papéis que o usuário possui
    return $this->roles()->whereHas('permissions', function($query) use ($permissionName) {
        $query->where('name', $permissionName);
    })->exists();
}
}
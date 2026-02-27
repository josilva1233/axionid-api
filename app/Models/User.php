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
}
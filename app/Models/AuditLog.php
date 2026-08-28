<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasFactory;

    /**
     * Campos que podem ser preenchidos em massa.
     */
    protected $fillable = [
        'user_id',
        'method',
        'url',
        'ip_address',
        'user_agent',
        'payload'
    ];

    /**
     * Casts para os campos da tabela.
     * * O cast 'json' transforma o payload em array automaticamente ao acessar.
     */
    protected $casts = [
        'payload' => 'json',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relacionamento: Um log pertence a um usuário.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Address extends Model
{
    protected $fillable = [
        'user_id', 
        'zip_code', 
        'street', 
        'number', 
        'complement', 
        'neighborhood', 
        'city', 
        'state',
        'updated_by_admin_id',
        'admin_updated_at'
    ];

    // Relacionamento com o dono do endereço
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relacionamento para identificar o administrador que realizou a atualização.
     * Vincula a coluna 'updated_by_admin_id' ao ID da tabela 'users'.
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_admin_id');
    }
}
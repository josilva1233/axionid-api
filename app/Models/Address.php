<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    // A variável PRECISA estar aqui dentro
    protected $fillable = [
        'user_id', 
        'zip_code', 
        'street', 
        'number', 
        'complement', 
        'neighborhood', 
        'city', 
        'state',
        'updated_by_admin_id', // Adicionar este
        'admin_updated_at'     // Adicionar este
    ];

    // Relacionamento reverso (opcional, mas recomendado)
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
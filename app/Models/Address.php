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
        'state'
    ];

    // Relacionamento reverso (opcional, mas recomendado)
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
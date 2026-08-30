<?php
// app/Models/UserTermAcceptance.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserTermAcceptance extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'term_id',
        'ip_address',
        'user_agent',
        'accepted_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function term()
    {
        return $this->belongsTo(Term::class);
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceOrder extends Model
{
    protected $fillable = [
        'protocol', 'title', 'description', 'user_id', 
        'group_id', 'technician_id', 'attachment_path', 
        'status', 'priority'
    ];

    // Quem abriu a OS
    public function client(): BelongsTo {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Grupo vinculado (se houver)
    public function group(): BelongsTo {
        return $this->belongsTo(Group::class);
    }

    // Técnico responsável
    public function technician(): BelongsTo {
        return $this->belongsTo(User::class, 'technician_id');
    }
}
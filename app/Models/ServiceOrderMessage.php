<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceOrderMessage extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'service_order_id',
        'user_id',
        'message',
        'attachment_path',
        'is_system_message', // Para diferenciar mensagens automáticas
    ];

    protected $casts = [
        'is_system_message' => 'boolean',
        'created_at' => 'datetime',
    ];

    // Quem enviou
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // A OS associada
    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    // Scope para mensagens de usuário
    public function scopeUserMessages($query)
    {
        return $query->where('is_system_message', false);
    }

    // Scope para mensagens do sistema
    public function scopeSystemMessages($query)
    {
        return $query->where('is_system_message', true);
    }

    // Acessor para formatação da data
    public function getFormattedCreatedAtAttribute()
    {
        return $this->created_at->format('d/m/Y H:i');
    }

    // Verificar se tem anexo
    public function hasAttachment(): bool
    {
        return !empty($this->attachment_path);
    }
}
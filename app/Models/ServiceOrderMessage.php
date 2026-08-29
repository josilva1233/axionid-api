<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class ServiceOrderMessage extends Model
{
    use SoftDeletes;

    protected $table = 'service_order_messages';

    protected $fillable = [
        'service_order_id',
        'user_id',
        'message',
        'attachment_path',
        'is_system_message',
    ];

    protected $casts = [
        'is_system_message' => 'boolean',
        'created_at' => 'datetime',
    ];

    protected $appends = [
        'user_name',
        'formatted_date',
        'attachment_url',
    ];

    // ========== RELACIONAMENTOS ==========

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    // ========== SCOPES ==========

    public function scopeUserMessages($query)
    {
        return $query->where('is_system_message', false);
    }

    public function scopeSystemMessages($query)
    {
        return $query->where('is_system_message', true);
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    // ========== ACCESSORS ==========

    public function getUserNameAttribute(): string
    {
        if ($this->is_system_message) {
            return 'Sistema';
        }
        return $this->user?->name ?? 'Usuário Desconhecido';
    }

    public function getFormattedDateAttribute(): string
    {
        return $this->created_at->format('d/m/Y H:i');
    }

    public function getAttachmentUrlAttribute(): ?string
    {
        if (!$this->attachment_path) {
            return null;
        }
        return Storage::disk('public')->url($this->attachment_path);
    }

    // ========== MÉTODOS ==========

    public function hasAttachment(): bool
    {
        return !empty($this->attachment_path) && Storage::disk('public')->exists($this->attachment_path);
    }

    public function markAsRead(): void
    {
        if (is_null($this->read_at)) {
            $this->update(['read_at' => now()]);
        }
    }

    public function getAttachmentInfo(): ?array
    {
        if (!$this->hasAttachment()) {
            return null;
        }

        return [
            'name' => basename($this->attachment_path),
            'size' => Storage::disk('public')->size($this->attachment_path),
            'size_formatted' => $this->formatFileSize(Storage::disk('public')->size($this->attachment_path)),
            'mime_type' => Storage::disk('public')->mimeType($this->attachment_path),
            'extension' => pathinfo($this->attachment_path, PATHINFO_EXTENSION),
        ];
    }

    // ========== MÉTODOS ESTÁTICOS ==========

    public static function createUserMessage(
        int $serviceOrderId,
        int $userId,
        string $message,
        $attachment = null
    ): self {
        $data = [
            'service_order_id' => $serviceOrderId,
            'user_id' => $userId,
            'message' => $message,
            'is_system_message' => false,
        ];

        if ($attachment) {
            $path = $attachment->store('service-orders/' . $serviceOrderId, 'public');
            $data['attachment_path'] = $path;
        }

        return self::create($data);
    }

    public static function createSystemMessage(
        int $serviceOrderId,
        string $message,
        int $userId = null
    ): self {
        return self::create([
            'service_order_id' => $serviceOrderId,
            'user_id' => $userId,
            'message' => $message,
            'is_system_message' => true,
        ]);
    }

    // ========== MÉTODOS PRIVADOS ==========

    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class ServiceOrderMessage extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'service_order_id',
        'user_id',
        'message',
        'attachment_path',
        'is_system_message',
        'read_at',
    ];

    protected $casts = [
        'is_system_message' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    protected $appends = [
        'attachment_url',
        'formatted_date',
        'is_read',
    ];

    // ========== RELACIONAMENTOS ==========

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    public function scopeRead($query)
    {
        return $query->whereNotNull('read_at');
    }

    public function scopeForOrder($query, $orderId)
    {
        return $query->where('service_order_id', $orderId);
    }

    // ========== ACESSORES ==========

    public function getAttachmentUrlAttribute()
    {
        if ($this->attachment_path && Storage::disk('public')->exists($this->attachment_path)) {
            return Storage::url($this->attachment_path);
        }
        return null;
    }

    public function getFormattedDateAttribute()
    {
        return $this->created_at->format('d/m/Y H:i');
    }

    public function getIsReadAttribute()
    {
        return !is_null($this->read_at);
    }

    public function getUserNameAttribute()
    {
        return $this->user ? $this->user->name : 'Sistema';
    }

    public function getUserAvatarAttribute()
    {
        if ($this->user && $this->user->avatar) {
            return Storage::url($this->user->avatar);
        }
        return null;
    }

    // ========== MÉTODOS ==========

    public function hasAttachment(): bool
    {
        return !empty($this->attachment_path) && Storage::disk('public')->exists($this->attachment_path);
    }

    public function markAsRead()
    {
        if (is_null($this->read_at)) {
            $this->update(['read_at' => now()]);
        }
        return $this;
    }

    public function markAsUnread()
    {
        $this->update(['read_at' => null]);
        return $this;
    }

    public function getAttachmentInfo()
    {
        if (!$this->hasAttachment()) {
            return null;
        }

        $path = Storage::disk('public')->path($this->attachment_path);
        return [
            'name' => basename($this->attachment_path),
            'size' => filesize($path),
            'size_formatted' => $this->formatSize(filesize($path)),
            'extension' => pathinfo($path, PATHINFO_EXTENSION),
            'url' => $this->attachment_url,
        ];
    }

    private function formatSize($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    // ========== MÉTODOS ESTÁTICOS ==========

    public static function createSystemMessage($orderId, $message, $userId = null)
    {
        return self::create([
            'service_order_id' => $orderId,
            'user_id' => $userId ?? 1, // Usuário sistema (criar um usuário padrão)
            'message' => $message,
            'is_system_message' => true,
        ]);
    }

    public static function createUserMessage($orderId, $userId, $message, $attachment = null)
    {
        $path = null;
        if ($attachment) {
            $path = $attachment->store('message_attachments', 'public');
        }

        return self::create([
            'service_order_id' => $orderId,
            'user_id' => $userId,
            'message' => $message,
            'attachment_path' => $path,
            'is_system_message' => false,
        ]);
    }
}
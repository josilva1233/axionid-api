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
        'user_name',
    ];

    // ========== RELACIONAMENTOS ==========

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
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

    public function scopeLatestFirst($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    public function scopeOldestFirst($query)
    {
        return $query->orderBy('created_at', 'asc');
    }

    // ========== ACESSORES ==========

    public function getAttachmentUrlAttribute(): ?string
    {
        if ($this->attachment_path && Storage::disk('public')->exists($this->attachment_path)) {
            return Storage::url($this->attachment_path);
        }
        return null;
    }

    public function getFormattedDateAttribute(): string
    {
        return $this->created_at->format('d/m/Y H:i');
    }

    public function getIsReadAttribute(): bool
    {
        return !is_null($this->read_at);
    }

    public function getUserNameAttribute(): string
    {
        if ($this->is_system_message) {
            return 'Sistema';
        }
        return $this->user ? $this->user->name : 'Usuário Desconhecido';
    }

    public function getUserAvatarAttribute(): ?string
    {
        if ($this->is_system_message) {
            return null;
        }
        
        if ($this->user && $this->user->avatar) {
            return Storage::url($this->user->avatar);
        }
        return null;
    }

    public function getUserEmailAttribute(): ?string
    {
        if ($this->is_system_message) {
            return null;
        }
        return $this->user ? $this->user->email : null;
    }

    // ========== MÉTODOS ==========

    public function hasAttachment(): bool
    {
        return !empty($this->attachment_path) && Storage::disk('public')->exists($this->attachment_path);
    }

    public function markAsRead(): self
    {
        if (is_null($this->read_at)) {
            $this->update(['read_at' => now()]);
        }
        return $this;
    }

    public function markAsUnread(): self
    {
        $this->update(['read_at' => null]);
        return $this;
    }

    public function getAttachmentInfo(): ?array
    {
        if (!$this->hasAttachment()) {
            return null;
        }

        $disk = Storage::disk('public');
        $path = $this->attachment_path;
        
        try {
            return [
                'name' => basename($path),
                'size' => $disk->size($path),
                'size_formatted' => $this->formatSize($disk->size($path)),
                'extension' => pathinfo($path, PATHINFO_EXTENSION),
                'url' => $this->attachment_url,
                'mime_type' => $disk->mimeType($path),
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    // ========== MÉTODOS ESTÁTICOS ==========

    public static function createSystemMessage(
        int $orderId,
        string $message,
        ?int $userId = null
    ): self {
        return self::create([
            'service_order_id' => $orderId,
            'user_id' => $userId ?? 1, // Usuário sistema
            'message' => $message,
            'is_system_message' => true,
            'read_at' => now(), // Mensagens do sistema já vem como lidas
        ]);
    }

    public static function createUserMessage(
        int $orderId,
        int $userId,
        string $message,
        $attachment = null
    ): self {
        $path = null;
        if ($attachment) {
            $path = $attachment->store('message_attachments/' . $orderId, 'public');
        }

        return self::create([
            'service_order_id' => $orderId,
            'user_id' => $userId,
            'message' => $message,
            'attachment_path' => $path,
            'is_system_message' => false,
        ]);
    }

    public static function markAllAsRead(int $orderId, int $userId): void
    {
        self::forOrder($orderId)
            ->where('user_id', '!=', $userId)
            ->unread()
            ->update(['read_at' => now()]);
    }

    public static function countUnreadForUser(int $userId, ?int $orderId = null): int
    {
        $query = self::where('user_id', '!=', $userId)
            ->unread();
        
        if ($orderId) {
            $query->forOrder($orderId);
        }
        
        return $query->count();
    }
}
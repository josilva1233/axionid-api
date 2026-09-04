<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Category; 

class ServiceOrder extends Model
{
    protected $table = 'service_orders';

    protected $fillable = [
        'protocol',
        'title',
        'description',
        'attachment_path',
        'user_id',
        'group_id',
        'technician_id',
        'status',
        'priority',
        'resolved_at',
        'category_id',
        'sla_first_response_due_at',
        'sla_resolution_due_at',
        'first_response_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    // Status possíveis
    const STATUS_OPEN = 'open';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_CLOSED = 'closed';

    // Prioridades possíveis
    const PRIORITY_LOW = 'low';
    const PRIORITY_MEDIUM = 'medium';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_URGENT = 'urgent';

    // ========== RELACIONAMENTOS ==========
     
    

    // Usuário que abriu a OS
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Grupo responsável
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    // Técnico responsável
    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    //  NOVO: Categoria do chamado
    public function category(): BelongsTo
{
    return $this->belongsTo(Category::class);
}

    // Mensagens da OS
    public function messages(): HasMany
    {
        return $this->hasMany(ServiceOrderMessage::class, 'service_order_id');
    }

    // ========== SCOPES ==========

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', self::STATUS_IN_PROGRESS);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByTechnician($query, $technicianId)
    {
        return $query->where('technician_id', $technicianId);
    }

    public function scopeByGroup($query, $groupId)
    {
        return $query->where('group_id', $groupId);
    }

    public function scopeByPriority($query, $priority)
    {
        return $query->where('priority', $priority);
    }

    // ========== MÉTODOS ==========

    // Gerar protocolo automaticamente
    public static function generateProtocol(): string
    {
        $prefix = 'OS';
        $year = date('Y');
        $month = date('m');
        
        // Buscar último número do mês
        $last = self::whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderBy('id', 'desc')
            ->first();
            
        $number = $last ? intval(substr($last->protocol, -4)) + 1 : 1;
        
        return $prefix . $year . $month . str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    // Verificar status
    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    // Verificar se pode ser editada
    public function canBeEdited(): bool
    {
        return !in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED
        ]);
    }

    // Getter para status formatado
    public function getStatusLabelAttribute(): string
    {
        return [
            self::STATUS_OPEN => 'Aberta',
            self::STATUS_IN_PROGRESS => 'Em Andamento',
            self::STATUS_COMPLETED => 'Concluída',
            self::STATUS_CANCELLED => 'Cancelada',
            self::STATUS_CLOSED => 'Fechada',
        ][$this->status] ?? $this->status;
    }

    // Getter para prioridade formatada
    public function getPriorityLabelAttribute(): string
    {
        return [
            self::PRIORITY_LOW => 'Baixa',
            self::PRIORITY_MEDIUM => 'Média',
            self::PRIORITY_HIGH => 'Alta',
            self::PRIORITY_URGENT => 'Urgente',
        ][$this->priority] ?? $this->priority;
    }

    // Getter para cor da prioridade
    public function getPriorityColorAttribute(): string
    {
        return [
            self::PRIORITY_LOW => 'success',
            self::PRIORITY_MEDIUM => 'warning',
            self::PRIORITY_HIGH => 'danger',
            self::PRIORITY_URGENT => 'danger',
        ][$this->priority] ?? 'secondary';
    }

    // Getter para protocolo formatado
    public function getFormattedProtocolAttribute(): string
    {
        return $this->protocol ?? 'N/A';
    }

    // Verificar se tem anexo
    public function hasAttachment(): bool
    {
        return !empty($this->attachment_path);
    }

    // Obter URL do anexo
    public function getAttachmentUrlAttribute(): ?string
    {
        if (!$this->hasAttachment()) {
            return null;
        }
        return Storage::disk('public')->url($this->attachment_path);
    }
    // ========== MÉTODOS DE SLA ==========

/**
 * Calcular prazos de SLA com base na categoria
 */
public static function calculateSlaDates($categoryId): array
{
    $category = Category::find($categoryId);
    if (!$category) {
        return [null, null];
    }

    $now = now();
    $firstResponseDue = $now->copy()->addHours($category->sla_first_response_hours);
    $resolutionDue = $now->copy()->addHours($category->sla_resolution_hours);

    return [$firstResponseDue, $resolutionDue];
}
}
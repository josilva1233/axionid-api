<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceOrderMessage extends Model
{
    protected $fillable = [
        'service_order_id',
        'user_id',
        'message',
        'attachment_path',
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
}
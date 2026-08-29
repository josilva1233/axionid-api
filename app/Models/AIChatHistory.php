<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIChatHistory extends Model
{
    protected $table = 'ai_chat_history';

    protected $fillable = [
        'user_id',
        'user_message',
        'ai_response',
        'model',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
<?php
// app/Models/Term.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Term extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'content',
        'version',
        'is_active',
        'created_by',
        'published_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function acceptances()
    {
        return $this->hasMany(UserTermAcceptance::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at');
    }

    public static function getActiveTerm()
    {
        return self::where('is_active', true)
            ->whereNotNull('published_at')
            ->orderBy('created_at', 'desc')
            ->first();
    }

    public function isAcceptedByUser(User $user): bool
    {
        return $this->acceptances()->where('user_id', $user->id)->exists();
    }
}
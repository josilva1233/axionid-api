<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'description', 'default_group_id',
        'sla_first_response_hours', 'sla_resolution_hours',
        'default_priority', 'is_active'
    ];

    protected $casts = [
        'sla_first_response_hours' => 'integer',
        'sla_resolution_hours' => 'integer',
        'is_active' => 'boolean',
    ];

    public function defaultGroup()
    {
        return $this->belongsTo(Group::class, 'default_group_id');
    }

    public function serviceOrders()
    {
        return $this->hasMany(ServiceOrder::class);
    }

    // Scope para ativos
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
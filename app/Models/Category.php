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
        'default_priority', 'is_active', 'parent_id'
    ];

    protected $casts = [
        'sla_first_response_hours' => 'integer',
        'sla_resolution_hours' => 'integer',
        'is_active' => 'boolean',
    ];

    // Relacionamento com grupo padrão
    public function defaultGroup()
    {
        return $this->belongsTo(Group::class, 'default_group_id');
    }

    // Chamados desta categoria
    public function serviceOrders()
    {
        return $this->hasMany(ServiceOrder::class);
    }

    // Categoria pai
    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    // Subcategorias
    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    // Scope para ativos
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Método para obter a árvore (com profundidade)
    public static function getTree($parentId = null, $depth = 0)
    {
        $categories = self::where('parent_id', $parentId)
                          ->orderBy('name')
                          ->get();

        $result = [];
        foreach ($categories as $cat) {
            $cat->depth = $depth;
            $cat->children = self::getTree($cat->id, $depth + 1);
            $result[] = $cat;
        }
        return $result;
    }

    // Método para obter todas as categorias em formato de lista plana com nível
    public static function getFlatList($parentId = null, $depth = 0, &$list = [])
    {
        $categories = self::where('parent_id', $parentId)
                          ->orderBy('name')
                          ->get();

        foreach ($categories as $cat) {
            $cat->level = $depth;
            $list[] = $cat;
            self::getFlatList($cat->id, $depth + 1, $list);
        }
        return $list;
    }
}
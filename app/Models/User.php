<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * Atributos que podem ser preenchidos em massa.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'cpf_cnpj',           
        'profile_completed',
        'is_admin',
        'google_id',
        'govbr_id',
        'is_active',
        'from_google',
    ];

    /**
     * Atributos que devem ser escondidos em respostas JSON.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Conversão de tipos (Casts).
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'profile_completed' => 'boolean',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
            'from_google' => 'boolean',
        ];
    }

    /**
     * Relacionamento com Endereço.
     */
    public function address()
    {
        return $this->hasOne(Address::class);
    }

    /**
     * Relacionamento com Grupos.
     */
    public function groups()
    {
        return $this->belongsToMany(Group::class)->withPivot('role')->withTimestamps();
    }

    /**
     * Relacionamento com Roles.
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

    // =========================================================
    // 🔥 RELACIONAMENTO COM PERMISSÕES DIRETAS
    // =========================================================

    /**
     * Relacionamento com permissões diretas do usuário
     */
    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'user_permissions');
    }

    // =========================================================
    // 🔥 MÉTODOS PARA VERIFICAR E GERENCIAR PERMISSÕES
    // =========================================================

    /**
     * Checa se o usuário tem uma permissão específica
     */
    public function hasPermission(string $permissionName): bool
    {
        // Admin total tem todas as permissões
        if ($this->is_admin) {
            return true;
        }

        // Verifica permissões diretas do usuário
        if ($this->permissions()->where('name', $permissionName)->exists()) {
            return true;
        }

        // Verifica permissões através dos roles
        return $this->roles()->whereHas('permissions', function($query) use ($permissionName) {
            $query->where('name', $permissionName);
        })->exists();
    }

    /**
     * Checa se o usuário tem uma permissão por ID
     */
    public function hasPermissionId(int $permissionId): bool
    {
        if ($this->is_admin) {
            return true;
        }

        if ($this->permissions()->where('permission_id', $permissionId)->exists()) {
            return true;
        }

        return $this->roles()->whereHas('permissions', function($query) use ($permissionId) {
            $query->where('id', $permissionId);
        })->exists();
    }

    /**
     * Atribui uma permissão ao usuário
     */
    public function assignPermission($permissionId)
    {
        return $this->permissions()->syncWithoutDetaching([$permissionId]);
    }

    /**
     * Remove uma permissão do usuário
     */
    public function removePermission($permissionId)
    {
        return $this->permissions()->detach($permissionId);
    }

    /**
     * Sincroniza todas as permissões do usuário (substitui todas)
     */
    public function syncPermissions(array $permissionIds)
    {
        return $this->permissions()->sync($permissionIds);
    }

    /**
     * Obtém todas as permissões do usuário (diretas + via roles)
     */
    public function getAllPermissions()
    {
        $permissions = collect();

        // Permissões diretas
        $permissions = $permissions->merge($this->permissions);

        // Permissões via roles
        foreach ($this->roles as $role) {
            $permissions = $permissions->merge($role->permissions);
        }

        return $permissions->unique('id');
    }

    /**
     * Verifica se o usuário é admin
     */
    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    /**
     * Verifica se o usuário está ativo
     */
    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }
}
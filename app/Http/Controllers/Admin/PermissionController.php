<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class PermissionController extends Controller
{
    /**
     * Atribuir um Papel (Role) a um Usuário
     */
    #[OA\Post(
        path: '/api/v1/admin/users/{id}/assign-role',
        summary: 'Atribuir cargo ao usuário',
        tags: ['Administração - Permissões'],
        security: [['sanctum' => []]]
    )]
    public function assignRole(Request $request, $id)
    {
        // Apenas o Admin Master (is_admin) pode gerenciar permissões
        if (!auth()->user()->is_admin) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $user = User::findOrFail($id);
        $role = Role::where('name', $request->role_name)->firstOrFail();

        // O método sync() evita duplicidade: ele remove os antigos e coloca o novo
        // Ou use attach() se o usuário puder ter vários cargos
        $user->roles()->syncWithoutDetaching([$role->id]);

        return response()->json([
            'message' => "Usuário agora possui o cargo: {$role->label}"
        ]);
    }

    /**
     * Listar todas as permissões cadastradas no sistema
     */
    public function listPermissions()
    {
        return response()->json(Permission::all());
    }

    /**
     * Criar uma nova permissão (ex: 'relatorios.gerar')
     */
    public function storePermission(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|unique:permissions|max:255',
            'label' => 'required|max:255',
        ]);

        $permission = Permission::create($validated);
        return response()->json($permission, 201);
    }
}
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
    #[OA\Post(
        path: '/api/v1/admin/users/{id}/assign-role',
        summary: 'Atribuir cargo ao usuário',
        description: 'Vincula um cargo (Role) específico a um usuário do sistema.',
        tags: ['Administração - Permissões'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'role_name', type: 'string', example: 'admin')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cargo atribuído com sucesso'),
            new OA\Response(response: 403, description: 'Acesso negado'),
            new OA\Response(response: 404, description: 'Usuário ou Cargo não encontrado')
        ]
    )]
    public function assignRole(Request $request, $id)
    {
        if (!auth()->user()->is_admin) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $user = User::findOrFail($id);
        $role = Role::where('name', $request->role_name)->firstOrFail();

        // syncWithoutDetaching garante que o usuário ganhe o cargo sem perder os que já tinha
        $user->roles()->syncWithoutDetaching([$role->id]);

        return response()->json([
            'message' => "Usuário agora possui o cargo: {$role->label}"
        ]);
    }

    #[OA\Get(
        path: '/api/v1/permissions',
        summary: 'Listar todas as permissões',
        tags: ['Administração - Permissões'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Lista de permissões')
        ]
    )]
    public function listPermissions()
    {
        return response()->json(Permission::all());
    }

    #[OA\Post(
        path: '/api/v1/permissions',
        summary: 'Criar uma nova permissão',
        tags: ['Administração - Permissões'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'users.delete'),
                    new OA\Property(property: 'label', type: 'string', example: 'Excluir Usuários')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Permissão criada'),
            new OA\Response(response: 422, description: 'Erro de validação')
        ]
    )]
    public function storePermission(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|unique:permissions|max:255',
            'label' => 'required|max:255',
        ]);

        $permission = Permission::create($validated);
        return response()->json($permission, 201);
    }

public function attachPermissionToRole(Request $request, $groupId)
{
    // Usamos Group porque no seu Tinker o ID 13 não existia em Role
    $group = \App\Models\Group::findOrFail($groupId);
    $permission = \App\Models\Permission::where('name', $request->permission_name)->firstOrFail();

    $group->permissions()->syncWithoutDetaching([$permission->id]);

    return response()->json([
        'message' => "Permissão vinculada com sucesso!"
    ]);
}

    // Remover permissão do grupo
    public function detachPermissionFromRole($groupId, $permissionId)
    {
        // Alterado de Role para Group
        $group = \App\Models\Group::findOrFail($groupId);
        
        $group->permissions()->detach($permissionId);

        return response()->json(['message' => 'Permissão removida com sucesso.']);
    }
}
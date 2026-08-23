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

        $user->roles()->syncWithoutDetaching([$role->id]);

        return response()->json([
            'message' => "Usuário agora possui o cargo: {$role->label}"
        ]);
    }

    // =========================================================
    // LISTAR PERMISSÕES COM FILTROS
    // =========================================================
    #[OA\Get(
        path: '/api/v1/admin/permissions',
        summary: 'Listar todas as permissões com filtros',
        tags: ['Administração - Permissões'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(
                name: 'label',
                in: 'query',
                description: 'Filtrar por label (nome exibido)',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'Criar')
            ),
            new OA\Parameter(
                name: 'name',
                in: 'query',
                description: 'Filtrar por name (chave do sistema)',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'users.create')
            ),
            new OA\Parameter(
                name: 'page',
                in: 'query',
                description: 'Número da página',
                required: false,
                schema: new OA\Schema(type: 'integer', example: 1)
            ),
            new OA\Parameter(
                name: 'per_page',
                in: 'query',
                description: 'Itens por página',
                required: false,
                schema: new OA\Schema(type: 'integer', example: 10)
            )
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de permissões filtrada'),
            new OA\Response(response: 403, description: 'Acesso negado')
        ]
    )]
    public function listPermissions(Request $request)
    {
        if (!auth()->user()->is_admin) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $query = Permission::query();

        if ($request->filled('label')) {
            $query->where('label', 'LIKE', "%{$request->label}%");
        }

        if ($request->filled('name')) {
            $query->where('name', 'LIKE', "%{$request->name}%");
        }

        $perPage = $request->per_page ?? 10;
        $permissions = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($permissions);
    }

    // =========================================================
    // BUSCAR UMA PERMISSÃO ESPECÍFICA (DETALHES)
    // =========================================================
    #[OA\Get(
        path: '/api/v1/admin/permissions/{id}',
        summary: 'Buscar uma permissão específica',
        tags: ['Administração - Permissões'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dados da permissão'),
            new OA\Response(response: 404, description: 'Permissão não encontrada')
        ]
    )]
    public function showPermission($id)
    {
        if (!auth()->user()->is_admin) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $permission = Permission::find($id);

        if (!$permission) {
            return response()->json(['message' => 'Permissão não encontrada.'], 404);
        }

        return response()->json($permission);
    }

    // =========================================================
    // CRIAR PERMISSÃO
    // =========================================================
    #[OA\Post(
        path: '/api/v1/admin/permissions',
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
        if (!auth()->user()->is_admin) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|unique:permissions|max:255',
            'label' => 'required|max:255',
            'description' => 'nullable|string',
        ]);

        $permission = Permission::create($validated);
        return response()->json($permission, 201);
    }

    // =========================================================
    // ATUALIZAR PERMISSÃO
    // =========================================================
    #[OA\Put(
        path: '/api/v1/admin/permissions/{id}',
        summary: 'Atualizar uma permissão',
        tags: ['Administração - Permissões'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'users.delete'),
                    new OA\Property(property: 'label', type: 'string', example: 'Excluir Usuários'),
                    new OA\Property(property: 'description', type: 'string', example: 'Permite excluir usuários do sistema')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Permissão atualizada'),
            new OA\Response(response: 404, description: 'Permissão não encontrada'),
            new OA\Response(response: 422, description: 'Erro de validação')
        ]
    )]
    public function updatePermission(Request $request, $id)
    {
        if (!auth()->user()->is_admin) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $permission = Permission::find($id);

        if (!$permission) {
            return response()->json(['message' => 'Permissão não encontrada.'], 404);
        }

        $validated = $request->validate([
            'name' => 'required|unique:permissions,name,' . $id . '|max:255',
            'label' => 'required|max:255',
            'description' => 'nullable|string',
        ]);

        $permission->update($validated);

        return response()->json($permission);
    }

    // =========================================================
    // EXCLUIR PERMISSÃO
    // =========================================================
    #[OA\Delete(
        path: '/api/v1/admin/permissions/{id}',
        summary: 'Excluir uma permissão',
        tags: ['Administração - Permissões'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Permissão excluída'),
            new OA\Response(response: 404, description: 'Permissão não encontrada'),
            new OA\Response(response: 422, description: 'Permissão vinculada a grupos')
        ]
    )]
    public function deletePermission($id)
    {
        if (!auth()->user()->is_admin) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $permission = Permission::find($id);

        if (!$permission) {
            return response()->json(['message' => 'Permissão não encontrada.'], 404);
        }

        // Verificar se a permissão está vinculada a algum grupo
        $hasGroups = $permission->groups()->count() > 0;
        
        if ($hasGroups) {
            return response()->json([
                'message' => 'Não é possível excluir esta permissão pois ela está vinculada a um ou mais grupos.',
                'groups' => $permission->groups()->pluck('name')
            ], 422);
        }

        $permission->delete();

        return response()->json(['message' => 'Permissão excluída com sucesso.']);
    }

    // =========================================================
    // VINCULAR PERMISSÃO AO GRUPO
    // =========================================================
    public function attachPermissionToRole(Request $request, $groupId)
    {
        if (!auth()->user()->is_admin) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $group = \App\Models\Group::findOrFail($groupId);
        $permission = Permission::where('name', $request->permission_name)->firstOrFail();

        if ($group->permissions()->where('permission_id', $permission->id)->exists()) {
            return response()->json([
                'message' => "Esta chave de permissão já está vinculada a este grupo."
            ], 422);
        }

        $group->permissions()->syncWithoutDetaching([$permission->id]);

        return response()->json([
            'message' => "Permissão '{$permission->label}' vinculada com sucesso!"
        ]);
    }

    // =========================================================
    // REMOVER PERMISSÃO DO GRUPO
    // =========================================================
    public function detachPermissionFromRole($groupId, $permissionId)
    {
        if (!auth()->user()->is_admin) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $group = \App\Models\Group::findOrFail($groupId);
        $group->permissions()->detach($permissionId);

        return response()->json(['message' => 'Permissão removida com sucesso.']);
    }
}
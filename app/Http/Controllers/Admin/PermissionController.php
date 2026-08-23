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
    // LISTAR PERMISSÕES COM FILTROS - CORRIGIDO
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
    public function listPermissions(Request $request) // ← ADICIONADO Request $request
    {
        // Verifica se é admin
        if (!auth()->user()->is_admin) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $query = Permission::query();

        // =========================================================
        // FILTROS QUE O FRONTEND ENVIA
        // =========================================================
        
        // 1. Filtrar por label (nome exibido)
        if ($request->filled('label')) {
            $query->where('label', 'LIKE', "%{$request->label}%");
        }

        // 2. Filtrar por name (chave do sistema)
        if ($request->filled('name')) {
            $query->where('name', 'LIKE', "%{$request->name}%");
        }

        // 3. Paginação
        $perPage = $request->per_page ?? 10;
        $permissions = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($permissions);
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
        ]);

        $permission = Permission::create($validated);
        return response()->json($permission, 201);
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
<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator; // Adicionado para seguir o padrão Auth
use Illuminate\Support\Facades\DB;        // Adicionado para consistência
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Grupos', description: 'Gerenciamento de grupos e membros')]
class AxionGroupController extends Controller
{
    #[OA\Post(
        path: '/api/v1/groups',
        summary: 'Criar novo grupo',
        tags: ['Grupos'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Grupo Financeiro')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Grupo criado com sucesso'),
            new OA\Response(response: 422, description: 'Erro de validação'),
            new OA\Response(response: 401, description: 'Não autenticado')
        ]
    )]
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $group = Group::create([
            'name' => $request->name,
            'creator_id' => Auth::id()
        ]);

        // Adiciona o criador como admin na tabela pivô
        $group->users()->attach(Auth::id(), ['role' => 'admin']);

        return response()->json([
            'message' => 'Grupo criado com sucesso',
            'group' => $group
        ], 201);
    }

    #[OA\Get(
        path: '/api/v1/groups/{id}',
        summary: 'Exibir detalhes do grupo',
        tags: ['Grupos'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dados do grupo e membros'),
            new OA\Response(response: 403, description: 'Acesso negado'),
            new OA\Response(response: 404, description: 'Grupo não encontrado')
        ]
    )]
    public function show($id)
    {
        $group = Group::with('users')->find($id);

        if (!$group) {
            return response()->json(['message' => 'Grupo não encontrado.'], 404);
        }

        if (!$group->users->contains(Auth::id())) {
            return response()->json(['message' => 'Você não tem acesso a este grupo.'], 403);
        }

        return response()->json($group);
    }

    #[OA\Post(
        path: '/api/v1/groups/{group_id}/members',
        summary: 'Adicionar membro ao grupo',
        tags: ['Grupos'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'group_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'user_id', type: 'integer', example: 5)
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Membro adicionado com sucesso'),
            new OA\Response(response: 403, description: 'Apenas administradores do grupo podem convidar'),
            new OA\Response(response: 422, description: 'Erro de validação ou usuário já no grupo')
        ]
    )]
    public function addMember(Request $request, $groupId)
    {
        $group = Group::findOrFail($groupId);
        
        $isAdmin = $group->users()
            ->where('user_id', Auth::id())
            ->wherePivot('role', 'admin')
            ->exists();

        if (!$isAdmin) {
            return response()->json(['message' => 'Apenas administradores do grupo podem convidar.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        if ($group->users()->where('user_id', $request->user_id)->exists()) {
            return response()->json(['message' => 'Este usuário já é membro do grupo.'], 422);
        }

        $group->users()->attach($request->user_id, ['role' => 'member']);

        return response()->json(['message' => 'Membro adicionado com sucesso.']);
    }

    #[OA\Patch(
        path: '/api/v1/groups/{group_id}/members/{user_id}/promote',
        summary: 'Promover membro a Admin do grupo',
        tags: ['Grupos'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'group_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'user_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Membro promovido com sucesso'),
            new OA\Response(response: 403, description: 'Ação negada')
        ]
    )]
    public function promoteMember($groupId, $userId)
    {
        $group = Group::findOrFail($groupId);
        
        $currentAdmin = $group->users()
            ->where('user_id', Auth::id())
            ->wherePivot('role', 'admin')
            ->exists();

        if (!$currentAdmin) {
            return response()->json(['message' => 'Ação negada. Você não é admin deste grupo.'], 403);
        }

        $group->users()->updateExistingPivot($userId, ['role' => 'admin']);

        return response()->json(['message' => 'Usuário promovido a administrador do grupo com sucesso.']);
    }

    #[OA\Delete(
        path: '/api/v1/groups/{group_id}/members/{user_id}',
        summary: 'Remover membro ou sair do grupo',
        tags: ['Grupos'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'group_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'user_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Removido com sucesso'),
            new OA\Response(response: 422, description: 'O grupo precisa de pelo menos um administrador')
        ]
    )]
    public function removeMember($groupId, $userId)
    {
        $group = Group::findOrFail($groupId);
        
        $currentAdmin = $group->users()
            ->where('user_id', Auth::id())
            ->wherePivot('role', 'admin')
            ->exists();

        // Pode remover se for admin ou se o usuário estiver tentando sair do próprio grupo
        if (!$currentAdmin && Auth::id() != $userId) {
            return response()->json(['message' => 'Ação negada.'], 403);
        }

        // Não permitir remover o último admin
        if ($group->admins()->count() <= 1 && $group->admins()->where('user_id', $userId)->exists()) {
            return response()->json(['message' => 'O grupo precisa de pelo menos um administrador.'], 422);
        }

        $group->users()->detach($userId);

        return response()->json(['message' => 'Membro removido com sucesso.']);
    }
}
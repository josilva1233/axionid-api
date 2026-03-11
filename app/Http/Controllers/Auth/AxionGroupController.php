<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Grupos', description: 'Gerenciamento de grupos e membros')]
class AxionGroupController extends Controller
{
    #[OA\Get(
        path: '/api/v1/admin/groups',
        summary: 'Listar todos os grupos (Apenas Super Admin)',
        tags: ['Grupos'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Lista de todos os grupos do sistema'),
            new OA\Response(response: 403, description: 'Acesso negado')
        ]
    )]
    public function index()
    {
        // Regra: Somente admin do sistema (total) pode ver tudo
        if (!Auth::user()->is_admin) {
            return response()->json(['message' => 'Acesso negado. Requer privilégios de administrador do sistema.'], 403);
        }

        $groups = Group::with(['creator', 'users'])->paginate(15);
        return response()->json($groups);
    }

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

        // Lógica de acesso: Se for Admin do sistema OU estiver no grupo
        $isSystemAdmin = Auth::user()->is_admin;
        $isMember = $group->users->contains(Auth::id());

        if (!$isSystemAdmin && !$isMember) {
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
            new OA\Response(response: 403, description: 'Apenas administradores podem convidar'),
            new OA\Response(response: 422, description: 'Erro de validação')
        ]
    )]
    public function addMember(Request $request, $groupId)
    {
        $group = Group::findOrFail($groupId);
        
        // Se for Admin do sistema, ignora a verificação de role de grupo
        $isSystemAdmin = Auth::user()->is_admin;
        $isGroupAdmin = $group->users()
            ->where('user_id', Auth::id())
            ->wherePivot('role', 'admin')
            ->exists();

        if (!$isSystemAdmin && !$isGroupAdmin) {
            return response()->json(['message' => 'Apenas administradores podem convidar membros.'], 403);
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
        
        $isSystemAdmin = Auth::user()->is_admin;
        $isGroupAdmin = $group->users()
            ->where('user_id', Auth::id())
            ->wherePivot('role', 'admin')
            ->exists();

        if (!$isSystemAdmin && !$isGroupAdmin) {
            return response()->json(['message' => 'Ação negada. Requer privilégios administrativos.'], 403);
        }

        $group->users()->updateExistingPivot($userId, ['role' => 'admin']);

        return response()->json(['message' => 'Usuário promovido a administrador do grupo.']);
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
        
        $isSystemAdmin = Auth::user()->is_admin;
        $isGroupAdmin = $group->users()
            ->where('user_id', Auth::id())
            ->wherePivot('role', 'admin')
            ->exists();

        // Admin do sistema pode remover qualquer um. 
        // Se não for admin sistema, deve ser admin do grupo ou o próprio usuário saindo.
        if (!$isSystemAdmin && !$isGroupAdmin && Auth::id() != $userId) {
            return response()->json(['message' => 'Ação negada.'], 403);
        }

        if ($group->admins()->count() <= 1 && $group->admins()->where('user_id', $userId)->exists()) {
            return response()->json(['message' => 'O grupo precisa de pelo menos um administrador.'], 422);
        }

        $group->users()->detach($userId);

        return response()->json(['message' => 'Membro removido com sucesso.']);
    }
}
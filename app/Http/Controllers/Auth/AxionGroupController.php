<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Grupos', description: 'Gerenciamento de grupos e membros')]
class AxionGroupController extends Controller
{
    #[OA\Get(
        path: '/api/v1/groups',
        summary: 'Listar grupos (Usuário vê os seus, Admin vê todos)',
        description: 'Filtra grupos pelo nome do grupo ou nome de integrantes/criador.',
        tags: ['Grupos'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(
                name: 'name',
                in: 'query',
                description: 'Nome do grupo ou nome de um usuário membro',
                required: false,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'page',
                in: 'query',
                description: 'Página para paginação',
                required: false,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de grupos filtrada'),
            new OA\Response(response: 401, description: 'Não autenticado')
        ]
    )]
    public function index(Request $request)
    {
        $user = Auth::user();
        $searchTerm = $request->name;

        // Adicione 'permissions' ao array do with
    $query = Group::with(['creator', 'permissions', 'users' => function($q) {
        $q->select('users.id', 'users.name', 'users.email'); 
    }]);

        // --- Início da Lógica de Busca ---
        if (!empty($searchTerm)) {
            $query->where(function($q) use ($searchTerm) {
                $q->where('name', 'like', '%' . $searchTerm . '%')
                  ->orWhereHas('users', function($userQuery) use ($searchTerm) {
                      $userQuery->where('name', 'like', '%' . $searchTerm . '%');
                  })
                  ->orWhereHas('creator', function($creatorQuery) use ($searchTerm) {
                      $creatorQuery->where('name', 'like', '%' . $searchTerm . '%');
                  });
            });
        }
        // --- Fim da Lógica de Busca ---

        if ($user->is_admin) {
            $groups = $query->paginate(15);
        } else {
            // Mantém a regra original: Dono ou Membro
            $groups = $query->where(function($q) use ($user) {
                $q->where('creator_id', $user->id)
                  ->orWhereHas('users', function ($q) use ($user) {
                      $q->where('group_user.user_id', $user->id);
                  });
            })->paginate(15);
        }

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
            new OA\Response(response: 422, description: 'Erro de validação')
        ]
    )]
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:groups,name',
        ], [
            'name.unique' => 'Já existe um grupo cadastrado com este nome.'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Esse nome de grupo já está em uso.',
                'errors' => $validator->errors()
            ], 422);
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
            new OA\Response(response: 200, description: 'Dados do grupo'),
            new OA\Response(response: 403, description: 'Acesso negado')
        ]
    )]
    public function show($id)
    {
        $group = Group::with(['users', 'permissions'])->find($id);

        if (!$group) {
            return response()->json(['message' => 'Grupo não encontrado.'], 404);
        }

        if (!Auth::user()->is_admin && !$group->users->contains(Auth::id())) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        return response()->json($group);
    }

    #[OA\Post(
        path: '/api/v1/groups/{id}/members',
        summary: 'Adicionar membro ao grupo',
        tags: ['Grupos'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [new OA\Property(property: 'user_id', type: 'integer')])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Adicionado com sucesso')
        ]
    )]
    public function addMember(Request $request, $groupId)
    {
        $group = Group::findOrFail($groupId);
        $user = Auth::user();
        
        $isGroupAdmin = $group->users()->where('user_id', $user->id)->wherePivot('role', 'admin')->exists();

        if (!$user->is_admin && !$isGroupAdmin) {
            return response()->json(['message' => 'Ação negada.'], 403);
        }

        if ($group->users()->where('user_id', $request->user_id)->exists()) {
            return response()->json(['message' => 'Usuário já é membro.'], 422);
        }

        $group->users()->attach($request->user_id, ['role' => 'member']);
        return response()->json(['message' => 'Membro adicionado.']);
    }

    #[OA\Patch(
        path: '/api/v1/groups/{id}/members/{user_id}/promote',
        summary: 'Promover membro a Admin do grupo',
        tags: ['Grupos'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'user_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Promovido')
        ]
    )]
    public function promoteMember($groupId, $userId)
    {
        $group = Group::findOrFail($groupId);
        $user = Auth::user();
        
        $isGroupAdmin = $group->users()->where('user_id', $user->id)->wherePivot('role', 'admin')->exists();

        if (!$user->is_admin && !$isGroupAdmin) {
            return response()->json(['message' => 'Ação negada.'], 403);
        }

        $group->users()->updateExistingPivot($userId, ['role' => 'admin']);
        return response()->json(['message' => 'Promovido a admin do grupo.']);
    }

    #[OA\Patch(
        path: '/api/v1/groups/{group_id}/members/{user_id}/demote',
        summary: 'Rebaixar administrador para membro comum',
        description: 'O Admin Total do sistema ignora as restrições de dono.',
        tags: ['Grupos'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'group_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'user_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Rebaixado')
        ]
    )]
    public function demoteMember($groupId, $userId)
    {
        $group = Group::findOrFail($groupId);
        $user = Auth::user();

        if (!$user->is_admin) {
            if ((int)$userId === (int)$group->creator_id) {
                return response()->json(['message' => 'O proprietário não pode ser rebaixado.'], 422);
            }

            if ((int)$userId === (int)$user->id) {
                return response()->json(['message' => 'Você não pode rebaixar a si mesmo.'], 422);
            }

            $isGroupAdmin = $group->users()->where('user_id', $user->id)->wherePivot('role', 'admin')->exists();
            if (!$isGroupAdmin) {
                return response()->json(['message' => 'Acesso negado.'], 403);
            }
        }

        $group->users()->updateExistingPivot($userId, ['role' => 'member']);
        return response()->json(['message' => 'Rebaixado para membro comum.']);
    }

    #[OA\Delete(
        path: '/api/v1/groups/{id}/members/{user_id}',
        summary: 'Remover membro ou sair do grupo',
        tags: ['Grupos'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'user_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Removido')
        ]
    )]
    public function removeMember($groupId, $userId)
    {
        $group = Group::findOrFail($groupId);
        $user = Auth::user();

        if (!$user->is_admin) {
            if ((int)$userId === (int)$group->creator_id) {
                return response()->json(['message' => 'O proprietário não pode ser removido.'], 422);
            }

            $isGroupAdmin = $group->users()->where('user_id', $user->id)->wherePivot('role', 'admin')->exists();
            if (!$isGroupAdmin && $user->id != $userId) {
                return response()->json(['message' => 'Ação negada.'], 403);
            }

            $isTargetAdmin = $group->users()->where('user_id', $userId)->wherePivot('role', 'admin')->exists();
            if ($isTargetAdmin && $group->users()->wherePivot('role', 'admin')->count() <= 1) {
                return response()->json(['message' => 'O grupo precisa de ao menos um administrador.'], 422);
            }
        }

        $group->users()->detach($userId);
        return response()->json(['message' => 'Removido com sucesso.']);
    }

    #[OA\Delete(
        path: '/api/v1/groups/{id}',
        summary: 'Excluir grupo permanentemente',
        tags: ['Grupos'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Excluído')
        ]
    )]
    public function destroy($id)
    {
        $group = Group::findOrFail($id);
        $user = Auth::user();

        if (!$user->is_admin && $group->creator_id !== $user->id) {
            return response()->json(['message' => 'Apenas o dono ou admin do sistema podem excluir o grupo.'], 403);
        }

        $group->users()->detach();
        $group->delete();

        return response()->json(['message' => 'Grupo excluído permanentemente.']);
    }
}
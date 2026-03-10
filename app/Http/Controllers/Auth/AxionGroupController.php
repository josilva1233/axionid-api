<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class AxionGroupController extends Controller
{
    #[OA\Post(
        path: '/api/v1/groups',
        summary: 'Criar novo grupo',
        tags: ['Grupos'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [new OA\Property(property: 'name', type: 'string', example: 'Grupo Financeiro')])
        ),
        responses: [new OA\Response(response: 201, description: 'Grupo criado')]
    )]
    public function store(Request $request)
    {
        $request->validate(['name' => 'required|string|max:255']);
        $group = Group::create(['name' => $request->name, 'creator_id' => Auth::id()]);
        $group->users()->attach(Auth::id(), ['role' => 'admin']);
        return response()->json(['message' => 'Grupo criado com sucesso', 'group' => $group], 201);
    }

    #[OA\Get(
        path: '/api/v1/groups/{id}',
        summary: 'Dados da tela do grupo',
        tags: ['Grupos'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [new OA\Response(response: 200, description: 'Dados do grupo')]
    )]
    public function show($id)
    {
        $group = Group::with('users')->findOrFail($id);
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
            content: new OA\JsonContent(properties: [new OA\Property(property: 'user_id', type: 'integer')])
        ),
        responses: [new OA\Response(response: 200, description: 'Membro adicionado')]
    )]
    public function addMember(Request $request, $groupId)
    {
        $group = Group::findOrFail($groupId);
        $isAdmin = $group->users()->where('user_id', Auth::id())->wherePivot('role', 'admin')->exists();
        if (!$isAdmin) return response()->json(['message' => 'Apenas admins podem convidar.'], 403);
        $request->validate(['user_id' => 'required|exists:users,id']);
        if ($group->users()->where('user_id', $request->user_id)->exists()) {
            return response()->json(['message' => 'Já está no grupo.'], 422);
        }
        $group->users()->attach($request->user_id, ['role' => 'member']);
        return response()->json(['message' => 'Membro adicionado.']);
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
        responses: [new OA\Response(response: 200, description: 'Promovido')]
    )]
    public function promoteMember($groupId, $userId)
    {
        $group = Group::findOrFail($groupId);
        $currentAdmin = $group->users()->where('user_id', Auth::id())->wherePivot('role', 'admin')->exists();
        if (!$currentAdmin) return response()->json(['message' => 'Ação negada.'], 403);
        $group->users()->updateExistingPivot($userId, ['role' => 'admin']);
        return response()->json(['message' => 'Usuário promovido.']);
    }

    #[OA\Delete(
        path: '/api/v1/groups/{group_id}/members/{user_id}',
        summary: 'Remover membro ou Criador do grupo',
        tags: ['Grupos'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'group_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'user_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [new OA\Response(response: 200, description: 'Removido')]
    )]
    public function removeMember($groupId, $userId)
    {
        $group = Group::findOrFail($groupId);
        $currentAdmin = $group->users()->where('user_id', Auth::id())->wherePivot('role', 'admin')->exists();
        if (!$currentAdmin && Auth::id() != $userId) {
            return response()->json(['message' => 'Ação negada.'], 403);
        }
        if ($group->admins()->count() <= 1 && $group->admins()->where('user_id', $userId)->exists()) {
            return response()->json(['message' => 'O grupo precisa de pelo menos um admin.'], 422);
        }
        $group->users()->detach($userId);
        return response()->json(['message' => 'Removido com sucesso.']);
    }
}
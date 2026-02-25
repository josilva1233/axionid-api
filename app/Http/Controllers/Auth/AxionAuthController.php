<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class AxionAuthController extends Controller
{
    #[OA\Post(
        path: '/api/v1/register',
        summary: '1. Registro Inicial (Etapa 1)',
        tags: ['Autenticação'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'João Silva'),
                    new OA\Property(property: 'email', type: 'string', example: 'joao@email.com'),
                    new OA\Property(property: 'cpf_cnpj', type: 'string', example: '12345678901'),
                    new OA\Property(property: 'password', type: 'string', example: 'senha123'),
                    new OA\Property(property: 'password_confirmation', type: 'string', example: 'senha123')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Usuário criado com sucesso'),
            new OA\Response(response: 422, description: 'Erro de validação')
        ]
    )]
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users',
            'cpf_cnpj' => 'required|string|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $document = preg_replace('/[^0-9]/', '', $request->cpf_cnpj);

        $user = User::create([
            'name'      => $request->name,
            'email'     => $request->email,
            'cpf_cnpj'  => $document,
            'password'  => Hash::make($request->password),
            'profile_completed' => false,
            'is_active' => true,
        ]);

        $token = $user->createToken('axion_token')->plainTextToken;

        return response()->json([
            'message' => 'Usuário criado com sucesso!',
            'token'   => $token,
            'user'    => $user
        ], 201);
    }

    #[OA\Post(
        path: '/api/v1/login',
        summary: '2. Autenticar usuário',
        tags: ['Autenticação'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'username', type: 'string', description: 'CPF ou CNPJ', example: '12345678901'),
                    new OA\Property(property: 'password', type: 'string', example: 'senha123')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Login realizado com sucesso'),
            new OA\Response(response: 401, description: 'Credenciais inválidas'),
            new OA\Response(response: 403, description: 'Conta suspensa')
        ]
    )]
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required',
            'password' => 'required',
        ]);

        $loginIdentifier = preg_replace('/[^0-9]/', '', $request->username);
        $user = User::where('cpf_cnpj', $loginIdentifier)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Credenciais inválidas.'], 401);
        }

        // Trava de usuário bloqueado
        if (!$user->is_active) {
            return response()->json(['message' => 'Esta conta foi suspensa por um administrador.'], 403);
        }

        $user->tokens()->delete(); 
        $token = $user->createToken('axion_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'profile_completed' => $user->profile_completed,
            'user' => $user
        ]);
    }

    #[OA\Post(
        path: '/api/v1/complete-profile',
        summary: '3. Completar Cadastro (Etapa 2 - Endereço)',
        tags: ['Perfil'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'zip_code', type: 'string', example: '01001000'),
                    new OA\Property(property: 'street', type: 'string', example: 'Rua Exemplo'),
                    new OA\Property(property: 'number', type: 'string', example: '123'),
                    new OA\Property(property: 'neighborhood', type: 'string', example: 'Bairro Centro'),
                    new OA\Property(property: 'city', type: 'string', example: 'São Paulo'),
                    new OA\Property(property: 'state', type: 'string', example: 'SP'),
                    new OA\Property(property: 'complement', type: 'string', example: 'Apto 1')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Perfil completado com sucesso')
        ]
    )]
    public function completeProfile(Request $request)
    {
        $user = Auth::user();
        $validator = Validator::make($request->all(), [
            'zip_code'     => 'required|string|max:8',
            'street'       => 'required|string',
            'number'       => 'required|string',
            'neighborhood' => 'required|string',
            'city'         => 'required|string',
            'state'        => 'required|string|max:2',
            'complement'   => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $user->address()->create($request->all());
        $user->update(['profile_completed' => true, 'email_verified_at' => now()]);

        return response()->json(['message' => 'Cadastro finalizado!', 'user' => $user->load('address')]);
    }

    // --- MÉTODOS DE ADMINISTRAÇÃO ---

    #[OA\Get(
        path: '/api/v1/users',
        summary: 'Listar usuários com filtros',
        tags: ['Admin'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'name', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'completed', in: 'query', schema: new OA\Schema(type: 'integer', enum: [0, 1]))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de usuários')
        ]
    )]
    public function index(Request $request)
    {
        if (!Auth::user()->is_admin) return response()->json(['message' => 'Acesso negado.'], 403);

        $query = User::with('address');
        if ($request->filled('name')) $query->where('name', 'like', '%' . $request->name . '%');
        if ($request->has('completed')) $query->where('profile_completed', $request->completed);

        return response()->json($query->orderBy('created_at', 'desc')->paginate(10));
    }

    #[OA\Post(
        path: '/api/v1/users/{id}/promote',
        summary: 'Promover usuário a Admin',
        tags: ['Admin'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Promovido com sucesso')]
    )]
    public function promoteToAdmin($id)
    {
        if (!Auth::user()->is_admin) return response()->json(['message' => 'Acesso negado.'], 403);

        $user = User::find($id);
        if (!$user) return response()->json(['message' => 'Usuário não encontrado.'], 404);
        $user->update(['is_admin' => true]);
        return response()->json(['message' => 'Usuário agora é Admin.']);
    }

    #[OA\Patch(
        path: '/api/v1/users/{id}/toggle-status',
        summary: 'Bloquear/Desbloquear usuário',
        tags: ['Admin'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Status alterado com sucesso')]
    )]
    public function toggleUserStatus($id)
    {
        if (!Auth::user()->is_admin) return response()->json(['message' => 'Acesso negado.'], 403);

        $user = User::findOrFail($id);
        if ($user->id === Auth::id()) {
            return response()->json(['message' => 'Você não pode bloquear sua própria conta.'], 400);
        }

        $user->is_active = !$user->is_active;
        $user->save();

        return response()->json(['message' => 'Status atualizado.', 'is_active' => $user->is_active]);
    }

    #[OA\Put(
        path: '/api/v1/users/{id}/update-manual',
        summary: 'Editar usuário manualmente (Admin)',
        tags: ['Admin'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'email', type: 'string'),
                    new OA\Property(property: 'cpf_cnpj', type: 'string')
                ]
            )
        ),
        responses: [new OA\Response(response: 200, description: 'Usuário atualizado')]
    )]
    public function adminUpdateUser(Request $request, $id)
    {
        if (!Auth::user()->is_admin) return response()->json(['message' => 'Acesso negado.'], 403);

        $user = User::findOrFail($id);
        $user->update($request->only(['name', 'email', 'cpf_cnpj']));

        return response()->json(['message' => 'Usuário atualizado com sucesso.', 'user' => $user]);
    }

    #[OA\Get(
        path: '/api/v1/audit-logs',
        summary: 'Visualizar logs de auditoria',
        tags: ['Admin'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'method', in: 'query', schema: new OA\Schema(type: 'string', example: 'POST')),
            new OA\Parameter(name: 'date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'))
        ],
        responses: [new OA\Response(response: 200, description: 'Logs retornados')]
    )]
    public function auditLogs(Request $request)
    {
        if (!Auth::user()->is_admin) return response()->json(['message' => 'Acesso negado.'], 403);

        $query = DB::table('audit_logs')->leftJoin('users', 'audit_logs.user_id', '=', 'users.id');
        if ($request->filled('method')) $query->where('method', strtoupper($request->method));
        if ($request->filled('date')) $query->whereDate('audit_logs.created_at', $request->date);

        return response()->json($query->orderBy('created_at', 'desc')->paginate(20));
    }

    #[OA\Post(
        path: '/api/v1/logout',
        summary: 'Logout',
        tags: ['Autenticação'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Sessão encerrada')]
    )]
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sessão encerrada com sucesso!']);
    }
}
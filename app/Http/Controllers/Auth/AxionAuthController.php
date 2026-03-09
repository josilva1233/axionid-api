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
                    new OA\Property(property: 'google_id', type: 'string', example: '123456789kjooojjd01'),
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
            'name'      => 'required|string|max:255',
            'email'     => 'required|string|email|max:255|unique:users',
            'cpf_cnpj'  => 'required|string|unique:users',
            'password'  => 'required|string|min:8|confirmed',
            'google_id' => 'nullable|string', 
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $document = preg_replace('/[^0-9]/', '', $request->cpf_cnpj);

        $user = User::create([
            'name'              => $request->name,
            'email'             => $request->email,
            'cpf_cnpj'          => $document,
            'password'          => Hash::make($request->password),
            'profile_completed' => false,
            'google_id'         => $request->google_id, 
            'is_active'         => true,
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
            new OA\Response(response: 200, description: 'Perfil completado com sucesso'),
            new OA\Response(response: 422, description: 'Erro de validação')
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

    #[OA\Get(
        path: '/api/v1/admin/users',
        summary: 'Listar usuários (Admin)',
        tags: ['Administração'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'name', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'completed', in: 'query', required: false, schema: new OA\Schema(type: 'boolean'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de usuários paginada'),
            new OA\Response(response: 403, description: 'Acesso negado')
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
        path: '/api/v1/admin/users/{id}/promote',
        summary: 'Promover usuário a Admin',
        tags: ['Administração'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Usuário promovido'),
            new OA\Response(response: 404, description: 'Usuário não encontrado')
        ]
    )]
    public function promoteToAdmin($id)
    {
        if (!Auth::user()->is_admin) return response()->json(['message' => 'Acesso negado.'], 403);

        $user = User::find($id);
        if (!$user) return response()->json(['message' => 'Usuário não encontrado.'], 404);
        $user->update(['is_admin' => true]);
        return response()->json(['message' => 'Usuário agora é Admin.']);
    }

    #[OA\Post(
        path: '/api/v1/admin/users/{id}/toggle-status',
        summary: 'Ativar/Desativar usuário',
        tags: ['Administração'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Status atualizado'),
            new OA\Response(response: 400, description: 'Erro na operação')
        ]
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
        path: '/api/v1/admin/users/{id}',
        summary: 'Atualizar usuário e endereço (Admin)',
        description: 'Permite que um administrador atualize o nome, e-mail e endereço do usuário.',
        tags: ['Administração'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID do usuário', schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'João Silva Atualizado'),
                    new OA\Property(property: 'email', type: 'string', example: 'joao.novo@email.com'),
                    new OA\Property(property: 'zip_code', type: 'string', example: '01001000'),
                    new OA\Property(property: 'street', type: 'string', example: 'Nova Rua Exemplo'),
                    new OA\Property(property: 'number', type: 'string', example: '456'),
                    new OA\Property(property: 'neighborhood', type: 'string', example: 'Bairro Novo'),
                    new OA\Property(property: 'city', type: 'string', example: 'São Paulo'),
                    new OA\Property(property: 'state', type: 'string', example: 'SP'),
                    new OA\Property(property: 'complement', type: 'string', example: 'Bloco B')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Usuário atualizado com sucesso'),
            new OA\Response(response: 403, description: 'Acesso negado'),
            new OA\Response(response: 404, description: 'Usuário não encontrado')
        ]
    )]
    public function adminUpdateUser(Request $request, $id)
    {
        if (!Auth::user()->is_admin) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $user = User::with('address')->find($id);

        if (!$user) {
            return response()->json(['message' => 'Usuário não encontrado.'], 404);
        }

        $user->update($request->only(['name', 'email']));

        $addressData = $request->only([
            'zip_code', 'street', 'number', 
            'neighborhood', 'city', 'state', 'complement'
        ]);

        if (!empty($addressData)) {
            if ($user->address) {
                $user->address->update($addressData);
            } else {
                $user->address()->create($addressData);
            }
        }

        return response()->json([
            'message' => 'Usuário e endereço atualizados com sucesso.',
            'user' => $user->load('address') 
        ]);
    }

    #[OA\Get(
        path: '/api/v1/admin/audit-logs',
        summary: 'Ver logs de auditoria (Admin)',
        tags: ['Administração'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'method', in: 'query', schema: new OA\Schema(type: 'string', example: 'POST')),
            new OA\Parameter(name: 'date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Logs recuperados com sucesso')
        ]
    )]
    public function auditLogs(Request $request)
    {
        if (!Auth::user()->is_admin) return response()->json(['message' => 'Acesso negado.'], 403);

        $query = DB::table('audit_logs')
            ->leftJoin('users', 'audit_logs.user_id', '=', 'users.id')
            ->select(
                'audit_logs.id as log_id',
                'audit_logs.method',
                'audit_logs.url',
                'audit_logs.ip_address',
                'audit_logs.payload',
                'audit_logs.created_at as executed_at',
                'users.name as user_name',
                'users.email as user_email'
            );

        if ($request->filled('method')) {
            $query->where('audit_logs.method', strtoupper($request->method));
        }

        if ($request->filled('date')) {
            $query->whereDate('audit_logs.created_at', $request->date);
        }

        return response()->json($query->orderBy('audit_logs.created_at', 'desc')->paginate(20));
    }

    #[OA\Post(
        path: '/api/v1/logout',
        summary: 'Encerrar sessão',
        tags: ['Autenticação'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Sessão encerrada')
        ]
    )]
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sessão encerrada com sucesso!']);
    }

    #[OA\Get(
        path: '/api/v1/admin/users/{id}',
        summary: 'Visualizar detalhes do usuário (Admin)',
        tags: ['Administração'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dados do usuário retornados'),
            new OA\Response(response: 404, description: 'Usuário não encontrado')
        ]
    )]
    public function show($id)
    {
        if (!Auth::user()->is_admin) return response()->json(['message' => 'Acesso negado.'], 403);

        $user = User::with('address')->find($id);

        if (!$user) return response()->json(['message' => 'Usuário não encontrado.'], 404);

        return response()->json(['data' => $user]);
    }

    #[OA\Delete(
        path: '/api/v1/admin/users/{id}',
        summary: 'Deletar usuário (Admin)',
        tags: ['Administração'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Usuário deletado'),
            new OA\Response(response: 404, description: 'Usuário não encontrado')
        ]
    )]
    public function destroy($id)
    {
        if (!Auth::user()->is_admin) return response()->json(['message' => 'Acesso negado.'], 403);

        $user = User::find($id);
        if (!$user) return response()->json(['message' => 'Usuário não encontrado.'], 404);

        $user->delete();
        return response()->json(['message' => 'Usuário deletado com sucesso.']);
    }

    #[OA\Post(
        path: '/api/v1/admin/users/{id}/remove-admin',
        summary: 'Remover privilégios de Admin',
        tags: ['Administração'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Privilégios removidos'),
            new OA\Response(response: 400, description: 'Erro na operação')
        ]
    )]
    public function removeAdmin($id)
    {
        if (!Auth::user()->is_admin) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $user = User::find($id);
        
        if (!$user) {
            return response()->json(['message' => 'Usuário não encontrado.'], 404);
        }

        if ($user->id === Auth::id()) {
            return response()->json(['message' => 'Você não pode remover seu próprio acesso administrativo.'], 400);
        }

        $user->update(['is_admin' => false]);
        
        return response()->json(['message' => 'Privilégios administrativos removidos com sucesso.']);
    }
}
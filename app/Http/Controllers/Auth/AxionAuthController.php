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
                    new OA\Property(property: 'google_id', type: 'string', example: '123456789kjooojjd01', nullable: true),
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
                    new OA\Property(property: 'complement', type: 'string', example: 'Apto 1', nullable: true)
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

    #[OA\Put(
        path: '/api/v1/admin/users/{id}',
        summary: 'Atualizar usuário e endereço (Admin)',
        description: 'Permite que um administrador atualize dados do usuário e endereço. Registra quem fez a alteração.',
        tags: ['Administração'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
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
                    new OA\Property(property: 'complement', type: 'string', example: 'Bloco B', nullable: true)
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200, 
                description: 'Sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'admin_info', type: 'object', properties: [
                            new OA\Property(property: 'admin_id', type: 'integer'),
                            new OA\Property(property: 'updated_at', type: 'string')
                        ])
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Acesso negado')
        ]
    )]
    public function adminUpdateUser(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $admin = Auth::user();

        if (!$admin->is_admin) return response()->json(['message' => 'Acesso negado.'], 403);

        $validated = $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $id,
            'zip_code'     => 'nullable|string',
            'street'       => 'nullable|string',
            'number'       => 'nullable|string',
            'neighborhood' => 'nullable|string',
            'city'         => 'nullable|string',
            'state'        => 'nullable|string',
        ]);

        $user->update([
            'name'              => $validated['name'],
            'email'             => $validated['email'],
            'profile_completed' => true,
        ]);

        $user->address()->updateOrCreate(
            ['user_id' => $user->id],
            array_merge(
                $request->only(['zip_code', 'street', 'number', 'neighborhood', 'city', 'state', 'complement']),
                [
                    'updated_by_admin_id' => $admin->id,
                    'admin_updated_at'    => now(),
                ]
            )
        );

        return response()->json([
            'message' => 'Usuário atualizado com sucesso pelo administrador',
            'admin_info' => [
                'admin_id' => $admin->id,
                'updated_at' => now()->toDateTimeString()
            ]
        ]);
    }

    #[OA\Get(
        path: '/api/v1/admin/audit-logs',
        summary: 'Ver logs de auditoria (Admin)',
        tags: ['Administração'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Logs de auditoria'),
            new OA\Response(response: 403, description: 'Acesso negado')
        ]
    )]
    public function auditLogs(Request $request)
    {
        if (!Auth::user()->is_admin) return response()->json(['message' => 'Acesso negado.'], 403);
        $query = DB::table('audit_logs')
            ->leftJoin('users', 'audit_logs.user_id', '=', 'users.id')
            ->select('audit_logs.*', 'users.name as user_name', 'users.email as user_email');

        if ($request->filled('method')) $query->where('audit_logs.method', strtoupper($request->method));
        if ($request->filled('date')) $query->whereDate('audit_logs.created_at', $request->date);

        return response()->json($query->orderBy('audit_logs.created_at', 'desc')->paginate(20));
    }

    #[OA\Post(
        path: '/api/v1/logout',
        summary: 'Encerrar sessão',
        tags: ['Autenticação'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Logout realizado com sucesso')
        ]
    )]
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sessão encerrada com sucesso!']);
    }

    #[OA\Get(
        path: '/api/v1/admin/users/{id}',
        summary: 'Exibir detalhes de um usuário (Admin)',
        tags: ['Administração'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dados do usuário'),
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
            new OA\Response(response: 200, description: 'Usuário removido'),
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

    #[OA\Patch(
        path: '/api/v1/admin/users/{id}/toggle-status',
        summary: 'Ativar/Desativar usuário (Admin)',
        tags: ['Administração'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Status atualizado')
        ]
    )]
    public function toggleUserStatus($id)
    {
        if (!Auth::user()->is_admin) return response()->json(['message' => 'Acesso negado.'], 403);
        $user = User::findOrFail($id);
        if ($user->id === Auth::id()) return response()->json(['message' => 'Você não pode bloquear sua própria conta.'], 400);
        $user->is_active = !$user->is_active;
        $user->save();
        return response()->json(['message' => 'Status atualizado.', 'is_active' => $user->is_active]);
    }

    #[OA\Post(
        path: '/api/v1/admin/users/{id}/promote',
        summary: 'Promover a Administrador',
        tags: ['Administração'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Promovido com sucesso')
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
        path: '/api/v1/admin/users/{id}/demote',
        summary: 'Remover privilégios de Admin',
        tags: ['Administração'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Privilégios removidos')
        ]
    )]
    public function removeAdmin($id)
    {
        if (!Auth::user()->is_admin) return response()->json(['message' => 'Acesso negado.'], 403);
        $user = User::find($id);
        if (!$user) return response()->json(['message' => 'Usuário não encontrado.'], 404);
        if ($user->id === Auth::id()) return response()->json(['message' => 'Impossível remover a si mesmo.'], 400);
        $user->update(['is_admin' => false]);
        return response()->json(['message' => 'Privilégios removidos com sucesso.']);
    }
    public function findByEmail($email)
    {
        // O e-mail vem da URL
        $user = User::where('email', $email)->first();

        if (!$user) {
            return response()->json(['message' => 'E-mail não encontrado no sistema.'], 404);
        }

        // Retornamos apenas o ID e o Nome para o Front-end
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email
        ]);
    }
}
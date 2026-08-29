<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
                    new OA\Property(property: 'password', type: 'string', example: 'senha123'),
                    new OA\Property(property: 'captcha_token', type: 'string', example: 'token_do_google')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Login realizado com sucesso'),
            new OA\Response(response: 401, description: 'Credenciais inválidas'),
            new OA\Response(response: 403, description: 'Conta suspensa'),
            new OA\Response(response: 422, description: 'Falha no Captcha')
        ]
    )]
    public function login(Request $request)
    {
        $validated = $request->validate([
            'username'      => 'required|string',
            'password'      => 'required|string',
            'captcha_token' => 'required|string',
        ]);

        $captchaResponse = Http::asForm()->post(
            'https://www.google.com/recaptcha/api/siteverify',
            [
                'secret'   => env('RECAPTCHA_SECRET_KEY'),
                'response' => $validated['captcha_token'],
                'remoteip' => $request->ip(),
            ]
        );

        if (!$captchaResponse->successful()) {
            \Log::error('Erro HTTP ao consultar Google reCAPTCHA', [
                'status' => $captchaResponse->status(),
                'body'   => $captchaResponse->body(),
            ]);

            return response()->json([
                'message' => 'Não foi possível verificar a segurança do acesso.'
            ], 422);
        }

        $captchaData = $captchaResponse->json();

        if (!($captchaData['success'] ?? false)) {
            \Log::error('Falha no reCAPTCHA AxionID', [
                'erros'    => $captchaData['error-codes'] ?? [],
                'hostname' => $captchaData['hostname'] ?? null,
                'ip'       => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Falha na verificação de segurança (Bot detectado).',
                'debug_info' => $captchaData['error-codes'] ?? [],
            ], 422);
        }

        $login = trim($validated['username']);
        $document = preg_replace('/[^0-9]/', '', $login);

        if (ctype_digit($document) && (strlen($document) === 11 || strlen($document) === 14)) {
            $user = User::where('cpf_cnpj', $document)->first();
        } else {
            $user = User::whereRaw('LOWER(email) = ?', [strtolower($login)])->first();
        }

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => 'CPF/e-mail ou senha inválidos.'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'Esta conta foi suspensa por um administrador.'], 403);
        }

        $user->tokens()->delete();
        $token = $user->createToken('axion_token')->plainTextToken;

        return response()->json([
            'token'             => $token,
            'profile_completed' => $user->profile_completed,
            'user'              => $user
        ], 200);
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

    /**
     * =========================================================
     * MÉTODO ADICIONADO: Atualizar perfil do usuário logado
     * =========================================================
     */
    #[OA\Put(
        path: '/api/v1/update-profile',
        summary: 'Atualizar perfil do usuário autenticado',
        tags: ['Perfil'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'João Silva Atualizado'),
                    new OA\Property(property: 'email', type: 'string', example: 'novoemail@email.com'),
                    new OA\Property(property: 'cpf_cnpj', type: 'string', example: '12345678901'),
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
            new OA\Response(response: 200, description: 'Perfil atualizado com sucesso'),
            new OA\Response(response: 422, description: 'Erro de validação')
        ]
    )]
    public function updateProfile(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'cpf_cnpj' => 'sometimes|string|max:20',
            'zip_code' => 'nullable|string|max:10',
            'street' => 'nullable|string|max:255',
            'number' => 'nullable|string|max:20',
            'neighborhood' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:2',
            'complement' => 'nullable|string|max:255',
        ]);

        // Atualiza dados do usuário
        $user->update($validated);

        // Atualiza ou cria endereço
        $addressData = $request->only([
            'zip_code', 'street', 'number', 'neighborhood', 'city', 'state', 'complement'
        ]);

        if ($user->address) {
            $user->address->update($addressData);
        } else {
            $user->address()->create($addressData);
        }

        return response()->json($user->load('address'));
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
            'complement'   => 'nullable|string',
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
            'user' => $user->load('address'),
            'admin_info' => [
                'admin_id' => $admin->id,
                'updated_at' => now()->toDateTimeString()
            ]
        ]);
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
        $user = User::where('email', $email)->first();

        if (!$user) {
            return response()->json(['message' => 'E-mail não encontrado no sistema.'], 404);
        }

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email
        ]);
    }
        /**
     * 🔥 OBTER PERMISSÕES DO USUÁRIO LOGADO
     * GET /api/v1/me/permissions
     * 
     * Retorna todas as permissões do usuário (diretas + grupos + roles)
     */
    public function getMyPermissions(Request $request)
    {
        try {
            $user = auth()->user();
            
            if (!$user) {
                return response()->json([
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            // Se for admin, retorna todas as permissões do sistema
            if ($user->is_admin) {
                $permissions = Permission::all();
                return response()->json([
                    'permissions' => $permissions,
                    'total' => $permissions->count(),
                    'is_admin' => true,
                ]);
            }

            // Buscar permissões do usuário (diretas + grupos + roles)
            $permissions = $user->getAllPermissions();

            // 🔥 DETALHAR ORIGEM DAS PERMISSÕES
            $source = [
                'direct' => $user->permissions->pluck('name')->toArray(),
                'groups' => [],
                'roles' => [],
            ];

            foreach ($user->groups as $group) {
                $source['groups'][$group->name] = $group->permissions->pluck('name')->toArray();
            }

            foreach ($user->roles as $role) {
                $source['roles'][$role->name] = $role->permissions->pluck('name')->toArray();
            }

            return response()->json([
                'permissions' => $permissions,
                'total' => $permissions->count(),
                'is_admin' => false,
                'source' => $source,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erro ao buscar permissões do usuário:', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'message' => 'Erro ao buscar permissões',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
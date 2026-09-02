<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Autenticação Social', description: 'Login e cadastro via provedores sociais')]
class SocialAuthController extends Controller
{
    #[OA\Get(
        path: '/api/v1/auth/google',
        summary: '1. Redirecionar para o Google',
        description: 'Inicia o fluxo OAuth2. O parâmetro "origin" define para onde o usuário volta após o login.',
        tags: ['Autenticação Social'],
        parameters: [
            new OA\Parameter(
                name: 'origin',
                in: 'query',
                description: 'URL do frontend (ex: http://localhost:3000)',
                required: false,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(response: 302, description: 'Redirecionamento para a conta Google')
        ]
    )]
    public function redirectToGoogle(Request $request)
    {
        $origin = $request->query('origin', config('app.frontend_url'));
        
        return Socialite::driver('google')
            ->stateless()
            ->with(['state' => 'origin=' . $origin])
            ->redirect();
    }

    #[OA\Get(
        path: '/api/v1/auth/google/callback',
        summary: '2. Callback do Google',
        description: 'Endpoint chamado pelo Google após autenticação. Redireciona para o frontend com token ou erro.',
        tags: ['Autenticação Social'],
        parameters: [
            new OA\Parameter(
                name: 'state',
                in: 'query',
                description: 'Parâmetro de estado contendo a origin do frontend',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'code',
                in: 'query',
                description: 'Código de autorização retornado pelo Google',
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(response: 302, description: 'Redireciona para /register ou /login com token')
        ]
    )]
    public function handleGoogleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
            
            $user = User::where('google_id', $googleUser->id)
                        ->orWhere('email', $googleUser->email)
                        ->first();

            if ($user && $user->is_active == 0) {
                $state = $request->input('state');
                parse_str($state, $result);
                $frontendUrl = rtrim($result['origin'] ?? config('app.frontend_url'), '/');
                return redirect("{$frontendUrl}/login?error=account_suspended");
            }
            
            if (!$user) {
                $user = User::create([
                    'name'              => $googleUser->name,
                    'email'             => $googleUser->email,
                    'google_id'         => $googleUser->id,
                    'password'          => Hash::make(Str::random(24)),
                    'from_google'       => true,
                    'profile_completed' => false,
                ]);
            } else {
                $user->update(['google_id' => $googleUser->id]);
            }

            $token = $user->createToken('axion_token')->plainTextToken;
            $state = $request->input('state');
            parse_str($state, $result);
            $frontendUrl = rtrim($result['origin'] ?? config('app.frontend_url'), '/');

            if (empty($user->cpf_cnpj)) {
                $params = http_build_query([
                    'token'       => $token,
                    'step'        => 2,
                    'from_google' => 'true',
                    'name'        => $user->name,
                    'email'       => $user->email
                ]);
                return redirect("{$frontendUrl}/register?{$params}");
            }

            return redirect("{$frontendUrl}/login?token={$token}");

        } catch (\Exception $e) {
            return redirect(config('app.frontend_url') . "/?error=auth_failed");
        }
    }

    #[OA\Post(
        path: '/api/v1/complete-profile',
        summary: '3. Finalizar cadastro (Google ou manual)',
        description: 'Completa o perfil do usuário com CPF/CNPJ e senha. Para usuários vindos do Google, também pode receber dados de endereço.',
        tags: ['Autenticação Social'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'cpf_cnpj', type: 'string', example: '12345678901'),
                    new OA\Property(property: 'password', type: 'string', example: 'senha123'),
                    new OA\Property(property: 'password_confirmation', type: 'string', example: 'senha123'),
                    new OA\Property(property: 'from_google', type: 'boolean', example: true, description: 'Indica se o cadastro veio do Google'),
                    new OA\Property(property: 'zip_code', type: 'string', example: '01001000'),
                    new OA\Property(property: 'street', type: 'string', example: 'Rua Exemplo'),
                    new OA\Property(property: 'number', type: 'string', example: '123'),
                    new OA\Property(property: 'neighborhood', type: 'string', example: 'Centro'),
                    new OA\Property(property: 'city', type: 'string', example: 'São Paulo'),
                    new OA\Property(property: 'state', type: 'string', example: 'SP'),
                    new OA\Property(property: 'complement', type: 'string', example: 'Apto 101', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Perfil finalizado com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'user', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Não autorizado'),
            new OA\Response(response: 422, description: 'Erro de validação')
        ]
    )]
    public function completeProfile(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Não autorizado.'], 401);
        }

        $request->validate([
            'cpf_cnpj' => 'required|string|unique:users,cpf_cnpj,' . $user->id,
            'password' => 'required|min:6|confirmed',
        ]);

        return DB::transaction(function () use ($request, $user) {
            
            $isFromGoogle = $request->input('from_google') === 'true' || $request->input('from_google') === true;
            
            $user->update([
                'cpf_cnpj'          => $request->cpf_cnpj,
                'password'          => Hash::make($request->password),
                'profile_completed' => $isFromGoogle ? false : true,
                'from_google'       => $isFromGoogle,
            ]);

            if ($request->has('zip_code') && $request->zip_code) {
                $user->address()->updateOrCreate(
                    ['user_id' => $user->id],
                    $request->only(['zip_code', 'street', 'number', 'neighborhood', 'city', 'state', 'complement'])
                );
                $user->update(['profile_completed' => true]);
            }

            return response()->json([
                'message' => 'Dados atualizados com sucesso!',
                'user'    => $user->fresh()->load('address')
            ]);
        });
    }

    #[OA\Get(
        path: '/api/v1/users/find-by-email/{email}',
        summary: 'Buscar usuário por e-mail (admin)',
        description: 'Retorna informações básicas de um usuário a partir do e-mail. Restrito a administradores.',
        tags: ['Usuários'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(
                name: 'email',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string'),
                example: 'joao@email.com'
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dados do usuário',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'email', type: 'string'),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Acesso negado'),
            new OA\Response(response: 404, description: 'Usuário não encontrado')
        ]
    )]
    public function findByEmail($email)
    {
        $user = \App\Models\User::where('email', $email)->first();

        if (!$user) {
            return response()->json(['message' => 'Usuário não encontrado'], 404);
        }

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email
        ]);
    }
}
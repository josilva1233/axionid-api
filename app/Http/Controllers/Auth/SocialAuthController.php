<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use OpenApi\Attributes as OA;

class SocialAuthController extends Controller
{
    #[OA\Get(
        path: '/api/v1/auth/google/redirect',
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
        // Usa config() em vez de env() para evitar erros de cache
        $origin = $request->query('origin', config('app.frontend_url'));
        
        return Socialite::driver('google')
            ->stateless()
            ->with(['state' => 'origin=' . $origin])
            ->redirect();
    }

    #[OA\Get(
        path: '/api/v1/auth/google/callback',
        summary: '2. Callback do Google',
        description: 'Endpoint processado pelo Google. Faz o login ou pré-cadastro e redireciona o navegador de volta para o frontend.',
        tags: ['Autenticação Social'],
        responses: [
            new OA\Response(response: 302, description: 'Redireciona para /register (se novo) ou /login (se existente)')
        ]
    )]
    public function handleGoogleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
            
            // Busca usuário existente ou prepara criação
            $user = User::where('google_id', $googleUser->id)
                        ->orWhere('email', $googleUser->email)
                        ->first();

            if ($user && $user->is_active == 0) {
                $state = $request->input('state');
                parse_str($state, $result);
                $frontendUrl = rtrim($result['origin'] ?? config('app.frontend_url'), '/');

                // Redireciona com um parâmetro de erro específico
                return redirect("{$frontendUrl}/login?error=account_suspended");
            }
            
            if (!$user) {
                // PRIMEIRO GRAVAÇÃO: Apenas dados básicos do Google
                $user = User::create([
                    'name'              => $googleUser->name,
                    'email'             => $googleUser->email,
                    'google_id'         => $googleUser->id,
                    'password'          => Hash::make(Str::random(24)), // Senha provisória
                    'from_google'       => true,
                    'profile_completed' => false,
                ]);
            } else {
                // Atualiza o ID do Google caso tenha entrado por e-mail antes
                $user->update(['google_id' => $googleUser->id]);
            }

            // Gera o Token para o usuário poder chamar o 'completeProfile'
            $token = $user->createToken('axion_token')->plainTextToken;

            $state = $request->input('state');
            parse_str($state, $result);
            $frontendUrl = rtrim($result['origin'] ?? config('app.frontend_url'), '/');

            // SE NÃO TEM CPF: Redireciona para completar (Step 2)
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

            // SE JÁ TEM CPF: Vai direto para o Dashboard
            return redirect("{$frontendUrl}/login?token={$token}");

        } catch (\Exception $e) {
            return redirect(config('app.frontend_url') . "/?error=auth_failed");
        }
    }

    #[OA\Post(
        path: '/api/v1/auth/google/complete-profile',
        summary: '3. Finalizar cadastro (Google)',
        description: 'Após o retorno do Google, o usuário deve definir CPF, Senha e Endereço para ativar a conta.',
        tags: ['Autenticação Social'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'cpf_cnpj', type: 'string', example: '12345678901'),
                    new OA\Property(property: 'password', type: 'string', example: 'nova_senha123'),
                    new OA\Property(property: 'password_confirmation', type: 'string', example: 'nova_senha123'),
                    new OA\Property(property: 'zip_code', type: 'string', example: '01001000'),
                    new OA\Property(property: 'street', type: 'string', example: 'Rua Exemplo'),
                    new OA\Property(property: 'number', type: 'string', example: '10'),
                    new OA\Property(property: 'neighborhood', type: 'string', example: 'Centro'),
                    new OA\Property(property: 'city', type: 'string', example: 'São Paulo'),
                    new OA\Property(property: 'state', type: 'string', example: 'SP'),
                    new OA\Property(property: 'complement', type: 'string', example: 'Apto 101')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Perfil finalizado com sucesso'),
            new OA\Response(response: 401, description: 'Não autorizado'),
            new OA\Response(response: 422, description: 'Erro de validação ou CPF já em uso')
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

        $user->update([
            'cpf_cnpj'          => $request->cpf_cnpj,
            'password'          => Hash::make($request->password),
            'profile_completed' => true,
        ]);

        // 3. SALVAR O ENDEREÇO
        $user->address()->updateOrCreate(
            ['user_id' => $user->id],
            $request->only(['zip_code', 'street', 'number', 'neighborhood', 'city', 'state', 'complement'])
        );       

        return response()->json([
            'message' => 'Cadastro finalizado com sucesso!',
            'user'    => $user->load('address')
        ]);
    }
}
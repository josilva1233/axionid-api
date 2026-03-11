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
        $origin = $request->query('origin', config('app.frontend_url'));
        
        return Socialite::driver('google')
            ->stateless()
            ->with(['state' => 'origin=' . $origin])
            ->redirect();
    }

    #[OA\Get(
        path: '/api/v1/auth/google/callback',
        summary: '2. Callback do Google',
        tags: ['Autenticação Social'],
        responses: [
            new OA\Response(response: 302, description: 'Redireciona para /register ou /login')
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
                    'profile_completed' => false, // No primeiro acesso via Google, inicia como 0
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
        path: '/api/v1/auth/google/complete-profile',
        summary: '3. Finalizar cadastro (Google)',
        tags: ['Autenticação Social'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'cpf_cnpj', type: 'string'),
                    new OA\Property(property: 'password', type: 'string'),
                    new OA\Property(property: 'password_confirmation', type: 'string')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Perfil finalizado com sucesso')
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
            // ATUALIZAÇÃO: Mudamos para true para gravar 1 no banco de dados
            $user->update([
                'cpf_cnpj'          => $request->cpf_cnpj,
                'password'          => Hash::make($request->password),
                'profile_completed' => true, // Aqui ele muda de 0 para 1
                'from_google'       => true,
            ]);

            if ($request->has('zip_code')) {
                $user->address()->updateOrCreate(
                    ['user_id' => $user->id],
                    $request->only(['zip_code', 'street', 'number', 'neighborhood', 'city', 'state', 'complement'])
                );
            }

            return response()->json([
                'message' => 'Cadastro finalizado com sucesso!',
                'user'    => $user->fresh()->load('address') // fresh() garante pegar os dados novos
            ]);
        });
    }
}
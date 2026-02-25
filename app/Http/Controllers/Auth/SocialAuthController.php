<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use OpenApi\Attributes as OA;

class SocialAuthController extends Controller
{
    #[OA\Get(
        path: '/api/v1/auth/google',
        summary: 'Redirecionar para login do Google',
        description: 'Envia o usuário para a tela de autenticação oficial do Google.',
        tags: ['Autenticação Social']
    )]
    #[OA\Response(response: 302, description: 'Redirecionamento para o Google')]
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    #[OA\Get(
        path: '/api/v1/auth/google/callback',
        summary: 'Callback da autenticação Google',
        description: 'Endpoint que recebe os dados do Google e gera o token de acesso da AxionID.',
        tags: ['Autenticação Social']
    )]
    #[OA\Response(
        response: 200,
        description: 'Login realizado com sucesso',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Login via Google realizado!'),
                new OA\Property(property: 'token', type: 'string', example: '39|vmmXWH...'),
                new OA\Property(property: 'user', type: 'object')
            ]
        )
    )]
    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
            
            $user = User::where('google_id', $googleUser->id)
                        ->orWhere('email', $googleUser->email)
                        ->first();

            if (!$user) {
                $user = User::create([
                    'name' => $googleUser->name,
                    'email' => $googleUser->email,
                    'google_id' => $googleUser->id,
                    'password' => null,
                    'profile_completed' => false,
                ]);
            } else {
                $user->update(['google_id' => $googleUser->id]);
            }

            $token = $user->createToken('axion_token')->plainTextToken;

            return response()->json([
                'message' => 'Login via Google realizado!',
                'token' => $token,
                'user' => $user
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Falha na autenticação: ' . $e->getMessage()], 401);
        }
    }
}
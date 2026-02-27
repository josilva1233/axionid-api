<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use OpenApi\Attributes as OA;
use Illuminate\Http\RedirectResponse;

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
        description: 'Recebe os dados do Google, gera o token e redireciona para o Front-end.',
        tags: ['Autenticação Social']
    )]
    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
            
            // Procura usuário pelo Google ID ou Email
            $user = User::where('google_id', $googleUser->id)
                        ->orWhere('email', $googleUser->email)
                        ->first();

            if (!$user) {
                // Cria novo usuário se não existir
                $user = User::create([
                    'name' => $googleUser->name,
                    'email' => $googleUser->email,
                    'google_id' => $googleUser->id,
                    'password' => null, // Login social não tem senha inicial
                    'profile_completed' => false,
                    'is_active' => true
                ]);
            } else {
                // Atualiza o ID do Google caso tenha logado antes por e-mail/senha
                $user->update(['google_id' => $googleUser->id]);
            }

            // Gera o Token de acesso (Sanctum)
            $token = $user->createToken('axion_token')->plainTextToken;

            // Define se precisa passar pelo Onboarding de CPF
            $needsCpf = empty($user->cpf_cnpj) ? 'true' : 'false';

            // --- A MÁGICA ACONTECE AQUI ---
            // Montamos a URL do seu Front-end passando o Token e o Status
            $redirectUrl = "http://localhost:5173/?token={$token}&needs_cpf={$needsCpf}";

            return redirect($redirectUrl);

        } catch (\Exception $e) {
            // Se der erro, volta para o login com uma mensagem de erro na URL
            return redirect("http://localhost:5173/?error=auth_failed&details=" . urlencode($e->getMessage()));
        }
    }
}
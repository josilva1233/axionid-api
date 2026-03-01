<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    /**
     * Redireciona para o Google
     */
    public function redirectToGoogle(Request $request)
    {
        $origin = $request->query('origin', env('FRONTEND_URL'));
        return Socialite::driver('google')
            ->stateless()
            ->with(['state' => 'origin=' . $origin])
            ->redirect();
    }

    /**
     * Callback do Google com Lógica de Vínculo e Redirecionamento Inteligente
     */
    public function handleGoogleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
            
            // 1. Tenta encontrar por google_id OU por email (Vínculo de Contas)
            $user = User::where('google_id', $googleUser->id)
                        ->orWhere('email', $googleUser->email)
                        ->first();

            if (!$user) {
                // 2. Se não existe de jeito nenhum, cria um novo
                $user = User::create([
                    'name'      => $googleUser->name,
                    'email'     => $googleUser->email,
                    'google_id' => $googleUser->id,
                    'password'  => Hash::make(Str::random(24)),
                    'from_google' => true,
                ]);
            } else {
                // 3. VÍNCULO AUTOMÁTICO: 
                // Se achou pelo email mas não tinha google_id, atualiza agora.
                $user->update([
                    'google_id' => $googleUser->id,
                    'from_google' => true 
                ]);
            }

            // 4. Gera o token de acesso (Sanctum)
            $token = $user->createToken('axion_token')->plainTextToken;

            // Recupera a URL de origem do state
            $state = $request->input('state');
            parse_str($state, $result);
            $frontendUrl = $result['origin'] ?? env('FRONTEND_URL');

            // 5. FLUXO DE REDIRECIONAMENTO INTELIGENTE
            // Caso A: Usuário manual antigo ou novo SEM CPF -> Manda completar perfil
            if (empty($user->cpf_cnpj)) {
                $params = http_build_query([
                    'token' => $token,
                    'step' => 2,
                    'from_google' => 'true',
                    'name' => $user->name,
                    'email' => $user->email
                ]);
                return redirect("{$frontendUrl}/register?{$params}");
            }

            // Caso B: Usuário já tem CPF (já era cadastrado e apenas vinculou o Google) -> Loga direto
            return redirect("{$frontendUrl}/dashboard?token={$token}");

        } catch (\Exception $e) {
            return redirect(env('FRONTEND_URL') . "/?error=auth_failed");
        }
    }

    /**
     * Completa o perfil gravando apenas CPF/CNPJ e Senha.
     * O usuário é identificado automaticamente pelo Token (Sanctum).
     */
    public function completeProfile(Request $request)
    {
        // 1. Pega o usuário autenticado pelo token enviado no Header Authorization
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Usuário não autenticado.'], 401);
        }

        // 2. Valida os dados (O CPF deve ser único, ignorando o próprio ID do usuário)
        $request->validate([
            'cpf_cnpj' => 'required|string|unique:users,cpf_cnpj,' . $user->id,
            'password' => 'required|min:6|confirmed',
        ]);

        // 3. Faz o update apenas das colunas necessárias para finalizar o onboarding
        $user->update([
            'cpf_cnpj' => $request->cpf_cnpj,
            'password'  => Hash::make($request->password),
            'profile_completed' => true, 
        ]);

        return response()->json([
            'message' => 'Cadastro finalizado com sucesso!',
            'user' => $user
        ]);
    }
}
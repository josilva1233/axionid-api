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
    public function redirectToGoogle(Request $request)
    {
        // Usa config() em vez de env() para evitar erros de cache
        $origin = $request->query('origin', config('app.frontend_url'));
        
        return Socialite::driver('google')
            ->stateless()
            ->with(['state' => 'origin=' . $origin])
            ->redirect();
    }

    public function handleGoogleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
            
            // Busca usuário existente ou prepara criação
            $user = User::where('google_id', $googleUser->id)
                        ->orWhere('email', $googleUser->email)
                        ->first();

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
            return redirect("{$frontendUrl}/dashboard?token={$token}");

        } catch (\Exception $e) {
            return redirect(config('app.frontend_url') . "/?error=auth_failed");
        }
    }

    /**
     * SEGUNDA GRAVAÇÃO: Faz o update do CPF e Senha definitiva
     * Rota: POST /api/v1/complete-profile
     * Middleware: auth:sanctum
     */
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

        return response()->json([
            'message' => 'Cadastro finalizado com sucesso!',
            'user'    => $user
        ]);
    }
}
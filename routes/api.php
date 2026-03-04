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
     * Inicia o fluxo do Google.
     * URL: /api/v1/auth/google?origin=https://seu-front.com
     */
    public function redirectToGoogle(Request $request)
    {
        // Pega a URL do front que chamou ou usa a padrão do config
        $origin = $request->query('origin', config('app.frontend_url'));

        // Passamos a origem dentro do 'state' para recuperar no callback
        return Socialite::driver('google')
            ->stateless()
            ->with(['state' => 'origin=' . $origin])
            ->redirect();
    }

    /**
     * Processa o retorno do Google.
     */
    public function handleGoogleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();

            // Busca por google_id ou email
            $user = User::where('google_id', $googleUser->id)
                        ->orWhere('email', $googleUser->email)
                        ->first();

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
                $user->update([
                    'google_id'   => $googleUser->id,
                    'from_google' => true,
                ]);
            }

            // Gera o Token Sanctum
            $token = $user->createToken('axion_token')->plainTextToken;

            // Recupera a origem do parâmetro 'state'
            $state = $request->input('state');
            parse_str($state, $result);
            $frontendUrl = rtrim($result['origin'] ?? config('app.frontend_url'), '/');

            // Lógica de redirecionamento baseada no preenchimento do CPF
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

            return redirect("{$frontendUrl}/dashboard?token={$token}");

        } catch (\Exception $e) {
            return redirect(config('app.frontend_url') . "/login?error=auth_failed");
        }
    }

    /**
     * Rota POST protegida para salvar CPF e Senha
     */
    public function completeProfile(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Usuário não identificado.'], 401);
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
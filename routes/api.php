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
        $origin = $request->query('origin', config('app.frontend_url'));

        // Guarda o origin na sessão
        session(['google_origin' => $origin]);

        return Socialite::driver('google')
            ->stateless()
            ->redirect();
    }

    /**
     * Callback do Google
     */
    public function handleGoogleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')
                ->stateless()
                ->user();

            // Busca usuário por google_id ou email
            $user = User::where('google_id', $googleUser->id)
                        ->orWhere('email', $googleUser->email)
                        ->first();

            if (!$user) {
                $user = User::create([
                    'name'            => $googleUser->name,
                    'email'           => $googleUser->email,
                    'google_id'       => $googleUser->id,
                    'password'        => Hash::make(Str::random(24)),
                    'from_google'     => true,
                    'profile_completed' => false,
                ]);
            } else {
                $user->update([
                    'google_id'   => $googleUser->id,
                    'from_google' => true,
                ]);
            }

            // Cria token Sanctum
            $token = $user->createToken('axion_token')->plainTextToken;

            $frontendUrl = session('google_origin', config('app.frontend_url'));

            // Se não tiver CPF/CNPJ → precisa completar cadastro
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

            // Se já tiver cadastro completo
            return redirect("{$frontendUrl}/dashboard?token={$token}");

        } catch (\Exception $e) {

            return redirect(config('app.frontend_url') . "/?error=auth_failed");
        }
    }

    /**
     * Completa o perfil (CPF + Senha)
     * Requer auth:sanctum
     */
    public function completeProfile(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Usuário não autenticado.'
            ], 401);
        }

        $request->validate([
            'cpf_cnpj' => 'required|string|unique:users,cpf_cnpj,' . $user->id,
            'password' => 'required|min:6|confirmed',
        ]);

        $user->update([
            'cpf_cnpj'         => $request->cpf_cnpj,
            'password'         => Hash::make($request->password),
            'profile_completed'=> true,
        ]);

        return response()->json([
            'message' => 'Cadastro finalizado com sucesso!',
            'user' => $user
        ]);
    }
}

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
     * Callback do Google
     */
    public function handleGoogleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
            
            // 1. Busca por google_id OU email
            $user = User::where('google_id', $googleUser->id)
                        ->orWhere('email', $googleUser->email)
                        ->first();

            if (!$user) {
                // CENÁRIO: Novo usuário via Google
                $user = User::create([
                    'name'        => $googleUser->name,
                    'email'       => $googleUser->email,
                    'google_id'   => $googleUser->id,
                    'password'    => Hash::make(Str::random(24)),
                    'from_google' => true,
                ]);
            } else {
                // CENÁRIO: Já tinha cadastro manual, vincula o ID agora
                if (empty($user->google_id)) {
                    $user->update([
                        'google_id' => $googleUser->id,
                        'from_google' => true 
                    ]);
                }
            }

            $token = $user->createToken('axion_token')->plainTextToken;
            $state = $request->input('state');
            parse_str($state, $result);
            $frontendUrl = rtrim($result['origin'] ?? env('FRONTEND_URL'), '/');

            // Parâmetros para o Front decidir o cenário
            $params = http_build_query([
                'token'     => $token,
                'is_admin'  => $user->is_admin ? '1' : '0',
                'name'      => $user->name,
                'email'     => $user->email,
                'needs_cpf' => empty($user->cpf_cnpj) ? 'true' : 'false'
            ]);

            return redirect("{$frontendUrl}/?{$params}");

        } catch (\Exception $e) {
            return redirect(env('FRONTEND_URL') . "/?error=auth_failed");
        }
    }

    /**
     * NOVO MÉTODO: Finaliza o perfil (Grava CPF e Senha)
     * Rota: POST /api/v1/complete-profile (Protegida por Sanctum)
     */
    public function completeProfile(Request $request)
    {
        // O Sanctum identifica o usuário pelo Token enviado no Header
        $user = $request->user(); 

        if (!$user) {
            return response()->json(['message' => 'Usuário não identificado.'], 401);
        }

        $request->validate([
            'cpf_cnpj' => 'required|string|unique:users,cpf_cnpj,' . $user->id,
            'password' => 'required|min:6|confirmed',
        ]);

        // Faz o UPDATE do registro que o handleGoogleCallback criou
        $user->update([
            'cpf_cnpj' => $request->cpf_cnpj,
            'password'  => Hash::make($request->password),
            'profile_completed' => true, 
        ]);

        return response()->json([
            'message' => 'Perfil completado com sucesso!',
            'user' => $user
        ]);
    }
}
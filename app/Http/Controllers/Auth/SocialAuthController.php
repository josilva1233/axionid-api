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
            $googleUser = Socialite::driver('google')
                ->stateless()
                ->user();

            // 🔎 Busca usuário
            $user = User::where('google_id', $googleUser->id)
                        ->orWhere('email', $googleUser->email)
                        ->first();

            if (!$user) {
                // 🆕 Novo usuário
                $user = User::create([
                    'name'              => $googleUser->name,
                    'email'             => $googleUser->email,
                    'google_id'         => $googleUser->id,
                    'password'          => Hash::make(Str::random(24)),
                    'from_google'       => true,
                    'profile_completed' => false,
                ]);
            } else {
                // 🔗 Vincula Google se necessário
                if (empty($user->google_id)) {
                    $user->update([
                        'google_id'   => $googleUser->id,
                        'from_google' => true
                    ]);
                }
            }

            // 🔐 Cria token
            $token = $user->createToken('axion_token')->plainTextToken;

            // 🔄 Recupera origem
            $state = $request->input('state');
            parse_str($state, $result);

            $frontendUrl = rtrim(
                $result['origin'] ?? env('FRONTEND_URL'),
                '/'
            );

            $user->refresh();

            // 🔥 MONTA PARÂMETROS
            $params = http_build_query([
                'token'     => $token,
                'is_admin'  => $user->is_admin ? '1' : '0',
                'name'      => $user->name,
                'email'     => $user->email,
                'needs_cpf' => $user->profile_completed ? 'false' : 'true'
            ]);

            // ✅ SEMPRE VOLTA PARA A RAIZ
            return redirect("{$frontendUrl}/?{$params}");

        } catch (\Exception $e) {
            return redirect(env('FRONTEND_URL') . "/?error=auth_failed");
        }
    }

    /**
     * Finaliza o perfil
     */
    public function completeProfile(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Usuário não identificado.'
            ], 401);
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
            'message' => 'Perfil completado com sucesso!',
            'user'    => $user
        ]);
    }
}

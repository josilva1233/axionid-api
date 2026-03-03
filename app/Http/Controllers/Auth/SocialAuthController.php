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
        $origin = $request->query('origin', env('FRONTEND_URL'));

        return Socialite::driver('google')
            ->stateless()
            ->with(['state' => base64_encode('origin=' . $origin)]) // 🔧 CORRIGIDO: base64 para evitar problemas de parsing
            ->redirect();
    }

    public function handleGoogleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();

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
                if (empty($user->google_id)) {
                    $user->update([
                        'google_id'   => $googleUser->id,
                        'from_google' => true
                    ]);
                }
            }

            // Criar Token Sanctum
            $token = $user->createToken('axion_token')->plainTextToken;

            // 🔧 CORRIGIDO: Parse do state com base64
            $state = $request->input('state');
            $decodedState = base64_decode($state);
            parse_str($decodedState, $result);
            $frontendUrl = rtrim($result['origin'] ?? env('FRONTEND_URL'), '/');

            // Verifica se precisa completar dados
            $needsCpf = empty($user->cpf_cnpj);

            $params = http_build_query([
                'token'      => $token,
                'is_admin'   => $user->is_admin ? '1' : '0',
                'name'       => $user->name,
                'email'      => $user->email,
                'needs_cpf'  => $needsCpf ? 'true' : 'false'
            ]);

            // Se precisar de CPF, manda para Register, senão Login (que processa o token)
            $targetPath = $needsCpf ? 'register' : 'dashboard';
            return redirect("{$frontendUrl}/{$targetPath}?{$params}");

        } catch (\Exception $e) {
            \Log::error('Google Auth Error: ' . $e->getMessage()); // 🔧 ADICIONADO: Log para debug
            return redirect(env('FRONTEND_URL') . "/login?error=auth_failed");
        }
    }

    public function completeProfile(Request $request)
    {
        // Mantém exatamente igual
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Usuário não autenticado.'], 401);
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
            'user'    => $user,
            'token'   => $request->bearerToken()
        ]);
    }
}

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
            ->with(['state' => 'origin=' . $origin])
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
                    'name'      => $googleUser->name,
                    'email'     => $googleUser->email,
                    'google_id' => $googleUser->id,
                    'password'  => Hash::make(Str::random(24)),
                    'from_google' => true,
                ]);
            } else {
                $user->update(['google_id' => $googleUser->id]);
            }

            $token = $user->createToken('axion_token')->plainTextToken;

            $state = $request->input('state');
            parse_str($state, $result);
            $frontendUrl = $result['origin'] ?? env('FRONTEND_URL');

            // Se não tem CPF, manda para o registro passo 2 com os dados
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

            return redirect("{$frontendUrl}/dashboard?token={$token}");

        } catch (\Exception $e) {
            return redirect(env('FRONTEND_URL') . "/?error=auth_failed");
        }
    }

    /**
     * Salva o CPF e a Senha do usuário vindo do Google
     */
/**
 * Completa o perfil gravando apenas CPF/CNPJ e Senha.
 * O usuário é identificado automaticamente pelo Token (Sanctum).
 */
public function completeProfile(Request $request)
{
    // 1. Pega o usuário autenticado pelo token
    $user = $request->user();

    // 2. Valida os dados (CPF obrigatório e Senha com confirmação)
    $request->validate([
        'cpf_cnpj' => 'required|string|unique:users,cpf_cnpj,' . $user->id,
        'password' => 'required|min:6|confirmed',
    ]);

    // 3. Faz o update apenas das colunas necessárias
    $user->update([
        'cpf_cnpj' => $request->cpf_cnpj,
        'password'  => Hash::make($request->password),
        'profile_completed' => true, // Marca como completo para o próximo login ir direto pro Dashboard
    ]);

    return response()->json([
        'message' => 'Cadastro finalizado com sucesso!',
        'user' => $user
    ]);
}
}
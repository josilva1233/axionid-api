<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    /**
     * 1. Redireciona para o Google
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
     * 2. Callback do Google - Cadastro Automático e Login
     */
    public function handleGoogleCallback(Request $request)
    {
        try {
            $state = $request->input('state');
            parse_str($state, $result);
            $frontendUrl = $result['origin'] ?? env('FRONTEND_URL');

            $googleUser = Socialite::driver('google')->stateless()->user();
            
            // Procura usuário pelo Google ID ou Email
            $user = User::where('google_id', $googleUser->id)
                        ->orWhere('email', $googleUser->email)
                        ->first();

            // CENÁRIO 1: USUÁRIO NÃO EXISTE -> CADASTRA AGORA
            if (!$user) {
                $user = User::create([
                    'name' => $googleUser->name,
                    'email' => $googleUser->email,
                    'google_id' => $googleUser->id,
                    'password' => Hash::make(Str::random(24)), // Senha aleatória segura
                    'from_google' => true,
                ]);
            }

            // Garante que o google_id esteja vinculado se ele já existia por email
            if (empty($user->google_id)) {
                $user->update(['google_id' => $googleUser->id]);
            }

            // Gera o Token de autenticação (Sanctum)
            $token = $user->createToken('axion_token')->plainTextToken;

            // CENÁRIO 2: FALTA CPF/CNPJ
            // Manda para o registro no Step 2, mas já autenticado com o Token
            if (empty($user->cpf_cnpj)) {
                return redirect("{$frontendUrl}/register?token={$token}&step=2&name=" . urlencode($user->name) . "&from_google=true");
            }

            // CENÁRIO 3: LOGIN SUCESSO (Tudo completo)
            $isAdmin = $user->is_admin ? '1' : '0';
            return redirect("{$frontendUrl}/dashboard?token={$token}&is_admin={$isAdmin}&email={$user->email}");

        } catch (\Exception $e) {
            $fallback = env('FRONTEND_URL');
            return redirect("{$fallback}/?error=auth_failed");
        }
    }

    /**
     * Nota: getTempData não é mais estritamente necessário para o fluxo Google, 
     * mas pode ser mantido se houver outros fluxos de cache.
     */
    public function getTempData($key)
    {
        $data = \Illuminate\Support\Facades\Cache::get('temp_google_' . $key);
        return $data ? response()->json($data) : response()->json(['message' => 'Expirado'], 404);
    }
}
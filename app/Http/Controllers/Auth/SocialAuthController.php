<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache; 
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
     * 2. Callback do Google
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

            // CENÁRIO 1: NOVO USUÁRIO (REGISTRO SEGURO)
            if (!$user) {
                $tempKey = Str::random(40);
                
                // SALVANDO NO CACHE (O google_id, name e email ficam seguros no servidor)
                Cache::put('temp_google_' . $tempKey, [
                    'google_id' => $googleUser->id,
                    'name'      => $googleUser->name,
                    'email'     => $googleUser->email,
                ], 600); // 10 minutos de vida

                // REDIRECIONAMENTO SEGURO (Sem IDs, Nomes ou E-mails expostos na URL)
                return redirect("{$frontendUrl}/register?t={$tempKey}&from_google=true");
            }

            // Atualiza o google_id se o usuário existia apenas por email
            if (empty($user->google_id)) {
                $user->update(['google_id' => $googleUser->id]);
            }

            // CENÁRIO 2: USUÁRIO EXISTE MAS NÃO TEM CPF
            if (empty($user->cpf_cnpj)) {
                $token = $user->createToken('axion_token')->plainTextToken;
                return redirect("{$frontendUrl}/register?token={$token}&step=2&name=" . urlencode($user->name));
            }

            // CENÁRIO 3: LOGIN SUCESSO
            $token = $user->createToken('axion_token')->plainTextToken;
            $isAdmin = $user->is_admin ? '1' : '0';

            return redirect("{$frontendUrl}/dashboard?token={$token}&is_admin={$isAdmin}&email={$user->email}");

        } catch (\Exception $e) {
            $fallback = env('FRONTEND_URL');
            return redirect("{$fallback}/?error=auth_failed");
        }
    }

    /**
     * 3. Recupera os dados do Cache para o formulário React
     */
    public function getTempData($key)
    {
        // Busca usando o mesmo prefixo definido no callback
        $data = Cache::get('temp_google_' . $key);

        if (!$data) {
            return response()->json(['message' => 'Dados expirados ou inválidos'], 404);
        }

        return response()->json($data);
    }
}
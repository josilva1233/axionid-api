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

            // CENÁRIO 1: NOVO USUÁRIO (REGISTRO)
            if (!$user) {
                $tempKey = Str::random(40);
                
                // Salva os dados sensíveis no cache por 10 minutos
                Cache::put('temp_google_' . $tempKey, [
                    'google_id' => $googleUser->id,
                    'email' => $googleUser->email,
                    'name' => $googleUser->name
                ], 600); 

                $name = urlencode($googleUser->name);
                $email = urlencode($googleUser->email);
                
                // Redireciona com a chave 't'
                return redirect("{$frontendUrl}/register?t={$tempKey}&name={$name}&email={$email}&from_google=true");
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
     * 3. NOVA ROTA: Recupera os dados do Google para o formulário React
     * Chamada pelo Front-end via: api.get('/api/v1/auth/temp-data/' + tempKey)
     */
    public function getTempData($key)
    {
        $data = Cache::get('temp_google_' . $key);

        if (!$data) {
            return response()->json(['message' => 'Dados expirados ou inválidos'], 404);
        }

        return response()->json($data);
    }
}
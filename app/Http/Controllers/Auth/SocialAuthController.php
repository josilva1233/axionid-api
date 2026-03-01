<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache; // Adicionado para segurança
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
                // Criamos uma chave temporária para não expor o google_id na URL
                $tempKey = Str::random(40);
                
                Cache::put('temp_google_' . $tempKey, [
                    'google_id' => $googleUser->id,
                    'email' => $googleUser->email,
                    'name' => $googleUser->name
                ], 600); // Expira em 10 minutos

                $name = urlencode($googleUser->name);
                $email = urlencode($googleUser->email);
                
                // Enviamos apenas a 't' (tempKey) para o Front-end
                return redirect("{$frontendUrl}/register?t={$tempKey}&name={$name}&email={$email}&from_google=true");
            }

            // Atualiza o google_id se o usuário existia apenas por email
            if (empty($user->google_id)) {
                $user->update(['google_id' => $googleUser->id]);
            }

            // CENÁRIO 2: USUÁRIO EXISTE MAS NÃO TEM CPF (CADASTRO INCOMPLETO)
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
            // Logar o erro ajuda a debugar no servidor: \Log::error($e->getMessage());
            return redirect("{$fallback}/?error=auth_failed");
        }
    }
}
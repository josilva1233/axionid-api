<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    /**
     * 1. O Front chama essa rota passando ?origin=https://seu-site.com
     */
    public function redirectToGoogle(Request $request)
    {
        // Pega a URL de quem chamou. Se não vier nada, usa o padrão do .env
        $origin = $request->query('origin', env('FRONTEND_URL'));

        // O 'state' é enviado ao Google e ele nos devolve exatamente igual no callback
        return Socialite::driver('google')
            ->stateless()
            ->with(['state' => 'origin=' . $origin])
            ->redirect();
    }

    /**
     * 2. O Google volta para cá trazendo o 'state' com a URL original
     */
    public function handleGoogleCallback(Request $request)
    {
        try {
            // Recupera qual era a URL do Front-end (Vercel ou Localhost)
            $state = $request->input('state');
            parse_str($state, $result);
            $frontendUrl = $result['origin'] ?? env('FRONTEND_URL');

            $googleUser = Socialite::driver('google')->stateless()->user();
            
            $user = User::where('google_id', $googleUser->id)
                        ->orWhere('email', $googleUser->email)
                        ->first();

            // REGISTRO: Redireciona para a URL dinâmica detectada
            if (!$user) {
                $name = urlencode($googleUser->name);
                $email = urlencode($googleUser->email);
                return redirect("{$frontendUrl}/register?name={$name}&email={$email}&from_google=true");
            }

            // SEM CPF: Redireciona para a URL dinâmica detectada
            if (empty($user->cpf_cnpj)) {
                $token = $user->createToken('axion_token')->plainTextToken;
                return redirect("{$frontendUrl}/register?token={$token}&name=" . urlencode($user->name) . "&email=" . $user->email);
            }

            // LOGIN SUCESSO: Redireciona para a URL dinâmica detectada
            $token = $user->createToken('axion_token')->plainTextToken;
            $isAdmin = $user->is_admin ? '1' : '0';

            return redirect("{$frontendUrl}/?token={$token}&needs_cpf=false&is_admin={$isAdmin}");

        } catch (\Exception $e) {
            $fallback = env('FRONTEND_URL');
            return redirect("{$fallback}/?error=auth_failed");
        }
    }
}
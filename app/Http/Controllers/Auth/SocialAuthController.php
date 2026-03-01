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
            // 1. Recupera os dados do Google
            $googleUser = Socialite::driver('google')->stateless()->user();
            
            // 2. Busca ou CRIA o usuário IMEDIATAMENTE com google_id, name e email
            $user = User::where('google_id', $googleUser->id)
                        ->orWhere('email', $googleUser->email)
                        ->first();

            if (!$user) {
                $user = User::create([
                    'name'      => $googleUser->name,
                    'email'     => $googleUser->email,
                    'google_id' => $googleUser->id,
                    'password'  => Hash::make(Str::random(24)), // Senha aleatória
                    'from_google' => true,
                ]);
            } else {
                // Se o usuário já existia por email, vincula o google_id agora
                $user->update(['google_id' => $googleUser->id]);
            }

            // 3. Gera o token de acesso (Sanctum)
            $token = $user->createToken('axion_token')->plainTextToken;

            // 4. Redireciona de volta para o seu Front-end no Vercel
            $state = $request->input('state');
            parse_str($state, $result);
            $frontendUrl = $result['origin'] ?? env('FRONTEND_URL');

            // Se não tem CPF, manda pro Step 2. Se tem, vai pro Dashboard.
            $target = empty($user->cpf_cnpj) 
                ? "/register?token={$token}&step=2&from_google=true&name=" . urlencode($user->name)
                : "/dashboard?token={$token}";

            return redirect($frontendUrl . $target);

        } catch (\Exception $e) {
            return redirect(env('FRONTEND_URL') . "/?error=auth_failed");
        }
    }
}
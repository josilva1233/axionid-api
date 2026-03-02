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
            
            // Busca por google_id OU email
            $user = User::where('google_id', $googleUser->id)
                        ->orWhere('email', $googleUser->email)
                        ->first();

            if (!$user) {
                // CENÁRIO 2: Novo usuário (Sem cadastro manual)
                $user = User::create([
                    'name'        => $googleUser->name,
                    'email'       => $googleUser->email,
                    'google_id'   => $googleUser->id,
                    'password'    => Hash::make(Str::random(24)),
                    'from_google' => true,
                ]);
            } else {
                // CENÁRIO 1: Já tinha manual, grava o google_id agora
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
            $frontendUrl = $result['origin'] ?? env('FRONTEND_URL');

            // Prepara os parâmetros para o Front
            $params = [
                'token'     => $token,
                'is_admin'  => $user->is_admin ? '1' : '0',
                'name'      => $user->name,
                'email'     => $user->email,
                'needs_cpf' => empty($user->cpf_cnpj) ? 'true' : 'false'
            ];

            // CENÁRIO 3: Se tem CPF e google_id (garantido acima), loga direto
            return redirect("{$frontendUrl}/??" . http_build_query($params));

        } catch (\Exception $e) {
            return redirect(env('FRONTEND_URL') . "/?error=auth_failed");
        }
    }
}
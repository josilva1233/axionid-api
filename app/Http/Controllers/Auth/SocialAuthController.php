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
            
            // 1. Busca por google_id OU email (Cenários 1 e 3)
            $user = User::where('google_id', $googleUser->id)
                        ->orWhere('email', $googleUser->email)
                        ->first();

            if (!$user) {
                // Cenário 2: Usuário novo
                $user = User::create([
                    'name'        => $googleUser->name,
                    'email'       => $googleUser->email,
                    'google_id'   => $googleUser->id,
                    'password'    => Hash::make(Str::random(24)),
                    'from_google' => true,
                ]);
            } else {
                // Cenário 1: Já existia manual, vincula o google_id agora
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
            $frontendUrl = rtrim($result['origin'] ?? env('FRONTEND_URL'), '/');

            // Prepara os parâmetros (Atenção à URL limpa com apenas um '?')
            $params = http_build_query([
                'token'     => $token,
                'is_admin'  => $user->is_admin ? '1' : '0',
                'name'      => $user->name,
                'email'     => $user->email,
                'needs_cpf' => empty($user->cpf_cnpj) ? 'true' : 'false'
            ]);

            return redirect("{$frontendUrl}/?{$params}");

        } catch (\Exception $e) {
            return redirect(env('FRONTEND_URL') . "/?error=auth_failed");
        }
    }
}
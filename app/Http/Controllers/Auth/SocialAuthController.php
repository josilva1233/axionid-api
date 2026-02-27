<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Http\RedirectResponse;

class SocialAuthController extends Controller
{
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
            
            // Busca ou cria o usuário
            $user = User::where('google_id', $googleUser->id)
                        ->orWhere('email', $googleUser->email)
                        ->first();

            if (!$user) {
                $user = User::create([
                    'name' => $googleUser->name,
                    'email' => $googleUser->email,
                    'google_id' => $googleUser->id,
                    'password' => null,
                    'profile_completed' => false,
                    'is_active' => true,
                    'is_admin' => false // Padrão para novos
                ]);
            } else {
                $user->update(['google_id' => $googleUser->id]);
            }

            // Gera o Token
            $token = $user->createToken('axion_token')->plainTextToken;

            // Prepara os parâmetros para a URL
            $needsCpf = empty($user->cpf_cnpj) ? 'true' : 'false';
            
            // CONVERSÃO DO STATUS ADMIN PARA A URL
            $isAdmin = $user->is_admin ? '1' : '0';

            // URL de redirecionamento com is_admin incluído
            $redirectUrl = "http://localhost:5173/?token={$token}&needs_cpf={$needsCpf}&is_admin={$isAdmin}";

            return redirect($redirectUrl);

        } catch (\Exception $e) {
            return redirect("http://localhost:5173/?error=auth_failed");
        }
    }
}
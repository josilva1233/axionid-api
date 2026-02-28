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
            
            // 1. Apenas BUSCA o usuário. NÃO cria se não encontrar.
            $user = User::where('google_id', $googleUser->id)
                        ->orWhere('email', $googleUser->email)
                        ->first();

            // 2. Se o usuário não existe, mandamos para o REGISTRO com os dados na URL
            if (!$user) {
                $name = urlencode($googleUser->name);
                $email = urlencode($googleUser->email);
                
                // Redireciona para a página de registro do React (Register.jsx)
                // Note que não passamos token aqui, pois o usuário ainda não foi criado.
                return redirect("http://localhost:5173/register?name={$name}&email={$email}&from_google=true");
            }

            // 3. Se o usuário existe mas está sem CPF (caso de erro anterior)
            if (empty($user->cpf_cnpj)) {
                $token = $user->createToken('axion_token')->plainTextToken;
                return redirect("http://localhost:5173/register?token={$token}&name=" . urlencode($user->name) . "&email=" . $user->email);
            }

            // 4. Se o usuário já existe e está COMPLETO, faz o login normal
            $token = $user->createToken('axion_token')->plainTextToken;
            $isAdmin = $user->is_admin ? '1' : '0';

            return redirect("http://localhost:5173/?token={$token}&needs_cpf=false&is_admin={$isAdmin}");

        } catch (\Exception $e) {
            return redirect("http://localhost:5173/?error=auth_failed");
        }
    }
}
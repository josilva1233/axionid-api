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
            
            $user = User::where('google_id', $googleUser->id)
                        ->orWhere('email', $googleUser->email)
                        ->first();

            if (!$user) {
                $user = User::create([
                    'name'      => $googleUser->name,
                    'email'     => $googleUser->email,
                    'google_id' => $googleUser->id,
                    'password'  => Hash::make(Str::random(24)),
                    'from_google' => true,
                ]);
            } else {
                $user->update(['google_id' => $googleUser->id]);
            }

            $token = $user->createToken('axion_token')->plainTextToken;

            $state = $request->input('state');
            parse_str($state, $result);
            $frontendUrl = $result['origin'] ?? env('FRONTEND_URL');

            $target = empty($user->cpf_cnpj) 
                ? "/register?token={$token}&step=2&from_google=true&name=" . urlencode($user->name) . "&email=" . urlencode($user->email)
                : "/dashboard?token={$token}";

            return redirect($frontendUrl . $target);

        } catch (\Exception $e) {
            return redirect(env('FRONTEND_URL') . "/?error=auth_failed");
        }
    }

    /**
     * ESTA FUNÇÃO DEVE FICAR AQUI DENTRO (Antes da última chave)
     */
    public function completeProfile(Request $request)
    {
        // O Sanctum identifica o usuário pelo Token enviado no Header
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Usuário não autenticado'], 401);
        }

        $request->validate([
            'cpf_cnpj' => 'required|string|unique:users,cpf_cnpj,' . $user->id,
            'password' => 'required|min:6|confirmed',
        ]);

        $user->update([
            'cpf_cnpj' => $request->cpf_cnpj,
            'password' => Hash::make($request->password),
            'profile_completed' => true, 
        ]);

        return response()->json([
            'message' => 'Perfil atualizado com sucesso!',
            'user' => $user
        ]);
    }
} // <--- Esta chave fecha a Classe
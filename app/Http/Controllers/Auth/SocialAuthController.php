<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    /**
     * 🔐 Redireciona para autenticação Google
     */
    public function redirectToGoogle(Request $request)
    {
        $origin = $request->query('origin', env('FRONTEND_URL', 'http://localhost:3000'));
        
        Log::info('🔍 GOOGLE REDIRECT', [
            'origin' => $origin,
            'user_agent' => $request->userAgent()
        ]);

        return Socialite::driver('google')
            ->stateless()
            ->with(['state' => base64_encode('origin=' . $origin)])
            ->redirect();
    }

    /**
     * 🎯 Callback do Google - CRIA TOKEN e REDIRECIONA
     */
    public function handleGoogleCallback(Request $request)
    {
        Log::info('🔍 GOOGLE CALLBACK RECEBIDO', [
            'state' => $request->input('state'),
            'code' => $request->input('code') ? 'PRESENT' : 'MISSING'
        ]);

        try {
            // 1. Pega dados do Google
            $googleUser = Socialite::driver('google')->stateless()->user();
            
            Log::info('✅ GOOGLE USER VALIDADO', [
                'google_id' => $googleUser->id,
                'email' => $googleUser->email,
                'name' => $googleUser->name
            ]);

            // 2. Busca ou cria usuário
            $user = User::where('google_id', $googleUser->id)
                        ->orWhere('email', $googleUser->email)
                        ->first();

            if (!$user) {
                Log::info('👤 NOVO USUÁRIO CRIADO');
                $user = User::create([
                    'name'              => $googleUser->name,
                    'email'             => $googleUser->email,
                    'email_verified_at' => now(),
                    'google_id'         => $googleUser->id,
                    'password'          => Hash::make(Str::random(24)),
                    'from_google'       => true,
                    'profile_completed' => false,
                ]);
            } else {
                if (empty($user->google_id)) {
                    $user->update([
                        'google_id'   => $googleUser->id,
                        'from_google' => true,
                        'email_verified_at' => now()
                    ]);
                    Log::info('🔗 GOOGLE_ID ASSOCIADO', ['user_id' => $user->id]);
                }
            }

            // 3. Cria token Sanctum
            $token = $user->createToken('axion_token')->plainTextToken;
            
            Log::info('🔑 TOKEN GERADO COM SUCESSO', [
                'user_id' => $user->id,
                'token_preview' => substr($token, 0, 20) . '...'
            ]);

            // 4. Decodifica origin do state
            $state = $request->input('state');
            $decodedState = base64_decode($state);
            parse_str($decodedState, $result);
            $frontendUrl = rtrim($result['origin'] ?? env('FRONTEND_URL', 'http://localhost:3000'), '/');

            Log::info('📱 REDIRECT FRONTEND', ['url' => $frontendUrl]);

            // 5. Verifica se precisa CPF
            $needsCpf = empty($user->cpf_cnpj);

            $params = http_build_query([
                'token'      => $token,
                'is_admin'   => $user->is_admin ? '1' : '0',
                'name'       => $user->name,
                'email'      => $user->email,
                'needs_cpf'  => $needsCpf ? 'true' : 'false'
            ]);

            $targetPath = $needsCpf ? 'register' : 'dashboard';
            $redirectUrl = "{$frontendUrl}/{$targetPath}?{$params}";

            Log::info('✅ REDIRECT FINAL', ['url' => $redirectUrl]);
            
            return redirect($redirectUrl);

        } catch (\Laravel\Socialite\Two\InvalidStateException $e) {
            Log::error('❌ GOOGLE INVALID STATE', ['error' => $e->getMessage()]);
            return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/login?error=invalid_state');
        } catch (\Exception $e) {
            Log::error('❌ GOOGLE AUTH ERROR', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/login?error=auth_failed');
        }
    }

    /**
     * ✅ Completa perfil com CPF (chamado pelo Register)
     */
    public function completeProfile(Request $request)
    {
        Log::info('🔧 COMPLETE PROFILE', ['user_id' => $request->user()?->id]);

        $user = $request->user();

        if (!$user) {
            Log::warning('❌ USUÁRIO NÃO AUTENTICADO');
            return response()->json(['message' => 'Usuário não autenticado.'], 401);
        }

        $request->validate([
            'cpf_cnpj' => 'required|string|unique:users,cpf_cnpj,' . $user->id,
            'password' => 'required|min:6|confirmed',
        ]);

        $user->update([
            'cpf_cnpj'          => $request->cpf_cnpj,
            'password'          => Hash::make($request->password),
            'profile_completed' => true,
        ]);

        Log::info('✅ PERFIL COMPLETADO', ['user_id' => $user->id, 'cpf' => substr($request->cpf_cnpj, 0, 3) . '...']);

        return response()->json([
            'message' => 'Perfil completado com sucesso!',
            'user'    => $user->makeHidden(['password']),
            'token'   => $request->bearerToken()
        ]);
    }
}

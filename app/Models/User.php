<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Http;

class AxionAuthController extends Controller
{
    /**
     * Login com validação de CAPTCHA e rate limiting
     */
    public function login(Request $request)
    {
        // Validação
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string|min:6',
            'captcha_token' => 'required|string',
            'remember_me' => 'boolean',
        ]);

        // Verificar CAPTCHA
        $this->verifyCaptcha($request->captcha_token);

        // Rate Limiting
        $key = 'login_attempts_' . $request->ip() . '_' . $request->email;
        
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'email' => ["Muitas tentativas. Aguarde {$seconds} segundos."],
            ]);
        }

        // Buscar usuário
        $user = User::where('email', $request->email)->first();

        // Verificar existência
        if (!$user) {
            RateLimiter::hit($key, 300);
            throw ValidationException::withMessages([
                'email' => ['Credenciais inválidas.'],
            ]);
        }

        // Verificar status
        if (!$user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Usuário inativo. Entre em contato com o administrador.'],
            ]);
        }

        // Verificar senha
        if (!Hash::check($request->password, $user->password)) {
            RateLimiter::hit($key, 300);
            
            AuditLog::log('login_failed', null, null, null, [
                'email' => $request->email,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'attempts' => RateLimiter::attempts($key),
            ]);
            
            throw ValidationException::withMessages([
                'email' => ['Credenciais inválidas.'],
            ]);
        }

        // Limpar tentativas
        RateLimiter::clear($key);

        // Registrar login
        AuditLog::log('login', $user, null, null, [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        // Atualizar dados do usuário
        $user->last_login_at = now();
        $user->last_login_ip = $request->ip();
        $user->login_attempts = 0;
        $user->save();

        // Gerar token
        $expiresIn = $request->remember_me ? 30 : 1;
        $token = $user->createToken('auth_token', ['*'], now()->addDays($expiresIn))->plainTextToken;

        // Carregar relações
        $user->load(['groups', 'roles', 'address']);

        return response()->json([
            'message' => 'Login realizado com sucesso!',
            'token' => $token,
            'user' => $user,
        ]);
    }

    /**
     * Verificar CAPTCHA
     */
    private function verifyCaptcha($token)
    {
        $secretKey = env('RECAPTCHA_SECRET_KEY');
        
        $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => $secretKey,
            'response' => $token,
        ]);

        $result = $response->json();

        if (!$result['success']) {
            throw ValidationException::withMessages([
                'captcha' => ['Falha na verificação do CAPTCHA. Tente novamente.'],
            ]);
        }

        return true;
    }

    /**
     * Logout
     */
    public function logout(Request $request)
    {
        $user = $request->user();
        
        AuditLog::log('logout', $user);
        
        $request->user()->currentAccessToken()->delete();
        
        return response()->json([
            'message' => 'Logout realizado com sucesso.',
        ]);
    }

    /**
     * Refresh Token
     */
    public function refreshToken(Request $request)
    {
        $user = $request->user();
        
        // Revogar token atual
        $request->user()->currentAccessToken()->delete();
        
        // Criar novo token
        $token = $user->createToken('auth_token', ['*'], now()->addDay())->plainTextToken;
        
        return response()->json([
            'token' => $token,
        ]);
    }

    /**
     * Registrar falha de login
     */
    public function logFailedAttempt(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();
        
        if ($user) {
            $user->login_attempts = ($user->login_attempts ?? 0) + 1;
            
            // Bloquear após 10 tentativas
            if ($user->login_attempts >= 10) {
                $user->is_active = false;
                $user->blocked_reason = 'Múltiplas tentativas de login';
                $user->blocked_at = now();
            }
            
            $user->save();
        }

        return response()->json(['message' => 'Tentativa registrada']);
    }
}
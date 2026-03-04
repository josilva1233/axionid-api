<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Models\User;
use Carbon\Carbon;

class PasswordResetController extends Controller
{
    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        
        // Padroniza e-mail para minúsculo
        $email = strtolower($request->email);
        $user = User::where('email', $email)->first();
        
        if (!$user) {
            return response()->json(['message' => 'Usuário não encontrado.'], 404);
        }

        // Gera código de 6 dígitos alfanuméricos
        $token = strtoupper(Str::random(6)); 

        // Salva ou atualiza o token na tabela padrão do Laravel
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token' => $token, // Aqui você pode usar Hash::make($token) para mais segurança
                'created_at' => now()
            ]
        );

        // Envio do e-mail
        try {
            Mail::raw("Seu código de recuperação AxionID é: $token. Ele expira em 60 minutos.", function ($message) use ($email) {
                $message->to($email)->subject('Recuperação de Senha - AxionID');
            });
        } catch (\Exception $e) {
            return response()->json(['message' => 'Erro ao enviar e-mail. Tente novamente.'], 500);
        }

        return response()->json([
            'message' => 'Código de recuperação enviado com sucesso para seu e-mail.',
            'code_debug' => $token // Remova em produção
        ]);
    }

    public function verifyCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required'
        ]);

        $email = strtolower($request->email);
        $reset = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->where('token', $request->token)
            ->first();

        if (!$reset) {
            return response()->json(['message' => 'Código inválido.'], 422);
        }

        // Verifica se expirou (60 minutos)
        if (Carbon::parse($reset->created_at)->addMinutes(60)->isPast()) {
            return response()->json(['message' => 'Este código expirou.'], 422);
        }

        return response()->json(['message' => 'Código validado! Prossiga para redefinir a senha.']);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required',
            'password' => 'required|min:8|confirmed',
        ]);

        $email = strtolower($request->email);

        // Valida se o token ainda é válido antes de trocar a senha
        $reset = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->where('token', $request->token)
            ->first();

        if (!$reset || Carbon::parse($reset->created_at)->addMinutes(60)->isPast()) {
            return response()->json(['message' => 'Token inválido ou expirado. Inicie o processo novamente.'], 422);
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            return response()->json(['message' => 'Usuário não encontrado.'], 404);
        }

        // Atualiza a senha e marca profile como completo (opcional)
        $user->password = Hash::make($request->password);
        $user->save();

        // Deleta o token para não ser usado novamente
        DB::table('password_reset_tokens')->where('email', $email)->delete();

        return response()->json(['message' => 'Senha alterada com sucesso!']);
    }
}
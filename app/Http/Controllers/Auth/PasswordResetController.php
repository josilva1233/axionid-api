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
use OpenApi\Attributes as OA;

class PasswordResetController extends Controller
{
    #[OA\Post(
        path: '/api/v1/password/send-link',
        summary: '1. Solicitar código de recuperação',
        description: 'Envia um código alfanumérico de 6 dígitos para o e-mail do usuário se ele existir no sistema.',
        tags: ['Recuperação de Senha'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'usuario@email.com')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Código enviado com sucesso'),
            new OA\Response(response: 404, description: 'Usuário não encontrado'),
            new OA\Response(response: 500, description: 'Erro ao enviar e-mail')
        ]
    )]
    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        
        $email = strtolower($request->email);
        $user = User::where('email', $email)->first();
        
        if (!$user) {
            return response()->json(['message' => 'Usuário não encontrado.'], 404);
        }

        $token = strtoupper(Str::random(6)); 

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token' => $token,
                'created_at' => now()
            ]
        );

        try {
            Mail::raw("Seu código de recuperação AxionID é: $token. Ele expira em 60 minutos.", function ($message) use ($email) {
                $message->to($email)->subject('Recuperação de Senha - AxionID');
            });
        } catch (\Exception $e) {
            return response()->json(['message' => 'Erro ao enviar e-mail. Tente novamente.'], 500);
        }

        return response()->json([
            'message' => 'Código de recuperação enviado com sucesso para seu e-mail.',
            'code_debug' => $token 
        ]);
    }

    #[OA\Post(
        path: '/api/v1/password/verify-code',
        summary: '2. Verificar validade do código',
        description: 'Valida se o código de 6 dígitos informado ainda é válido e não expirou (limite de 60 min).',
        tags: ['Recuperação de Senha'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'usuario@email.com'),
                    new OA\Property(property: 'token', type: 'string', example: 'A1B2C3')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Código validado com sucesso'),
            new OA\Response(response: 422, description: 'Código inválido ou expirado')
        ]
    )]
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

        if (Carbon::parse($reset->created_at)->addMinutes(60)->isPast()) {
            return response()->json(['message' => 'Este código expirou.'], 422);
        }

        return response()->json(['message' => 'Código validado! Prossiga para redefinir a senha.']);
    }

    #[OA\Post(
        path: '/api/v1/password/reset',
        summary: '3. Redefinir senha final',
        description: 'Altera a senha do usuário após a validação bem-sucedida do token.',
        tags: ['Recuperação de Senha'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'usuario@email.com'),
                    new OA\Property(property: 'token', type: 'string', example: 'A1B2C3'),
                    new OA\Property(property: 'password', type: 'string', example: 'nova_senha123'),
                    new OA\Property(property: 'password_confirmation', type: 'string', example: 'nova_senha123')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Senha alterada com sucesso'),
            new OA\Response(response: 404, description: 'Usuário não encontrado'),
            new OA\Response(response: 422, description: 'Token inválido/expirado ou erro de confirmação de senha')
        ]
    )]
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required',
            'password' => 'required|min:8|confirmed',
        ]);

        $email = strtolower($request->email);

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

        $user->password = Hash::make($request->password);
        $user->save();

        DB::table('password_reset_tokens')->where('email', $email)->delete();

        return response()->json(['message' => 'Senha alterada com sucesso!']);
    }
}
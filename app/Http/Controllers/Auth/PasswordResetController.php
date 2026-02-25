<?php

namespace App\Http\Controllers\Auth;

use OpenApi\Attributes as OA;
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
    #[OA\Post(
        path: '/api/v1/forgot-password',
        summary: '1. Solicitar código de recuperação',
        tags: ['Recuperação de Senha'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'email', type: 'string', example: 'josilva1233@gmail.com')
            ])
        ),
        responses: [new OA\Response(response: 200, description: 'Código enviado')]
    )]
    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        $user = User::where('email', $request->email)->first();
        
        if (!$user) {
            return response()->json(['message' => 'Usuário não encontrado.'], 404);
        }

        $token = strtoupper(Str::random(6)); 

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $request->email],
            ['token' => $token, 'created_at' => now()]
        );

        Mail::raw("Seu código de recuperação AxionID é: $token", function ($message) use ($request) {
            $message->to($request->email)->subject('Recuperação de Senha - AxionID');
        });

        return response()->json([
            'message' => 'Código de recuperação enviado com sucesso para seu e-mail.',
            'code_debug' => $token 
        ]);
    }

    #[OA\Post(
        path: '/api/v1/verify-code',
        summary: '2. Validar código recebido',
        tags: ['Recuperação de Senha'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'email', type: 'string', example: 'josilva1233@gmail.com'),
                new OA\Property(property: 'token', type: 'string', example: 'ABC123')
            ])
        ),
        responses: [new OA\Response(response: 200, description: 'Código válido')]
    )]
    public function verifyCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required'
        ]);

        $reset = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->where('token', $request->token)
            ->first();

        if (!$reset || Carbon::parse($reset->created_at)->addMinutes(60)->isPast()) {
            return response()->json(['message' => 'Código inválido ou expirado.'], 422);
        }

        return response()->json(['message' => 'Código validado! Prossiga para redefinir a senha.']);
    }

    #[OA\Post(
        path: '/api/v1/reset-password',
        summary: '3. Definir nova senha',
        tags: ['Recuperação de Senha'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'email', type: 'string', example: 'josilva1233@gmail.com'),
                new OA\Property(property: 'token', type: 'string', example: 'ABC123'),
                new OA\Property(property: 'password', type: 'string', example: 'nova_senha123'),
                new OA\Property(property: 'password_confirmation', type: 'string', example: 'nova_senha123')
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Senha alterada com sucesso'),
            new OA\Response(response: 422, description: 'Erro de validação')
        ]
    )]
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required',
            'password' => 'required|min:8|confirmed',
        ]);

        $reset = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->where('token', $request->token)
            ->first();

        if (!$reset) {
            return response()->json(['message' => 'Operação inválida.'], 422);
        }

        $user = User::where('email', $request->email)->first();
        $user->password = Hash::make($request->password);
        $user->save();

        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json(['message' => 'Senha alterada com sucesso!']);
    }
}
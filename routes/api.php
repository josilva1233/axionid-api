<?php

use App\Http\Controllers\Auth\AxionAuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\Auth\AxionGroupController; // Importação adicionada
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| API Routes - AxionID
|--------------------------------------------------------------------------
*/

// Health Check
Route::get('/health', function() {
    try {
        DB::connection()->getPdo();
        $dbStatus = 'Connected';
    } catch (\Exception $e) {
        $dbStatus = 'Disconnected';
    }
    return response()->json([
        'status'   => 'UP',
        'database' => $dbStatus,
    ], $dbStatus === 'Connected' ? 200 : 503);
});

Route::prefix('v1')->group(function () {
    
    // Autenticação Pública
    Route::post('/register', [AxionAuthController::class, 'register']);
    Route::post('/login', [AxionAuthController::class, 'login']);

    // Recuperação de Senha (Sempre Públicas)
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink']);
    Route::post('/verify-code', [PasswordResetController::class, 'verifyCode']);
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);

    // Google Auth
    Route::get('/auth/google', [SocialAuthController::class, 'redirectToGoogle']);
    Route::get('/auth/google/callback', [SocialAuthController::class, 'handleGoogleCallback']);
    
    // Rotas Protegidas (Logados)
    Route::middleware('auth:sanctum')->group(function () {
        
        Route::post('/logout', [AxionAuthController::class, 'logout']);
        Route::post('/complete-profile', [SocialAuthController::class, 'completeProfile']); 
        Route::put('/update-profile', [AxionAuthController::class, 'updateProfile']); 
        Route::get('/me', function (Request $request) {
            return $request->user()->load('address');
        });
        // rota de buscar usuário por email (para o frontend verificar se o email já existe antes de registrar):
        Route::get('/users/find-by-email/{email}', [AxionAuthController::class, 'findByEmail']);
        // --- Módulo de Grupos (Usuários comuns e Admins de Grupo) ---
        Route::prefix('groups')->group(function () {
            Route::get('/', [AxionGroupController::class, 'index']);
            Route::post('/', [AxionGroupController::class, 'store']);             // Criar Grupo
            Route::get('/{id}', [AxionGroupController::class, 'show']);           // Ver detalhes
            Route::post('/{group_id}/members', [AxionGroupController::class, 'addMember']); // Convidar
            Route::patch('/{group_id}/members/{user_id}/promote', [AxionGroupController::class, 'promoteMember']); // Promover a Admin de Grupo
            Route::delete('/{group_id}/members/{user_id}', [AxionGroupController::class, 'removeMember']); // Remover/Sair
        });

        // --- Módulo Administrativo (Apenas Super Admin) ---
        Route::middleware('admin')->group(function () {
            Route::get('/users', [AxionAuthController::class, 'index']);
            Route::get('/users/{id}', [AxionAuthController::class, 'show']);
            Route::post('/users/{id}/promote', [AxionAuthController::class, 'promoteToAdmin']);
            Route::post('/users/{id}/remove-admin', [AxionAuthController::class, 'removeAdmin']);
            Route::patch('/users/{id}/toggle-status', [AxionAuthController::class, 'toggleUserStatus']);
            Route::put('/users/{id}/update-manual', [AxionAuthController::class, 'adminUpdateUser']);
            Route::get('/audit-logs', [AxionAuthController::class, 'auditLogs']);
            Route::delete('/users/{id}', [AxionAuthController::class, 'destroy']);

            // Nova rota: Super Admin vendo todos os grupos do sistema
            Route::get('/admin/groups', [AxionGroupController::class, 'index']);
        });
    });
});
<?php

use App\Http\Controllers\Auth\AxionAuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\AxionGroupController; // Importe o novo controller
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
    
    // Autenticação
    Route::post('/register', [AxionAuthController::class, 'register']);
    Route::post('/login', [AxionAuthController::class, 'login']);

    // Recuperação de Senha (Sempre Públicas)
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink']);
    Route::post('/verify-code', [PasswordResetController::class, 'verifyCode']);
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);

    // Google Auth
    Route::get('/auth/google', [SocialAuthController::class, 'redirectToGoogle']);
    Route::get('/auth/google/callback', [SocialAuthController::class, 'handleGoogleCallback']);
    
    // Rotas Protegidas
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AxionAuthController::class, 'logout']);
        Route::post('/users/{id}/remove-admin', [AxionAuthController::class, 'removeAdmin']);
        Route::post('/complete-profile', [SocialAuthController::class, 'completeProfile']); 
        Route::put('/update-profile', [AxionAuthController::class, 'updateProfile']); 
        Route::get('/users/{id}', [AxionAuthController::class, 'show']);
        Route::get('/me', function (Request $request) {
            return $request->user()->load('address');
        });

        // --- GESTÃO DE GRUPOS (Usuários Comuns e Admins de Grupo) ---
        Route::prefix('groups')->group(function () {
            Route::post('/', [AxionGroupController::class, 'store']);                // Criar grupo
            Route::get('/{id}', [AxionGroupController::class, 'show']);              // Tela do grupo
            Route::post('/{group_id}/members', [AxionGroupController::class, 'addMember']); // Adicionar membro
            Route::patch('/{group_id}/members/{user_id}/promote', [AxionGroupController::class, 'promoteMember']); // Promover a admin do grupo
            Route::delete('/{group_id}/members/{user_id}', [AxionGroupController::class, 'removeMember']); // Remover membro/admin do grupo
        });

        // Rotas exclusivas de Admin do Sistema
        Route::middleware('admin')->group(function () {
            Route::get('/users', [AxionAuthController::class, 'index']);
            Route::post('/users/{id}/promote', [AxionAuthController::class, 'promoteToAdmin']);
            Route::patch('/users/{id}/toggle-status', [AxionAuthController::class, 'toggleUserStatus']);
            Route::put('/users/{id}/update-manual', [AxionAuthController::class, 'adminUpdateUser']);
            Route::get('/audit-logs', [AxionAuthController::class, 'auditLogs']);
            Route::put('/users/{id}', [AxionAuthController::class, 'adminUpdateUser']);
            Route::delete('/users/{id}', [AxionAuthController::class, 'destroy']);
        });
    });
});
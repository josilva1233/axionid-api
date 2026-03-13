<?php

use App\Http\Controllers\Auth\AxionAuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\Auth\AxionGroupController;
use App\Http\Controllers\Auth\AuditLogController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

Route::prefix('v1')->group(function () {
    
    // Autenticação Pública
    Route::post('/register', [AxionAuthController::class, 'register']);
    Route::post('/login', [AxionAuthController::class, 'login']);

    // Recuperação de Senha
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink']);
    Route::post('/verify-code', [PasswordResetController::class, 'verifyCode']);
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);

    // Google Auth
    Route::get('/auth/google', [SocialAuthController::class, 'redirectToGoogle']);
    Route::get('/auth/google/callback', [SocialAuthController::class, 'handleGoogleCallback']);
    
    // Rotas Protegidas
    Route::middleware('auth:sanctum')->group(function () {
        
        Route::post('/logout', [AxionAuthController::class, 'logout']);
        Route::post('/complete-profile', [SocialAuthController::class, 'completeProfile']); 
        Route::put('/update-profile', [AxionAuthController::class, 'updateProfile']); 
        Route::get('/me', function (Request $request) {
            return $request->user()->load('address');
        });

        Route::get('/users/find-by-email/{email}', [AxionAuthController::class, 'findByEmail']);

        // --- Módulo de Grupos ---
        Route::prefix('groups')->group(function () {
            Route::get('/', [AxionGroupController::class, 'index']);
            Route::post('/', [AxionGroupController::class, 'store']);
            Route::get('/{id}', [AxionGroupController::class, 'show']);
            Route::delete('/{id}', [AxionGroupController::class, 'destroy']);
            Route::post('/{group_id}/members', [AxionGroupController::class, 'addMember']);
            Route::patch('/{group_id}/members/{user_id}/promote', [AxionGroupController::class, 'promoteMember']);
            Route::delete('/{group_id}/members/{user_id}', [AxionGroupController::class, 'removeMember']);
        });

        // --- Módulo Administrativo (Apenas Super Admin) ---
        Route::middleware('admin')->group(function () {
            
            // MANTIDAS: Rotas de usuários sem o prefixo /admin (ex: /api/v1/users)
            Route::get('/users', [AxionAuthController::class, 'index']);
            Route::get('/users/{id}', [AxionAuthController::class, 'show']);
            Route::post('/users/{id}/promote', [AxionAuthController::class, 'promoteToAdmin']);
            Route::post('/users/{id}/remove-admin', [AxionAuthController::class, 'removeAdmin']);
            Route::patch('/users/{id}/toggle-status', [AxionAuthController::class, 'toggleUserStatus']);
            Route::put('/users/{id}/update-manual', [AxionAuthController::class, 'adminUpdateUser']);
            Route::delete('/users/{id}', [AxionAuthController::class, 'destroy']);

            // AJUSTADA: Rota de Auditoria com prefixo manual para bater com o Swagger
            // URL: /api/v1/admin/audit-logs
            Route::get('/admin/audit-logs', [AuditLogController::class, 'index']);

            // Grupos do sistema (Admin)
            Route::get('/admin/groups', [AxionGroupController::class, 'index']);
        });
    });
});
<?php

use App\Http\Controllers\Auth\AxionAuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\Auth\AxionGroupController;
use App\Http\Controllers\Auth\AuditLogController;
use App\Http\Controllers\Admin\PermissionController; // Importado da pasta Admin
use App\Http\Controllers\ServiceOrder\ServiceOrderController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

Route::prefix('v1')->group(function () {
    
    // --- Autenticação Pública ---
    Route::post('/register', [AxionAuthController::class, 'register']);
    Route::post('/login', [AxionAuthController::class, 'login']);

    // --- Recuperação de Senha ---
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink']);
    Route::post('/verify-code', [PasswordResetController::class, 'verifyCode']);
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);

    // --- Google Auth ---
    Route::get('/auth/google', [SocialAuthController::class, 'redirectToGoogle']);
    Route::get('/auth/google/callback', [SocialAuthController::class, 'handleGoogleCallback']);
    
    // --- Rotas Protegidas (Sanctum) ---
    Route::middleware('auth:sanctum')->group(function () {
        
        Route::post('/logout', [AxionAuthController::class, 'logout']);
        Route::post('/complete-profile', [SocialAuthController::class, 'completeProfile']); 
        Route::put('/update-profile', [AxionAuthController::class, 'updateProfile']); 
        
        Route::get('/me', function (Request $request) {
            return $request->user()->load('address');
        });

        Route::get('/users/find-by-email/{email}', [AxionAuthController::class, 'findByEmail']);

        // --- Módulo de Grupos (Implementação Original) ---
        Route::prefix('groups')->group(function () {
            Route::get('/', [AxionGroupController::class, 'index']);
            Route::post('/', [AxionGroupController::class, 'store']);
            Route::get('/{id}', [AxionGroupController::class, 'show']);
            Route::delete('/{id}', [AxionGroupController::class, 'destroy']);
            Route::post('/{group_id}/members', [AxionGroupController::class, 'addMember']);
            
            // Promoção e Rebaixamento de Membros dentro do grupo
            Route::patch('/{group_id}/members/{user_id}/promote', [AxionGroupController::class, 'promoteMember']);
            Route::patch('/{group_id}/members/{user_id}/demote', [AxionGroupController::class, 'demoteMember']);
            
            Route::delete('/{group_id}/members/{user_id}', [AxionGroupController::class, 'removeMember']);
        });

         Route::prefix('service-orders')->group(function () {
            Route::get('/', [ServiceOrderController::class, 'index']);
            Route::post('/', [ServiceOrderController::class, 'store']);
            Route::get('/{id}', [ServiceOrderController::class, 'show']);
            Route::patch('/{id}', [ServiceOrderController::class, 'update']);
            Route::put('/{id}', [ServiceOrderController::class, 'update']); // ← ADICIONE ESTA LINHA
            Route::delete('/{id}', [ServiceOrderController::class, 'destroy']); 
        });
        // --- Módulo Administrativo (Super Admin) ---
        // Unificado para evitar duplicidade de código
        Route::middleware('admin')->prefix('admin')->group(function () {
            
            // Gestão de Usuários (Controllers em Auth conforme seu código)
            Route::get('/users', [AxionAuthController::class, 'index']);
            Route::get('/users/{id}', [AxionAuthController::class, 'show']);
            // Rota corrigida: Agora ela bate com o Swagger e faz sentido lógico
            Route::post('/users/{id}/assign-role', [PermissionController::class, 'assignRole']);
            Route::post('/users/{id}/promote', [AxionAuthController::class, 'promoteToAdmin']);
            Route::post('/users/{id}/remove-admin', [AxionAuthController::class, 'removeAdmin']);
            Route::patch('/users/{id}/toggle-status', [AxionAuthController::class, 'toggleUserStatus']);
            Route::put('/users/{id}/update-manual', [AxionAuthController::class, 'adminUpdateUser']);
            Route::delete('/users/{id}', [AxionAuthController::class, 'destroy']);

            // --- ADICIONE ESTAS DUAS LINHAS ABAIXO ---
            Route::post('/groups/{roleId}/permissions', [PermissionController::class, 'attachPermissionToRole']);
            Route::delete('/groups/{roleId}/permissions/{permissionId}', [PermissionController::class, 'detachPermissionFromRole']);
    
            // --- ADICIONE ESTA LINHA TAMBÉM SE QUISER QUE A LISTAGEM APAREÇA NO ADMIN ---
            Route::get('/permissions', [PermissionController::class, 'listPermissions']);

            // Gestão de Auditoria e Grupos Admin
            Route::get('/audit-logs', [AuditLogController::class, 'index']);
            Route::get('/groups', [AxionGroupController::class, 'index']);

            // --- NOVA SEÇÃO: Gestão de IAM (Controller em Admin) ---
            Route::prefix('permissions')->group(function () {
                Route::get('/', [PermissionController::class, 'listPermissions']); 
                Route::post('/', [PermissionController::class, 'storePermission']);  
            });
        });
    });
});
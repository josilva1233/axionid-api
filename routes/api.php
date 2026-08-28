<?php

use App\Http\Controllers\Auth\AxionAuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\Auth\AxionGroupController;
use App\Http\Controllers\Auth\AuditLogController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\ServiceOrder\ServiceOrderController;
use App\Http\Controllers\ServiceOrder\ServiceOrderMessageController;
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

        // --- Módulo de Grupos ---
        Route::prefix('groups')->group(function () {
            Route::get('/', [AxionGroupController::class, 'index']);
            Route::post('/', [AxionGroupController::class, 'store']);
            Route::get('/{id}', [AxionGroupController::class, 'show']);
            Route::delete('/{id}', [AxionGroupController::class, 'destroy']);
            Route::post('/{group_id}/members', [AxionGroupController::class, 'addMember']);
            Route::patch('/{group_id}/members/{user_id}/promote', [AxionGroupController::class, 'promoteMember']);
            Route::patch('/{group_id}/members/{user_id}/demote', [AxionGroupController::class, 'demoteMember']);
            Route::delete('/{group_id}/members/{user_id}', [AxionGroupController::class, 'removeMember']);
        });

        // --- Módulo de Ordens de Serviço ---
        Route::prefix('service-orders')->group(function () {
            Route::get('/', [ServiceOrderController::class, 'index']);
            Route::post('/', [ServiceOrderController::class, 'store']);
            Route::get('/{id}', [ServiceOrderController::class, 'show']);
            Route::patch('/{id}', [ServiceOrderController::class, 'update']);
            Route::put('/{id}', [ServiceOrderController::class, 'update']);
            Route::delete('/{id}', [ServiceOrderController::class, 'destroy']); 
        });

            // --- ROTAS DE MENSAGENS (aninhadas) ---
    Route::prefix('{serviceOrderId}/messages')->group(function () {
        Route::get('/', [ServiceOrderMessageController::class, 'index']);
        Route::post('/', [ServiceOrderMessageController::class, 'store']);
        Route::put('/{messageId}', [ServiceOrderMessageController::class, 'update']);
        Route::delete('/{messageId}', [ServiceOrderMessageController::class, 'destroy']);
    });

        // =========================================================
        // --- Módulo Administrativo (Super Admin) ---
        // =========================================================
        Route::middleware('admin')->prefix('admin')->group(function () {
            
            // --- Gestão de Usuários ---
            Route::get('/users', [AxionAuthController::class, 'index']);
            Route::get('/users/{id}', [AxionAuthController::class, 'show']);
            Route::post('/users/{id}/assign-role', [PermissionController::class, 'assignRole']);
            Route::post('/users/{id}/promote', [AxionAuthController::class, 'promoteToAdmin']);
            Route::post('/users/{id}/remove-admin', [AxionAuthController::class, 'removeAdmin']);
            Route::patch('/users/{id}/toggle-status', [AxionAuthController::class, 'toggleUserStatus']);
            Route::put('/users/{id}/update-manual', [AxionAuthController::class, 'adminUpdateUser']);
            Route::delete('/users/{id}', [AxionAuthController::class, 'destroy']);

            // --- Gestão de Grupos (Admin) ---
            Route::get('/groups', [AxionGroupController::class, 'index']);

            // --- Gestão de Permissões (IAM) ---
            Route::prefix('permissions')->group(function () {
                Route::get('/', [PermissionController::class, 'listPermissions']);      // Listar
                Route::get('/{id}', [PermissionController::class, 'showPermission']);    // Detalhes ← ADICIONAR
                Route::post('/', [PermissionController::class, 'storePermission']);       // Criar
                Route::put('/{id}', [PermissionController::class, 'updatePermission']);   // Editar ← ADICIONAR
                Route::delete('/{id}', [PermissionController::class, 'deletePermission']); // Excluir ← ADICIONAR
            });

            // --- Gestão de Permissões em Grupos ---
            Route::post('/groups/{roleId}/permissions', [PermissionController::class, 'attachPermissionToRole']);
            Route::delete('/groups/{roleId}/permissions/{permissionId}', [PermissionController::class, 'detachPermissionFromRole']);

            // --- Gestão de Auditoria ---
            Route::get('/audit-logs', [AuditLogController::class, 'index']);
        });
    });
});
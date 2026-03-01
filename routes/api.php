<?php

use App\Http\Controllers\Auth\AxionAuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SocialAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| API Routes - AxionID
|--------------------------------------------------------------------------
*/

// Health Check com Inteligência de Ambiente
Route::get('/health', function() {
    try {
        DB::connection()->getPdo();
        $dbStatus = 'Connected';
    } catch (\Exception $e) {
        $dbStatus = 'Disconnected';
    }

    $response = [
        'status'   => 'UP',
        'database' => $dbStatus,
    ];

    if (app()->environment() !== 'production') {
        $response['service'] = 'AxionID API';
        $response['environment'] = app()->environment();
        $response['php_version'] = PHP_VERSION;
    }

    return response()->json($response, $dbStatus === 'Connected' ? 200 : 503);
});

// Todas as rotas abaixo têm o prefixo /api/v1
Route::prefix('v1')->group(function () {
    
    // ---------------------------------------------------------
    // ROTAS PÚBLICAS (Abertas)
    // ---------------------------------------------------------
    Route::post('/register', [AxionAuthController::class, 'register']);
    Route::post('/login', [AxionAuthController::class, 'login']);

    // Autenticação Social (Google)
    Route::get('/auth/google', [SocialAuthController::class, 'redirectToGoogle']);
    Route::get('/auth/google/callback', [SocialAuthController::class, 'handleGoogleCallback']);
    
    // ROTA ADICIONADA: Recupera dados do cache via chave temporária 't'
    Route::get('/auth/temp-data/{key}', [SocialAuthController::class, 'getTempData']);
    
    // Rotas de Recuperação de Senha
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink']);
    Route::post('/verify-code', [PasswordResetController::class, 'verifyCode']);
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);

    // ---------------------------------------------------------
    // ROTAS PROTEGIDAS (Requer Token Válido via Sanctum)
    // ---------------------------------------------------------
    Route::middleware('auth:sanctum')->group(function () {
        
        // Perfil e Logout
        Route::post('/logout', [AxionAuthController::class, 'logout']);
        Route::post('/complete-profile', [AxionAuthController::class, 'completeProfile']);
        Route::put('/update-profile', [AxionAuthController::class, 'updateProfile']); 
        
        Route::get('/me', function (Request $request) {
            return $request->user()->load('address');
        });

        // ---------------------------------------------------------
        // PAINEL ADMINISTRATIVO (Apenas usuários com is_admin = 1)
        // ---------------------------------------------------------
        Route::middleware('admin')->group(function () {
            // Listagem e Auditoria
            Route::get('/users', [AxionAuthController::class, 'index']);
            Route::get('/audit-logs', [AxionAuthController::class, 'auditLogs']);
            
            // Gestão de Privilégios
            Route::patch('/users/{id}/promote', [AxionAuthController::class, 'promoteToAdmin']);
            Route::patch('/users/{id}/demote', [AxionAuthController::class, 'demoteFromAdmin']);
            
            // Gestão de Status e Edição (Novas Rotas)
            Route::patch('/users/{id}/toggle-status', [AxionAuthController::class, 'toggleUserStatus']);
            Route::put('/users/{id}/update-manual', [AxionAuthController::class, 'adminUpdateUser']);
            
            // Exclusão
            Route::delete('/users/{id}', [AxionAuthController::class, 'destroy']);
        });
        
    });
});
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
    
    // ---------------------------------------------------------
    // ROTAS PÚBLICAS
    // ---------------------------------------------------------
    Route::post('/register', [AxionAuthController::class, 'register']);
    Route::post('/login', [AxionAuthController::class, 'login']);
    Route::middleware('auth:sanctum')->post('/v1/complete-profile', [SocialAuthController::class, 'completeProfile']);

    // Autenticação Social (Google)
    Route::get('/auth/google', [SocialAuthController::class, 'redirectToGoogle']);
    Route::get('/auth/google/callback', [SocialAuthController::class, 'handleGoogleCallback']);
    
    // Rotas de Recuperação de Senha
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink']);
    Route::post('/verify-code', [PasswordResetController::class, 'verifyCode']);
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);

    // ---------------------------------------------------------
    // ROTAS PROTEGIDAS (Sanctum)
    // ---------------------------------------------------------
    Route::middleware('auth:sanctum')->group(function () {
        
        Route::post('/logout', [AxionAuthController::class, 'logout']);
        
        // Finalização de perfil para quem vem do Google (Onde gravamos o CPF)
        Route::post('/complete-profile', [SocialAuthController::class, 'completeProfile']);
        
        Route::put('/update-profile', [AxionAuthController::class, 'updateProfile']); 
        
        Route::get('/me', function (Request $request) {
            return $request->user()->load('address');
        });

        // ---------------------------------------------------------
        // PAINEL ADMINISTRATIVO (is_admin = 1)
        // ---------------------------------------------------------
        Route::middleware('admin')->group(function () {
            Route::get('/users', [AxionAuthController::class, 'index']);
            Route::get('/audit-logs', [AxionAuthController::class, 'auditLogs']);
            Route::patch('/users/{id}/promote', [AxionAuthController::class, 'promoteToAdmin']);
            Route::patch('/users/{id}/demote', [AxionAuthController::class, 'demoteFromAdmin']);
            Route::patch('/users/{id}/toggle-status', [AxionAuthController::class, 'toggleUserStatus']);
            Route::put('/users/{id}/update-manual', [AxionAuthController::class, 'adminUpdateUser']);
            Route::delete('/users/{id}', [AxionAuthController::class, 'destroy']);
        });
    });
});
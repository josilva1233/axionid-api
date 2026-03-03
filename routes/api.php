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

Route::get('/health', function () {
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

    /*
    |--------------------------------------------------------------------------
    | 🔓 ROTAS PÚBLICAS
    |--------------------------------------------------------------------------
    */

    Route::post('/register', [AxionAuthController::class, 'register']);
    Route::post('/login', [AxionAuthController::class, 'login']);

    // 🔐 Google OAuth
    Route::get('/auth/google', [SocialAuthController::class, 'redirectToGoogle'])
        ->name('google.redirect');

    Route::get('/auth/google/callback', [SocialAuthController::class, 'handleGoogleCallback'])
        ->name('google.callback');

    // 🔁 Recuperação de senha
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink']);
    Route::post('/verify-code', [PasswordResetController::class, 'verifyCode']);
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);

    /*
    |--------------------------------------------------------------------------
    | 🔒 ROTAS PROTEGIDAS (Sanctum)
    |--------------------------------------------------------------------------
    */

    Route::middleware('auth:sanctum')->group(function () {

        Route::post('/logout', [AxionAuthController::class, 'logout']);

        // ✅ Completar cadastro Google
        Route::post('/complete-profile', [SocialAuthController::class, 'completeProfile']);

        Route::put('/update-profile', [AxionAuthController::class, 'updateProfile']);

        Route::get('/me', function (Request $request) {
            return $request->user()->load('address');
        });

        /*
        |--------------------------------------------------------------------------
        | 👑 ADMIN (middleware admin)
        |--------------------------------------------------------------------------
        */

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

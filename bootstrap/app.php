<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Registra o apelido para o middleware de administrador
        $middleware->alias([
            'admin' => \App\Http\Middleware\CheckAdmin::class,
        ]);

        // ADICIONE ESTA LINHA ABAIXO:
        // O 'append' faz com que o AuditMiddleware seja executado em todas as requisições
        $middleware->append(\App\Http\Middleware\AuditMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
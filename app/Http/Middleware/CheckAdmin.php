<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Verifica se o usuário está autenticado e se é administrador
        if (auth()->check() && auth()->user()->is_admin) {
            return $next($request);
        }

        // Se não for admin, retorna erro 403 (Proibido)
        return response()->json([
            'message' => 'Acesso negado. Esta área é restrita a administradores.'
        ], 403);
    }
}
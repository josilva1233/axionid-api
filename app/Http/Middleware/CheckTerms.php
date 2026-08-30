<?php
// app/Http/Middleware/CheckTerms.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Term;

class CheckTerms
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        // Rotas que não precisam verificar termos
        $excludedRoutes = [
            'terms/check',
            'terms/accept',
            'logout',
        ];

        if (in_array($request->path(), $excludedRoutes)) {
            return $next($request);
        }

        $currentTerm = Term::getActiveTerm();

        // Se não há termo ativo, permite acesso
        if (!$currentTerm) {
            return $next($request);
        }

        // Verifica se o usuário aceitou o termo atual
        if (!$user->hasAcceptedCurrentTerm()) {
            return response()->json([
                'message' => 'Você precisa aceitar os termos de uso para continuar',
                'needs_acceptance' => true,
                'term_id' => $currentTerm->id,
            ], 403);
        }

        return $next($request);
    }
}
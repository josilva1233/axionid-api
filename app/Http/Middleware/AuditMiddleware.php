<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // Deixa a requisição acontecer primeiro
        $response = $next($request);

        // Salva o log apenas para métodos que alteram dados (ou todos, se preferir)
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            DB::table('audit_logs')->insert([
                'user_id'    => auth()->id(),
                'method'     => $request->method(),
                'url'        => $request->fullUrl(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'payload'    => json_encode($request->except(['password', 'password_confirmation'])),
                'payload' => json_encode($request->all()),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $response;
    }
}
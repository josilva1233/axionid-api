<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use OpenApi\Attributes as OA;

class AuditLogController extends Controller
{
    #[OA\Get(
        path: '/api/v1/admin/audit-logs',
        summary: 'Ver logs de auditoria (Admin)',
        tags: ['Administração'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(
                name: 'method',
                in: 'query',
                description: 'Filtrar por método HTTP (GET, POST, etc)',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'POST')
            ),
            new OA\Parameter(
                name: 'start_date',
                in: 'query',
                description: 'Data inicial (AAAA-MM-DD)',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-09-02')
            ),
            new OA\Parameter(
                name: 'end_date',
                in: 'query',
                description: 'Data final (AAAA-MM-DD)',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-09-02')
            ),
            new OA\Parameter(
                name: 'page',
                in: 'query',
                description: 'Número da página',
                required: false,
                schema: new OA\Schema(type: 'integer', example: 1)
            )
        ],
        responses: [
            new OA\Response(response: 200, description: 'Logs de auditoria recuperados com sucesso'),
            new OA\Response(response: 403, description: 'Acesso negado')
        ]
    )]
    public function index(Request $request)
    {
        if (!Auth::user()->is_admin) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $query = AuditLog::with('user:id,name,email');

        // Filtro por usuário
        if ($request->filled('user')) {
            $search = $request->user;
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('email', 'LIKE', "%{$search}%");
            });
        }

        // Filtro por URL
        if ($request->filled('url')) {
            $query->where('url', 'LIKE', "%{$request->url}%");
        }

        // Filtro por método
        if ($request->filled('method')) {
            $query->where('method', strtoupper($request->method));
        }

        // 🔥 Filtro por data de início (corrigido)
        if ($request->filled('start_date')) {
            $start = Carbon::parse($request->start_date)->startOfDay();
            $query->where('created_at', '>=', $start);
        }

        // 🔥 Filtro por data de fim (corrigido)
        if ($request->filled('end_date')) {
            $end = Carbon::parse($request->end_date)->endOfDay();
            $query->where('created_at', '<=', $end);
        }

        return response()->json(
            $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 20)
        );
    }
}
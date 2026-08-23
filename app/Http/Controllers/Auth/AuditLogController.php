<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class AuditLogController extends Controller
{
    #[OA\Get(
        path: '/api/v1/admin/audit-logs',
        summary: 'Ver logs de auditoria (Admin)',
        tags: ['Administração'],
        security: [['sanctum' => []]],
        // Adicionando os parâmetros para o Swagger detectar os campos de pesquisa
        parameters: [
            new OA\Parameter(
                name: 'method',
                in: 'query',
                description: 'Filtrar por método HTTP (GET, POST, etc)',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'POST')
            ),
            new OA\Parameter(
                name: 'date',
                in: 'query',
                description: 'Filtrar por data específica (AAAA-MM-DD)',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2023-10-27')
            ),
            new OA\Parameter(
                name: 'page',
                in: 'query',
                description: 'Número da página para paginação',
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

    // Filtros
    if ($request->filled('user')) {
        $search = $request->user;
        $query->whereHas('user', function ($q) use ($search) {
            $q->where('name', 'LIKE', "%{$search}%")
              ->orWhere('email', 'LIKE', "%{$search}%");
        });
    }

    if ($request->filled('url')) {
        $query->where('url', 'LIKE', "%{$request->url}%");
    }

    if ($request->filled('method')) {
        $query->where('method', strtoupper($request->method));
    }

    if ($request->filled('start_date')) {
        $query->whereDate('created_at', '>=', $request->start_date);
    }

    if ($request->filled('end_date')) {
        $query->whereDate('created_at', '<=', $request->end_date);
    }

    return response()->json(
        $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 20)
    );
}
}
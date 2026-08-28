<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AuditLogController extends Controller
{
    #[OA\Get(
        path: '/api/v1/admin/audit-logs',
        summary: 'Listar logs de auditoria',
        description: 'Retorna os logs de auditoria com filtros opcionais. Apenas administradores podem acessar.',
        tags: ['Auditoria'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(
                name: 'page',
                in: 'query',
                description: 'Número da página',
                required: false,
                schema: new OA\Schema(type: 'integer', example: 1)
            ),
            new OA\Parameter(
                name: 'per_page',
                in: 'query',
                description: 'Itens por página',
                required: false,
                schema: new OA\Schema(type: 'integer', example: 20)
            ),
            new OA\Parameter(
                name: 'user',
                in: 'query',
                description: 'Filtrar por nome ou email do usuário',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'admin')
            ),
            new OA\Parameter(
                name: 'method',
                in: 'query',
                description: 'Filtrar por método HTTP (GET, POST, PUT, DELETE, etc.)',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'POST')
            ),
            new OA\Parameter(
                name: 'url',
                in: 'query',
                description: 'Filtrar por URL (parcial)',
                required: false,
                schema: new OA\Schema(type: 'string', example: '/api/v1/users')
            ),
            new OA\Parameter(
                name: 'start_date',
                in: 'query',
                description: 'Data inicial (YYYY-MM-DD)',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-01-01')
            ),
            new OA\Parameter(
                name: 'end_date',
                in: 'query',
                description: 'Data final (YYYY-MM-DD)',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-12-31')
            ),
            new OA\Parameter(
                name: 'action',
                in: 'query',
                description: 'Filtrar por ação (payload->action)',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'message_created')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de logs recuperada com sucesso'),
            new OA\Response(response: 403, description: 'Sem permissão (apenas admin)'),
        ]
    )]
    public function index(Request $request)
    {
        // Verifica se o usuário é admin (reforço, pois o middleware 'admin' já deve estar aplicado)
        if (!auth()->user()->is_admin) {
            return response()->json(['message' => 'Apenas administradores podem acessar os logs de auditoria.'], 403);
        }

        $perPage = $request->input('per_page', 20);

        $query = AuditLog::with('user')->latest();

        // Filtros
        if ($request->filled('user')) {
            $search = $request->input('user');
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('method')) {
            $query->where('method', $request->method);
        }

        if ($request->filled('url')) {
            $query->where('url', 'like', '%' . $request->url . '%');
        }

        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        // Filtro por ação (dentro do payload JSON)
        if ($request->filled('action')) {
            $query->where('payload->action', $request->action);
        }

        $logs = $query->paginate($perPage);

        return response()->json($logs);
    }

    #[OA\Get(
        path: '/api/v1/admin/audit-logs/{id}',
        summary: 'Exibir um log de auditoria específico',
        tags: ['Auditoria'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Log recuperado'),
            new OA\Response(response: 403, description: 'Sem permissão'),
            new OA\Response(response: 404, description: 'Log não encontrado')
        ]
    )]
    public function show($id)
    {
        if (!auth()->user()->is_admin) {
            return response()->json(['message' => 'Apenas administradores podem acessar os logs de auditoria.'], 403);
        }

        $log = AuditLog::with('user')->findOrFail($id);
        return response()->json($log);
    }
}
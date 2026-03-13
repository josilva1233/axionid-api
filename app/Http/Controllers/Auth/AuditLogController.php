<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class AuditLogController extends Controller
{
    #[OA\Get(
        path: '/api/v1/admin/audit-logs',
        summary: 'Ver logs de auditoria (Admin)',
        tags: ['Administração'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Logs de auditoria'),
            new OA\Response(response: 403, description: 'Acesso negado')
        ]
    )]
    public function index(Request $request)
    {
        // Mantendo a lógica de proteção original
        if (!Auth::user()->is_admin) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $query = DB::table('audit_logs')
            ->leftJoin('users', 'audit_logs.user_id', '=', 'users.id')
            ->select('audit_logs.*', 'users.name as user_name', 'users.email as user_email');

        // Filtros originais mantidos
        if ($request->filled('method')) {
            $query->where('audit_logs.method', strtoupper($request->method));
        }

        if ($request->filled('date')) {
            $query->whereDate('audit_logs.created_at', $request->date);
        }

        return response()->json($query->orderBy('audit_logs.created_at', 'desc')->paginate(20));
    }
}
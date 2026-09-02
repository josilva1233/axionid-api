<?php
// app/Http/Controllers/Auth/TermController.php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Term;
use App\Models\User;
use App\Models\UserTermAcceptance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Termos de Uso', description: 'Gerenciamento de termos de uso e aceitações')]
class TermController extends Controller
{
    /**
     * Verifica se o usuário precisa aceitar os termos
     */
    #[OA\Get(
        path: '/api/v1/terms/check',
        summary: 'Verificar necessidade de aceitação dos termos',
        tags: ['Termos de Uso'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Status atual do usuário em relação aos termos',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'authenticated', type: 'boolean'),
                        new OA\Property(property: 'needs_acceptance', type: 'boolean'),
                        new OA\Property(property: 'term', type: 'object', nullable: true,
                            properties: [
                                new OA\Property(property: 'id', type: 'integer'),
                                new OA\Property(property: 'content', type: 'string'),
                                new OA\Property(property: 'version', type: 'string'),
                                new OA\Property(property: 'published_at', type: 'string', format: 'date-time'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Não autenticado')
        ]
    )]
    public function checkStatus(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'authenticated' => false,
                'needs_acceptance' => false,
            ]);
        }

        $currentTerm = Term::getActiveTerm();
        
        if (!$currentTerm) {
            return response()->json([
                'authenticated' => true,
                'needs_acceptance' => false,
                'message' => 'Nenhum termo ativo disponível',
            ]);
        }

        $hasAccepted = $user->hasAcceptedCurrentTerm();

        return response()->json([
            'authenticated' => true,
            'needs_acceptance' => !$hasAccepted,
            'term' => !$hasAccepted ? [
                'id' => $currentTerm->id,
                'content' => $currentTerm->content,
                'version' => $currentTerm->version,
                'published_at' => $currentTerm->published_at,
            ] : null,
        ]);
    }

    /**
     * Aceitar os termos de uso
     */
    #[OA\Post(
        path: '/api/v1/terms/accept',
        summary: 'Aceitar os termos de uso atuais',
        tags: ['Termos de Uso'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'term_id', type: 'integer', example: 1)
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Termos aceitos com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'accepted', type: 'boolean'),
                        new OA\Property(property: 'accepted_at', type: 'string', format: 'date-time'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 422, description: 'Erro de validação')
        ]
    )]
    public function accept(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Usuário não autenticado'], 401);
        }

        $validator = Validator::make($request->all(), [
            'term_id' => 'required|exists:terms,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $term = Term::find($request->term_id);

        if (!$term->is_active || !$term->published_at) {
            return response()->json([
                'message' => 'Este termo não está mais ativo ou não foi publicado',
            ], 422);
        }

        if ($term->isAcceptedByUser($user)) {
            return response()->json([
                'message' => 'Você já aceitou este termo',
                'already_accepted' => true,
            ]);
        }

        try {
            $acceptance = $user->acceptTerm(
                $term,
                $request->ip(),
                $request->userAgent()
            );

            Log::info('Termo de uso aceito', [
                'user_id' => $user->id,
                'term_id' => $term->id,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Termos de uso aceitos com sucesso',
                'accepted' => true,
                'accepted_at' => $acceptance->accepted_at,
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao aceitar termos', [
                'user_id' => $user->id,
                'term_id' => $term->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Erro ao aceitar os termos',
            ], 500);
        }
    }

    /**
     * Obter termo atual (público)
     */
    #[OA\Get(
        path: '/api/v1/terms/current',
        summary: 'Obter termo de uso ativo (público)',
        tags: ['Termos de Uso'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Termo ativo atual',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'content', type: 'string'),
                        new OA\Property(property: 'version', type: 'string'),
                        new OA\Property(property: 'published_at', type: 'string', format: 'date-time'),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Nenhum termo ativo disponível')
        ]
    )]
    public function getCurrentTerm()
    {
        $term = Term::getActiveTerm();

        if (!$term) {
            return response()->json(['message' => 'Nenhum termo ativo disponível'], 404);
        }

        return response()->json([
            'id' => $term->id,
            'content' => $term->content,
            'version' => $term->version,
            'published_at' => $term->published_at,
        ]);
    }

    // =========================================================
    // ADMIN: Gestão de Termos
    // =========================================================

    /**
     * Listar todos os termos (Admin)
     */
    #[OA\Get(
        path: '/api/v1/admin/terms',
        summary: 'Listar todos os termos (Admin)',
        tags: ['Termos de Uso'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'is_active', in: 'query', schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista paginada de termos'),
            new OA\Response(response: 403, description: 'Acesso negado')
        ]
    )]
    public function index(Request $request)
    {
        if (!Auth::user()->is_admin) {
            return response()->json(['message' => 'Acesso negado'], 403);
        }

        $query = Term::with('creator');
        
        if ($request->has('search')) {
            $query->where('content', 'like', '%' . $request->search . '%');
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->is_active === 'true');
        }

        return response()->json($query->orderBy('created_at', 'desc')->paginate(15));
    }

    /**
     * Criar novo termo (Admin)
     */
    #[OA\Post(
        path: '/api/v1/admin/terms',
        summary: 'Criar novo termo de uso (Admin)',
        tags: ['Termos de Uso'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'content', type: 'string', example: 'Conteúdo do termo...'),
                    new OA\Property(property: 'version', type: 'string', example: '1.0.0'),
                    new OA\Property(property: 'is_active', type: 'boolean', example: true),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Termo criado com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'term', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Acesso negado'),
            new OA\Response(response: 422, description: 'Erro de validação')
        ]
    )]
    public function store(Request $request)
    {
        if (!Auth::user()->is_admin) {
            return response()->json(['message' => 'Acesso negado'], 403);
        }

        $validator = Validator::make($request->all(), [
            'content' => 'required|string|min:10',
            'version' => 'required|string|max:20',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Se este termo for ativo, desativa os outros
        if ($request->is_active) {
            Term::where('is_active', true)->update(['is_active' => false]);
        }

        $term = Term::create([
            'content' => $request->content,
            'version' => $request->version,
            'is_active' => $request->is_active ?? false,
            'created_by' => Auth::id(),
            'published_at' => $request->is_active ? now() : null,
        ]);

        Log::info('Novo termo de uso criado', [
            'term_id' => $term->id,
            'created_by' => Auth::id(),
            'version' => $term->version,
        ]);

        return response()->json([
            'message' => 'Termo criado com sucesso',
            'term' => $term->load('creator'),
        ], 201);
    }

    /**
     * Atualizar termo (Admin)
     */
    #[OA\Put(
        path: '/api/v1/admin/terms/{id}',
        summary: 'Atualizar termo de uso (Admin)',
        tags: ['Termos de Uso'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'content', type: 'string', example: 'Conteúdo atualizado...'),
                    new OA\Property(property: 'version', type: 'string', example: '1.0.1'),
                    new OA\Property(property: 'is_active', type: 'boolean', example: false),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Termo atualizado com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'term', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Acesso negado'),
            new OA\Response(response: 422, description: 'Erro de validação')
        ]
    )]
    public function update(Request $request, $id)
    {
        if (!Auth::user()->is_admin) {
            return response()->json(['message' => 'Acesso negado'], 403);
        }

        $term = Term::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'content' => 'sometimes|string|min:10',
            'version' => 'sometimes|string|max:20',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Se for ativar este termo, desativa os outros
        if ($request->has('is_active') && $request->is_active === true) {
            Term::where('is_active', true)
                ->where('id', '!=', $term->id)
                ->update(['is_active' => false]);
        }

        $term->update($request->only(['content', 'version', 'is_active']));

        // Atualiza published_at se for ativado
        if ($request->has('is_active') && $request->is_active === true && !$term->published_at) {
            $term->update(['published_at' => now()]);
        }

        Log::info('Termo de uso atualizado', [
            'term_id' => $term->id,
            'updated_by' => Auth::id(),
        ]);

        return response()->json([
            'message' => 'Termo atualizado com sucesso',
            'term' => $term->load('creator'),
        ]);
    }

    /**
     * Excluir termo (Admin)
     */
    #[OA\Delete(
        path: '/api/v1/admin/terms/{id}',
        summary: 'Excluir termo de uso (Admin)',
        tags: ['Termos de Uso'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Termo excluído com sucesso'),
            new OA\Response(response: 403, description: 'Acesso negado'),
            new OA\Response(response: 422, description: 'Não é possível excluir termo que já foi aceito')
        ]
    )]
    public function destroy($id)
    {
        if (!Auth::user()->is_admin) {
            return response()->json(['message' => 'Acesso negado'], 403);
        }

        $term = Term::findOrFail($id);

        if ($term->acceptances()->count() > 0) {
            return response()->json([
                'message' => 'Não é possível excluir um termo que já foi aceito por usuários',
            ], 422);
        }

        $term->delete();

        Log::info('Termo de uso excluído', [
            'term_id' => $id,
            'deleted_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Termo excluído com sucesso']);
    }

    /**
     * Ativar/Desativar termo (Admin)
     */
    #[OA\Patch(
        path: '/api/v1/admin/terms/{id}/toggle',
        summary: 'Ativar/Desativar termo (Admin)',
        tags: ['Termos de Uso'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Status atualizado',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'is_active', type: 'boolean'),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Acesso negado')
        ]
    )]
    public function toggleStatus($id)
    {
        if (!Auth::user()->is_admin) {
            return response()->json(['message' => 'Acesso negado'], 403);
        }

        $term = Term::findOrFail($id);

        if (!$term->is_active) {
            Term::where('is_active', true)->update(['is_active' => false]);
            $term->update([
                'is_active' => true,
                'published_at' => $term->published_at ?? now(),
            ]);
        } else {
            $term->update(['is_active' => false]);
        }

        return response()->json([
            'message' => 'Status do termo atualizado',
            'is_active' => $term->is_active,
        ]);
    }

    // =========================================================
    // ADMIN: Visualização de Aceitações
    // =========================================================

    /**
     * Listar usuários que aceitaram os termos (Admin)
     */
    #[OA\Get(
        path: '/api/v1/admin/terms/acceptances',
        summary: 'Listar aceitações de termos (Admin)',
        tags: ['Termos de Uso'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'term_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista paginada de aceitações',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items()),
                        new OA\Property(property: 'meta', type: 'object'),
                        new OA\Property(property: 'terms', type: 'array', items: new OA\Items()),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Acesso negado')
        ]
    )]
    public function acceptances(Request $request)
    {
        if (!Auth::user()->is_admin) {
            return response()->json(['message' => 'Acesso negado'], 403);
        }

        $query = UserTermAcceptance::with(['user', 'term'])
            ->when($request->term_id, function ($q) use ($request) {
                return $q->where('term_id', $request->term_id);
            })
            ->when($request->search, function ($q) use ($request) {
                return $q->whereHas('user', function ($query) use ($request) {
                    $query->where('name', 'like', "%{$request->search}%")
                          ->orWhere('email', 'like', "%{$request->search}%");
                });
            })
            ->latest('accepted_at');

        $acceptances = $query->paginate(15);
        $terms = Term::orderBy('version', 'desc')->get();

        return response()->json([
            'data' => $acceptances->items(),
            'meta' => [
                'current_page' => $acceptances->currentPage(),
                'last_page' => $acceptances->lastPage(),
                'per_page' => $acceptances->perPage(),
                'total' => $acceptances->total(),
            ],
            'terms' => $terms,
        ]);
    }

    /**
     * Listar usuários que NÃO aceitaram o termo atual (Admin)
     */
    #[OA\Get(
        path: '/api/v1/admin/terms/acceptances/pending',
        summary: 'Listar usuários pendentes de aceitação (Admin)',
        tags: ['Termos de Uso'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista paginada de usuários pendentes',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items()),
                        new OA\Property(property: 'meta', type: 'object'),
                        new OA\Property(property: 'term', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Acesso negado'),
            new OA\Response(response: 404, description: 'Nenhum termo ativo')
        ]
    )]
    public function pendingAcceptances(Request $request)
    {
        if (!Auth::user()->is_admin) {
            return response()->json(['message' => 'Acesso negado'], 403);
        }

        $currentTerm = Term::getActiveTerm();

        if (!$currentTerm) {
            return response()->json([
                'message' => 'Nenhum termo ativo'
            ], 404);
        }

        $users = User::whereDoesntHave('userTermAcceptances', function ($q) use ($currentTerm) {
            $q->where('term_id', $currentTerm->id);
        })
        ->when($request->search, function ($q) use ($request) {
            $q->where('name', 'like', "%{$request->search}%")
              ->orWhere('email', 'like', "%{$request->search}%");
        })
        ->paginate(15);

        return response()->json([
            'data' => $users->items(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
            'term' => $currentTerm,
        ]);
    }

    /**
     * Estatísticas de aceitação (Admin)
     */
    #[OA\Get(
        path: '/api/v1/admin/terms/acceptances/stats',
        summary: 'Obter estatísticas de aceitação (Admin)',
        tags: ['Termos de Uso'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Estatísticas de aceitação',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'total_users', type: 'integer'),
                        new OA\Property(property: 'current_term', type: 'object'),
                        new OA\Property(property: 'terms_stats', type: 'array', items: new OA\Items()),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Acesso negado')
        ]
    )]
    public function acceptanceStats()
    {
        if (!Auth::user()->is_admin) {
            return response()->json(['message' => 'Acesso negado'], 403);
        }

        $totalUsers = User::count();
        $currentTerm = Term::getActiveTerm();

        $stats = [
            'total_users' => $totalUsers,
        ];

        if ($currentTerm) {
            $acceptedCount = UserTermAcceptance::where('term_id', $currentTerm->id)->count();
            $stats['current_term'] = [
                'id' => $currentTerm->id,
                'version' => $currentTerm->version,
                'accepted_count' => $acceptedCount,
                'pending_count' => max(0, $totalUsers - $acceptedCount),
                'acceptance_rate' => $totalUsers > 0 
                    ? round(($acceptedCount / $totalUsers) * 100, 2) 
                    : 0,
            ];
        }

        $termsStats = Term::withCount('userTermAcceptances')
            ->orderBy('version', 'desc')
            ->get()
            ->map(function ($term) use ($totalUsers) {
                return [
                    'id' => $term->id,
                    'version' => $term->version,
                    'is_active' => $term->is_active,
                    'accepted_count' => $term->user_term_acceptances_count,
                    'acceptance_rate' => $totalUsers > 0 
                        ? round(($term->user_term_acceptances_count / $totalUsers) * 100, 2) 
                        : 0,
                ];
            });

        $stats['terms_stats'] = $termsStats;

        return response()->json($stats);
    }

    /**
     * Exportar lista de usuários que aceitaram um termo (CSV)
     */
    #[OA\Get(
        path: '/api/v1/admin/terms/acceptances/export/{termId}',
        summary: 'Exportar aceitações de um termo (CSV) (Admin)',
        tags: ['Termos de Uso'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'termId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Arquivo CSV com a lista de usuários que aceitaram o termo',
                content: new OA\MediaType(
                    mediaType: 'text/csv'
                )
            ),
            new OA\Response(response: 403, description: 'Acesso negado'),
            new OA\Response(response: 404, description: 'Termo não encontrado')
        ]
    )]
    public function exportAcceptances($termId)
    {
        if (!Auth::user()->is_admin) {
            return response()->json(['message' => 'Acesso negado'], 403);
        }

        $term = Term::findOrFail($termId);

        $acceptances = UserTermAcceptance::with(['user'])
            ->where('term_id', $termId)
            ->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=usuarios_aceitaram_termo_v{$term->version}.csv",
        ];

        $callback = function() use ($acceptances) {
            $handle = fopen('php://output', 'w');
            
            fputcsv($handle, ['ID', 'Nome', 'Email', 'Data de Aceitação', 'IP', 'User Agent']);

            foreach ($acceptances as $acceptance) {
                fputcsv($handle, [
                    $acceptance->user->id,
                    $acceptance->user->name,
                    $acceptance->user->email,
                    $acceptance->accepted_at?->format('d/m/Y H:i:s') ?? 'N/A',
                    $acceptance->ip_address ?? 'N/A',
                    $acceptance->user_agent ?? 'N/A',
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }
}
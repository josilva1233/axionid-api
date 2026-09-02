<?php

namespace App\Http\Controllers\ServiceOrder;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Group;
use App\Models\ServiceOrder;
use App\Notifications\ServiceOrderNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use OpenApi\Attributes as OA;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

#[OA\Tag(name: 'Ordens de Serviço', description: 'Gerenciamento de chamados e ordens de serviço')]
class ServiceOrderController extends Controller
{
    #[OA\Get(
        path: '/api/v1/service-orders',
        summary: 'Listar Ordens de Serviço',
        description: 'Retorna as OSs vinculadas ao usuário, ao seu grupo ou todas se for admin, permitindo filtros opcionais.',
        tags: ['Ordens de Serviço'],
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
                schema: new OA\Schema(type: 'integer', example: 10)
            ),
            new OA\Parameter(
                name: 'protocol',
                in: 'query',
                description: 'Filtrar por protocolo (parcial)',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'OS-2026')
            ),
            new OA\Parameter(
                name: 'title',
                in: 'query',
                description: 'Filtrar por título/assunto (parcial)',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'Erro no sistema')
            ),
            new OA\Parameter(
                name: 'applicant',
                in: 'query',
                description: 'Filtrar por nome do solicitante',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'Luana')
            ),
            new OA\Parameter(
                name: 'priority',
                in: 'query',
                description: 'Filtrar por prioridade',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['low', 'medium', 'high', 'urgent'])
            ),
            new OA\Parameter(
                name: 'status',
                in: 'query',
                description: 'Filtrar por status',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['open', 'in_progress', 'completed', 'canceled'])
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de OS recuperada com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'current_page', type: 'integer'),
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ServiceOrder')),
                        new OA\Property(property: 'last_page', type: 'integer'),
                        new OA\Property(property: 'total', type: 'integer'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Não autenticado')
        ]
    )]
    public function index(Request $request)
    {
        $user = auth()->user();
        $perPage = $request->input('per_page', 10);
        
        $query = ServiceOrder::with(['user', 'group', 'technician'])->latest();
        
        if ($request->filled('protocol')) {
            $query->where('protocol', 'like', '%' . $request->protocol . '%');
        }

        if ($request->filled('title')) {
            $query->where('title', 'like', '%' . $request->title . '%');
        }

        if ($request->filled('applicant') || $request->filled('solicitante')) {
            $search = $request->input('applicant', $request->input('solicitante'));
            $query->whereHas('user', function($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%');
            });
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($user->is_admin) {
            $orders = $query->paginate($perPage);
        } else {
            $groupIds = $user->groups->pluck('id');
            $orders = $query->where(function($q) use ($user, $groupIds) {
                $q->where('user_id', $user->id)
                  ->orWhereIn('group_id', $groupIds);
            })->paginate($perPage);
        }

        return response()->json($orders);
    }

    #[OA\Post(
        path: '/api/v1/service-orders',
        summary: 'Abrir nova Ordem de Serviço',
        description: 'Cria uma OS individual ou para um grupo, com suporte a anexo.',
        tags: ['Ordens de Serviço'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['title', 'description', 'priority'],
                    properties: [
                        new OA\Property(property: 'title', type: 'string', example: 'Problema no acesso ao sistema'),
                        new OA\Property(property: 'description', type: 'string', example: 'Não consigo logar desde hoje cedo'),
                        new OA\Property(property: 'priority', type: 'string', enum: ['low', 'medium', 'high', 'urgent']),
                        new OA\Property(property: 'group_id', type: 'integer', nullable: true, example: 1),
                        new OA\Property(property: 'attachment', type: 'string', format: 'binary', description: 'Arquivo PDF ou Imagem')
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'OS criada com sucesso',
                content: new OA\JsonContent(ref: '#/components/schemas/ServiceOrder')
            ),
            new OA\Response(response: 422, description: 'Erro de validação')
        ]
    )]
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string',
            'description' => 'required',
            'priority' => 'required|in:low,medium,high,urgent',
            'group_id' => 'nullable|exists:groups,id',
            'attachment' => 'nullable|file|mimes:pdf,jpg,png|max:5120',
        ]);

        $path = $request->hasFile('attachment') 
            ? $request->file('attachment')->store('attachments', 'public') 
            : null;

        $os = ServiceOrder::create([
            'protocol' => 'OS-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4)),
            'user_id' => auth()->id(),
            'group_id' => $request->group_id,
            'title' => $request->title,
            'description' => $request->description,
            'priority' => $request->priority,
            'attachment_path' => $path,
        ]);

        $os->load(['user', 'technician', 'group']);

        try {
            $this->sendNewOrderNotification($os);
        } catch (\Exception $e) {
            Log::error('Erro ao enviar notificação de novo chamado:', [
                'order_id' => $os->id,
                'error' => $e->getMessage()
            ]);
        }

        return response()->json($os, 201);
    }

    #[OA\Get(
        path: '/api/v1/service-orders/{id}',
        summary: 'Obter detalhes de uma Ordem de Serviço',
        tags: ['Ordens de Serviço'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                description: 'ID da ordem de serviço'
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Detalhes da OS',
                content: new OA\JsonContent(ref: '#/components/schemas/ServiceOrder')
            ),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 404, description: 'OS não encontrada')
        ]
    )]
    public function show($id)
    {
        $order = ServiceOrder::with(['user', 'group', 'technician'])->find($id);

        if (!$order) {
            return response()->json(['message' => 'Ordem de serviço não encontrada'], 404);
        }

        return response()->json($order);
    }

    #[OA\Put(
        path: '/api/v1/service-orders/{id}',
        summary: 'Atualizar/Atender Ordem de Serviço',
        description: 'Permite mudar o status (ex: in_progress, completed) e adicionar observações.',
        tags: ['Ordens de Serviço'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                description: 'ID da ordem de serviço'
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['open', 'in_progress', 'completed', 'canceled'], example: 'in_progress'),
                    new OA\Property(property: 'technician_notes', type: 'string', example: 'Troca de cabo realizada com sucesso.'),
                    new OA\Property(property: 'technician_id', type: 'integer', nullable: true, example: 5)
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'OS atualizada com sucesso',
                content: new OA\JsonContent(ref: '#/components/schemas/ServiceOrder')
            ),
            new OA\Response(response: 403, description: 'Sem permissão'),
            new OA\Response(response: 404, description: 'OS não encontrada')
        ]
    )]
    public function update(Request $request, $id)
    {
        $os = ServiceOrder::with(['user', 'technician', 'group'])->findOrFail($id);
        
        if (!auth()->user()->is_admin && $os->user_id !== auth()->id()) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        $oldStatus = $os->status;
        $oldTechnicianId = $os->technician_id;
        $user = auth()->user();

        if ($request->has('status')) {
            $os->status = $request->status;
        }

        if ($request->status === 'in_progress' && !$os->technician_id) {
            $os->technician_id = $user->id;
        }

        if ($request->has('technician_id')) {
            $os->technician_id = $request->technician_id;
        }

        $os->save();
        $os->load(['user', 'technician', 'group']);

        try {
            if ($request->has('status') && $oldStatus !== $os->status) {
                $this->sendStatusChangeNotification($os, $oldStatus, $user);
            }
            
            if ($os->technician_id && $oldTechnicianId !== $os->technician_id) {
                $this->sendAssignmentNotification($os, $user);
            }
        } catch (\Exception $e) {
            Log::error('Erro ao enviar notificações de atualização:', [
                'order_id' => $os->id,
                'error' => $e->getMessage()
            ]);
        }

        return response()->json($os);
    }

    #[OA\Delete(
        path: '/api/v1/service-orders/{id}',
        summary: 'Excluir Ordem de Serviço',
        description: 'Remove permanentemente uma OS. Apenas administradores podem excluir.',
        tags: ['Ordens de Serviço'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                description: 'ID da ordem de serviço'
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'OS excluída com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Ordem de serviço excluída com sucesso.')
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Sem permissão'),
            new OA\Response(response: 404, description: 'OS não encontrada')
        ]
    )]
    public function destroy($id)
    {
        $order = ServiceOrder::findOrFail($id);
        
        if (!auth()->user()->is_admin) {
            return response()->json(['message' => 'Apenas administradores podem excluir ordens de serviço.'], 403);
        }
        
        if ($order->attachment_path) {
            Storage::disk('public')->delete($order->attachment_path);
        }
        
        $order->delete();
        
        return response()->json(['message' => 'Ordem de serviço excluída com sucesso.'], 200);
    }

    // ================================================================
    // 🔥 MÉTODO: Buscar grupos disponíveis para o formulário
    // ================================================================

    #[OA\Get(
        path: '/api/v1/service-orders/groups/available',
        summary: 'Listar grupos disponíveis para criação de OS',
        description: 'Retorna os grupos que o usuário pode selecionar ao abrir um chamado. Admins veem todos os grupos.',
        tags: ['Ordens de Serviço'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de grupos disponíveis',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'id', type: 'integer'),
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'description', type: 'string', nullable: true),
                            ]
                        )),
                        new OA\Property(property: 'total', type: 'integer'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 500, description: 'Erro interno')
        ]
    )]
    public function getAvailableGroups(Request $request)
    {
        try {
            $user = auth()->user();
            
            if ($user->is_admin) {
                $groups = Group::orderBy('name')
                    ->get(['id', 'name', 'description']);
            } else {
                $groups = $user->groups()
                    ->orderBy('name')
                    ->get(['id', 'name', 'description']);
            }

            return response()->json([
                'data' => $groups,
                'total' => $groups->count()
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erro ao buscar grupos:', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Erro ao buscar grupos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ================================================================
    // 🔥 MÉTODOS DE NOTIFICAÇÃO (Privados - sem documentação Swagger)
    // ================================================================

    /**
     * Enviar notificação de novo chamado
     */
    private function sendNewOrderNotification($order)
    {
        $order = ServiceOrder::with(['user', 'technician', 'group'])->find($order->id);
        
        if (!$order) {
            Log::error('Ordem não encontrada', ['order_id' => $order->id]);
            return;
        }
        
        $sender = User::find($order->user_id);
        
        if (!$sender) {
            Log::error('Remetente não encontrado', ['order_id' => $order->id]);
            return;
        }

        Log::info('📢 NOVO CHAMADO - Iniciando envio:', [
            'order_id' => $order->id,
            'protocol' => $order->protocol,
            'sender_id' => $sender->id,
            'sender_email' => $sender->email,
            'solicitante_id' => $order->user_id,
            'solicitante_email' => $order->user ? $order->user->email : 'NÃO CARREGADO'
        ]);

        $message = (object) [
            'message' => "Novo chamado #{$order->protocol}: {$order->title}",
            'user_id' => $order->user_id,
            'has_attachment' => !empty($order->attachment_path),
        ];

        $recipients = $this->getAllRecipientsForNewOrder($order, $sender);

        Log::info('📢 Destinatários encontrados:', [
            'total' => $recipients->count(),
            'emails' => $recipients->pluck('email')->toArray()
        ]);

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new ServiceOrderNotification($order, $message, $sender, 'new_order'));
                Log::info('✅ Notificação de novo chamado enviada para:', ['email' => $recipient->email]);
            } catch (\Exception $e) {
                Log::error('❌ Erro ao enviar notificação:', ['email' => $recipient->email, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Enviar notificação de mudança de status
     */
    private function sendStatusChangeNotification($order, $oldStatus, $sender)
    {
        $order = ServiceOrder::with(['user', 'technician', 'group'])->find($order->id);
        
        $message = (object) [
            'message' => "Status alterado de '{$oldStatus}' para '{$order->status}'",
            'user_id' => $sender->id,
            'has_attachment' => false,
        ];

        $recipients = $this->getAllRecipients($order, $sender);

        foreach ($recipients as $recipient) {
            if ($recipient->id === $sender->id) {
                continue;
            }
            
            try {
                $recipient->notify(new ServiceOrderNotification($order, $message, $sender, 'status_change'));
                Log::info('✅ Notificação de status enviada para:', ['email' => $recipient->email]);
            } catch (\Exception $e) {
                Log::error('❌ Erro ao enviar notificação:', ['email' => $recipient->email, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Enviar notificação de atribuição
     */
    private function sendAssignmentNotification($order, $sender)
    {
        if (!$order->technician) {
            return;
        }

        $order = ServiceOrder::with(['user', 'technician', 'group'])->find($order->id);

        $message = (object) [
            'message' => "Chamado atribuído para: {$order->technician->name}",
            'user_id' => $sender->id,
            'has_attachment' => false,
        ];

        $recipients = $this->getAllRecipients($order, $sender);

        foreach ($recipients as $recipient) {
            if ($recipient->id === $sender->id) {
                continue;
            }
            
            try {
                $recipient->notify(new ServiceOrderNotification($order, $message, $sender, 'assigned'));
                Log::info('✅ Notificação de atribuição enviada para:', ['email' => $recipient->email]);
            } catch (\Exception $e) {
                Log::error('❌ Erro ao enviar notificação:', ['email' => $recipient->email, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Buscar destinatários para novo chamado (inclui o solicitante)
     */
    private function getAllRecipientsForNewOrder($order, $sender)
    {
        $recipients = collect();

        Log::info('🔍 Buscando destinatários para novo chamado:', [
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'has_user' => $order->user ? 'Sim' : 'Não'
        ]);

        // 1. Solicitante
        if ($order->user) {
            $recipients->push($order->user);
            Log::info('📌 Solicitante incluso (via relacionamento):', ['email' => $order->user->email]);
        } else {
            $user = User::find($order->user_id);
            if ($user) {
                $recipients->push($user);
                Log::info('📌 Solicitante incluso (via User::find):', ['email' => $user->email]);
            } else {
                Log::warning('⚠️ Solicitante NÃO encontrado!', [
                    'order_id' => $order->id,
                    'user_id' => $order->user_id
                ]);
            }
        }

        // 2. Técnico
        if ($order->technician && $order->technician->id !== $sender->id) {
            $recipients->push($order->technician);
            Log::info('🔧 Técnico incluso:', ['email' => $order->technician->email]);
        }

        // 3. Administradores
        $admins = User::where('is_admin', true)
            ->where('id', '!=', $sender->id)
            ->get();
        foreach ($admins as $admin) {
            $recipients->push($admin);
            Log::info('👑 Admin incluso:', ['email' => $admin->email]);
        }

        // 4. Membros do grupo
        if ($order->group_id) {
            $members = User::whereHas('groups', function($q) use ($order) {
                $q->where('groups.id', $order->group_id);
            })
            ->where('id', '!=', $sender->id)
            ->get();
            foreach ($members as $member) {
                $recipients->push($member);
                Log::info('👥 Membro do grupo incluso:', ['email' => $member->email]);
            }
        }

        Log::info('📊 Total de destinatários encontrados:', ['count' => $recipients->count()]);

        return $recipients->unique('id');
    }

    /**
     * Buscar destinatários para outros eventos (exclui o remetente)
     */
    private function getAllRecipients($order, $sender)
    {
        $recipients = collect();

        // 1. Solicitante
        if ($order->user && $order->user->id !== $sender->id) {
            $recipients->push($order->user);
            Log::info('📌 Solicitante incluso:', ['email' => $order->user->email]);
        }

        // 2. Técnico
        if ($order->technician && $order->technician->id !== $sender->id) {
            $recipients->push($order->technician);
            Log::info('🔧 Técnico incluso:', ['email' => $order->technician->email]);
        }

        // 3. Administradores
        $admins = User::where('is_admin', true)
            ->where('id', '!=', $sender->id)
            ->get();
        foreach ($admins as $admin) {
            $recipients->push($admin);
            Log::info('👑 Admin incluso:', ['email' => $admin->email]);
        }

        // 4. Membros do grupo
        if ($order->group_id) {
            $members = User::whereHas('groups', function($q) use ($order) {
                $q->where('groups.id', $order->group_id);
            })
            ->where('id', '!=', $sender->id)
            ->get();
            foreach ($members as $member) {
                $recipients->push($member);
                Log::info('👥 Membro do grupo incluso:', ['email' => $member->email]);
            }
        }

        return $recipients->unique('id');
    }
}
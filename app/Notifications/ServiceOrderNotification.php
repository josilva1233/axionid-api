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
            new OA\Response(response: 200, description: 'Lista de OS recuperada com sucesso'),
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
            new OA\Response(response: 201, description: 'OS criada com sucesso'),
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

        // 🔥 ENVIAR NOTIFICAÇÃO DE NOVO CHAMADO
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

    #[OA\Put(
        path: '/api/v1/service-orders/{id}',
        summary: 'Atualizar/Atender Ordem de Serviço',
        description: 'Permite mudar o status (ex: in_progress, completed) e adicionar observações.',
        tags: ['Ordens de Serviço'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'status', type: 'string', enum: ['open', 'in_progress', 'completed', 'canceled']),
                new OA\Property(property: 'technician_notes', type: 'string', example: 'Troca de cabo realizada com sucesso.')
            ]
        ),
        responses: [
            new OA\Response(response: 200, description: 'OS atualizada'),
            new OA\Response(response: 403, description: 'Sem permissão')
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

        // 🔥 ENVIAR NOTIFICAÇÕES
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

    public function show($id)
    {
        $order = ServiceOrder::with(['user', 'group', 'technician'])->find($id);

        if (!$order) {
            return response()->json(['message' => 'Ordem de serviço não encontrada'], 404);
        }

        return response()->json($order);
    }

    #[OA\Delete(
        path: '/api/v1/service-orders/{id}',
        summary: 'Excluir Ordem de Serviço',
        description: 'Remove permanentemente uma OS. Apenas administradores podem excluir.',
        tags: ['Ordens de Serviço'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'OS excluída com sucesso'),
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
    public function getAvailableGroups(Request $request)
    {
        try {
            $user = auth()->user();
            
            if ($user->is_admin) {
                $groups = Group::where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name', 'description']);
            } else {
                $groups = $user->groups()
                    ->where('is_active', true)
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
    // 🔥 MÉTODOS DE NOTIFICAÇÃO
    // ================================================================

    /**
     * 🔥 ENVIAR NOTIFICAÇÃO DE NOVO CHAMADO
     * CORRIGIDO: Envia para o solicitante mesmo que seja o remetente
     */
    private function sendNewOrderNotification($order)
    {
        $sender = User::find($order->user_id);
        
        if (!$sender) {
            Log::error('Remetente não encontrado para notificação de novo chamado', ['order_id' => $order->id]);
            return;
        }

        $message = (object) [
            'message' => "Novo chamado #{$order->protocol}: {$order->title}",
            'user_id' => $order->user_id,
            'has_attachment' => !empty($order->attachment_path),
        ];

        $recipients = $this->getAllRecipients($order, $sender);

        Log::info('📌 Enviando notificação de NOVO CHAMADO:', [
            'order_id' => $order->id,
            'protocol' => $order->protocol,
            'sender' => $sender->email,
            'recipients' => $recipients->pluck('email')->toArray()
        ]);

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new ServiceOrderNotification($order, $message, $sender, 'new_order'));
                Log::info('✅ Notificação de novo chamado enviada para:', ['email' => $recipient->email]);
            } catch (\Exception $e) {
                Log::error('❌ Erro ao enviar notificação de novo chamado:', [
                    'email' => $recipient->email,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * 🔥 ENVIAR NOTIFICAÇÃO DE MUDANÇA DE STATUS
     */
    private function sendStatusChangeNotification($order, $oldStatus, $sender)
    {
        $message = (object) [
            'message' => "Status alterado de '{$oldStatus}' para '{$order->status}'",
            'user_id' => $sender->id,
            'has_attachment' => false,
        ];

        $recipients = $this->getAllRecipients($order, $sender);

        Log::info('🔄 Enviando notificação de STATUS:', [
            'order_id' => $order->id,
            'old_status' => $oldStatus,
            'new_status' => $order->status,
            'recipients' => $recipients->pluck('email')->toArray()
        ]);

        foreach ($recipients as $recipient) {
            if ($recipient->id === $sender->id) {
                continue;
            }
            
            try {
                $recipient->notify(new ServiceOrderNotification($order, $message, $sender, 'status_change'));
                Log::info('✅ Notificação de status enviada para:', ['email' => $recipient->email]);
            } catch (\Exception $e) {
                Log::error('❌ Erro ao enviar notificação de status:', [
                    'email' => $recipient->email,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * 🔥 ENVIAR NOTIFICAÇÃO DE ATRIBUIÇÃO
     */
    private function sendAssignmentNotification($order, $sender)
    {
        if (!$order->technician) {
            return;
        }

        $message = (object) [
            'message' => "Chamado atribuído para: {$order->technician->name}",
            'user_id' => $sender->id,
            'has_attachment' => false,
        ];

        $recipients = $this->getAllRecipients($order, $sender);

        Log::info('🔧 Enviando notificação de ATRIBUIÇÃO:', [
            'order_id' => $order->id,
            'technician' => $order->technician->name,
            'recipients' => $recipients->pluck('email')->toArray()
        ]);

        foreach ($recipients as $recipient) {
            if ($recipient->id === $sender->id) {
                continue;
            }
            
            try {
                $recipient->notify(new ServiceOrderNotification($order, $message, $sender, 'assigned'));
                Log::info('✅ Notificação de atribuição enviada para:', ['email' => $recipient->email]);
            } catch (\Exception $e) {
                Log::error('❌ Erro ao enviar notificação de atribuição:', [
                    'email' => $recipient->email,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * 🔥 BUSCAR TODOS OS DESTINATÁRIOS
     * CORRIGIDO: Inclui o solicitante mesmo que seja o remetente
     */
    private function getAllRecipients($order, $sender)
    {
        $recipients = collect();

        // 1. 🔥 SOLICITANTE (sempre incluso, mesmo que seja o remetente)
        if ($order->user) {
            $recipients->push($order->user);
            Log::info('📌 Solicitante incluso:', ['email' => $order->user->email]);
        }

        // 2. Técnico (se houver)
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
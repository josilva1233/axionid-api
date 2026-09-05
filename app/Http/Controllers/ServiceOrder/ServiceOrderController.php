<?php

namespace App\Http\Controllers\ServiceOrder;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Group;
use App\Models\ServiceOrder;
use App\Models\Category;
use App\Notifications\ServiceOrderNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'User',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'João Silva'),
        new OA\Property(property: 'email', type: 'string', example: 'joao@email.com'),
        new OA\Property(property: 'is_admin', type: 'boolean', example: false),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
    ]
)]
#[OA\Schema(
    schema: 'Group',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'TI Suporte'),
        new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Equipe de suporte técnico'),
        new OA\Property(property: 'creator_id', type: 'integer', example: 5),
    ]
)]
#[OA\Schema(
    schema: 'Category',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Rede'),
        new OA\Property(property: 'slug', type: 'string', example: 'rede'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'parent_id', type: 'integer', nullable: true),
        new OA\Property(property: 'default_group_id', type: 'integer', nullable: true),
        new OA\Property(property: 'sla_first_response_hours', type: 'integer', example: 4),
        new OA\Property(property: 'sla_resolution_hours', type: 'integer', example: 24),
        new OA\Property(property: 'default_priority', type: 'string', enum: ['low','medium','high','urgent']),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
    ]
)]
#[OA\Schema(
    schema: 'ServiceOrder',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'protocol', type: 'string', example: 'OS-20260902-A1B2'),
        new OA\Property(property: 'title', type: 'string', example: 'Problema no acesso ao sistema'),
        new OA\Property(property: 'description', type: 'string', example: 'Não consigo logar desde hoje cedo'),
        new OA\Property(property: 'status', type: 'string', enum: ['open', 'in_progress', 'completed', 'canceled'], example: 'open'),
        new OA\Property(property: 'priority', type: 'string', enum: ['low', 'medium', 'high', 'urgent'], example: 'high'),
        new OA\Property(property: 'category_id', type: 'integer', nullable: true, example: 1),
        new OA\Property(property: 'department', type: 'string', nullable: true, example: 'TI'),
        new OA\Property(property: 'deadline', type: 'string', format: 'date', nullable: true, example: '2025-12-31'),
        new OA\Property(property: 'contact_phone', type: 'string', nullable: true, example: '1199999999'),
        new OA\Property(property: 'attachment_path', type: 'string', nullable: true, example: 'attachments/file.pdf'),
        new OA\Property(property: 'user_id', type: 'integer', example: 10),
        new OA\Property(property: 'group_id', type: 'integer', nullable: true, example: 1),
        new OA\Property(property: 'technician_id', type: 'integer', nullable: true, example: 5),
        new OA\Property(property: 'user', ref: '#/components/schemas/User'),
        new OA\Property(property: 'group', ref: '#/components/schemas/Group'),
        new OA\Property(property: 'technician', ref: '#/components/schemas/User'),
        new OA\Property(property: 'category', ref: '#/components/schemas/Category'),
        new OA\Property(property: 'sla_first_response_due_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'sla_resolution_due_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'first_response_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'resolved_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Tag(name: 'Ordens de Serviço', description: 'Gerenciamento de chamados e ordens de serviço')]
class ServiceOrderController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $perPage = $request->input('per_page', 10);

        $query = ServiceOrder::with(['user', 'group', 'technician', 'category'])->latest();

        if ($request->filled('protocol')) {
            $query->where('protocol', 'like', '%' . $request->protocol . '%');
        }

        if ($request->filled('title')) {
            $query->where('title', 'like', '%' . $request->title . '%');
        }

        if ($request->filled('applicant') || $request->filled('solicitante')) {
            $search = $request->input('applicant', $request->input('solicitante'));
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%');
            });
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($user->is_admin) {
            $orders = $query->paginate($perPage);
        } else {
            $groupIds = $user->groups->pluck('id');
            $orders = $query->where(function ($q) use ($user, $groupIds) {
                $q->where('user_id', $user->id)
                    ->orWhereIn('group_id', $groupIds);
            })->paginate($perPage);
        }

        return response()->json($orders);
    }

    #[OA\Post(
        path: '/api/v1/service-orders',
        summary: 'Abrir nova Ordem de Serviço',
        description: 'Cria uma OS para o usuário autenticado ou para outro (se tiver permissão).',
        tags: ['Ordens de Serviço'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['title', 'description', 'category_id'],
                    properties: [
                        new OA\Property(property: 'title', type: 'string', example: 'Problema no acesso ao sistema'),
                        new OA\Property(property: 'description', type: 'string', example: 'Não consigo logar desde hoje cedo'),
                        new OA\Property(property: 'category_id', type: 'integer', example: 1),
                        new OA\Property(property: 'priority', type: 'string', enum: ['low', 'medium', 'high', 'urgent']),
                        new OA\Property(property: 'group_id', type: 'integer', nullable: true, example: 1),
                        new OA\Property(property: 'user_id', type: 'integer', nullable: true, description: 'ID do solicitante (apenas admin/técnico com permissão)'),
                        new OA\Property(property: 'attachment', type: 'string', format: 'binary', description: 'Arquivo PDF ou Imagem'),
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
            'priority' => 'sometimes|in:low,medium,high,urgent',
            'group_id' => 'nullable|exists:groups,id',
            'category_id' => 'required|exists:categories,id',
            'attachment' => 'nullable|file|mimes:pdf,jpg,png|max:5120',
            'user_id' => 'nullable|exists:users,id|integer',
        ]);

        // 🔥 Definir o solicitante (padrão: usuário autenticado)
        $userId = auth()->id();

        if ($request->filled('user_id') && $request->user_id) {
            // Verifica permissão: admin OU permissão específica
            if (auth()->user()->is_admin || auth()->user()->can('orders.create_for_others')) {
                $targetUser = User::find($request->user_id);
                if ($targetUser && $targetUser->is_active) {
                    $userId = $targetUser->id;
                } else {
                    return response()->json([
                        'message' => 'Usuário inválido ou inativo.'
                    ], 422);
                }
            } else {
                // Sem permissão: ignora e usa o próprio ID
                // Pode retornar erro ou simplesmente ignorar
                // Vamos ignorar e usar o próprio usuário
            }
        }

        // Processar anexo
        $path = $request->hasFile('attachment')
            ? $request->file('attachment')->store('attachments', 'public')
            : null;

        $category = Category::findOrFail($request->category_id);
        $groupId = $request->group_id ?? $category->default_group_id;
        [$firstResponseDue, $resolutionDue] = ServiceOrder::calculateSlaDates($category->id);

        $os = ServiceOrder::create([
            'protocol' => 'OS-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4)),
            'user_id' => $userId,
            'group_id' => $groupId,
            'category_id' => $category->id,
            'title' => $request->title,
            'description' => $request->description,
            'priority' => $request->priority ?? $category->default_priority,
            'attachment_path' => $path,
            'sla_first_response_due_at' => $firstResponseDue,
            'sla_resolution_due_at' => $resolutionDue,
        ]);

        $os->load(['user', 'technician', 'group', 'category']);

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

    public function show($id)
    {
        $order = ServiceOrder::with(['user', 'group', 'technician', 'category'])->find($id);

        if (!$order) {
            return response()->json(['message' => 'Ordem de serviço não encontrada'], 404);
        }

        return response()->json($order);
    }

    public function update(Request $request, $id)
    {
        $os = ServiceOrder::with(['user', 'technician', 'group'])->findOrFail($id);

        if (!auth()->user()->is_admin && $os->user_id !== auth()->id()) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        $oldStatus = $os->status;
        $oldTechnicianId = $os->technician_id;
        $user = auth()->user();

        if ($request->has('category_id')) {
            $request->validate(['category_id' => 'exists:categories,id']);
            $os->category_id = $request->category_id;
        }

        if ($request->has('status')) {
            $os->status = $request->status;
        }

        if ($request->status === 'in_progress' && !$os->technician_id) {
            $os->technician_id = $user->id;
        }

        if ($request->has('technician_id')) {
            $os->technician_id = $request->technician_id;
        }

        if ($request->status === 'in_progress' && !$os->first_response_at) {
            $os->first_response_at = now();
        }

        if ($request->status === 'completed' && !$os->resolved_at) {
            $os->resolved_at = now();
        }

        $os->save();
        $os->load(['user', 'technician', 'group', 'category']);

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

    public function cancel($id)
    {
        $os = ServiceOrder::with(['user', 'technician', 'group', 'category'])->findOrFail($id);
        $user = auth()->user();

        if (!$user->is_admin && $os->user_id !== $user->id) {
            return response()->json([
                'message' => 'Você não tem permissão para cancelar este chamado.'
            ], 403);
        }

        if (!in_array($os->status, ['open', 'in_progress'])) {
            return response()->json([
                'message' => 'Apenas chamados abertos ou em andamento podem ser cancelados.'
            ], 422);
        }

        $os->status = ServiceOrder::STATUS_CANCELLED;
        $os->save();
        $os->load(['user', 'technician', 'group', 'category']);

        return response()->json([
            'message' => 'Ordem de serviço cancelada com sucesso.',
            'data' => $os
        ]);
    }

    public function getAvailableGroups(Request $request)
    {
        try {
            $user = auth()->user();

            if ($user->is_admin) {
                $groups = Group::orderBy('name')->get(['id', 'name', 'description']);
            } else {
                $groups = $user->groups()->orderBy('name')->get(['id', 'name', 'description']);
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
    // 🔥 MÉTODOS DE NOTIFICAÇÃO (Privados)
    // ================================================================

    private function sendNewOrderNotification($order)
    {
        $order = ServiceOrder::with(['user', 'technician', 'group', 'category'])->find($order->id);

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

    private function sendStatusChangeNotification($order, $oldStatus, $sender)
    {
        $order = ServiceOrder::with(['user', 'technician', 'group', 'category'])->find($order->id);

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

    private function sendAssignmentNotification($order, $sender)
    {
        if (!$order->technician) {
            return;
        }

        $order = ServiceOrder::with(['user', 'technician', 'group', 'category'])->find($order->id);

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

    private function getAllRecipientsForNewOrder($order, $sender)
    {
        $recipients = collect();

        Log::info('🔍 Buscando destinatários para novo chamado:', [
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'has_user' => $order->user ? 'Sim' : 'Não'
        ]);

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

        if ($order->technician && $order->technician->id !== $sender->id) {
            $recipients->push($order->technician);
            Log::info('🔧 Técnico incluso:', ['email' => $order->technician->email]);
        }

        $admins = User::where('is_admin', true)
            ->where('id', '!=', $sender->id)
            ->get();
        foreach ($admins as $admin) {
            $recipients->push($admin);
            Log::info('👑 Admin incluso:', ['email' => $admin->email]);
        }

        if ($order->group_id) {
            $members = User::whereHas('groups', function ($q) use ($order) {
                $q->where('groups.id', $order->group_id);
            })->where('id', '!=', $sender->id)->get();
            foreach ($members as $member) {
                $recipients->push($member);
                Log::info('👥 Membro do grupo incluso:', ['email' => $member->email]);
            }
        }

        Log::info('📊 Total de destinatários encontrados:', ['count' => $recipients->count()]);

        return $recipients->unique('id');
    }

    private function getAllRecipients($order, $sender)
    {
        $recipients = collect();

        if ($order->user && $order->user->id !== $sender->id) {
            $recipients->push($order->user);
            Log::info('📌 Solicitante incluso:', ['email' => $order->user->email]);
        }

        if ($order->technician && $order->technician->id !== $sender->id) {
            $recipients->push($order->technician);
            Log::info('🔧 Técnico incluso:', ['email' => $order->technician->email]);
        }

        $admins = User::where('is_admin', true)
            ->where('id', '!=', $sender->id)
            ->get();
        foreach ($admins as $admin) {
            $recipients->push($admin);
            Log::info('👑 Admin incluso:', ['email' => $admin->email]);
        }

        if ($order->group_id) {
            $members = User::whereHas('groups', function ($q) use ($order) {
                $q->where('groups.id', $order->group_id);
            })->where('id', '!=', $sender->id)->get();
            foreach ($members as $member) {
                $recipients->push($member);
                Log::info('👥 Membro do grupo incluso:', ['email' => $member->email]);
            }
        }

        return $recipients->unique('id');
    }
}
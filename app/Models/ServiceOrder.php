<?php

namespace App\Http\Controllers\ServiceOrder;

use App\Http\Controllers\Controller;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderMessage;
use App\Models\User;
use App\Notifications\NewMessageNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class ServiceOrderMessageController extends Controller
{
    #[OA\Get(
        path: '/api/v1/service-orders/{id}/messages',
        summary: 'Listar mensagens da OS',
        description: 'Retorna todas as mensagens de uma ordem de serviço com paginação',
        tags: ['Mensagens OS'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Mensagens listadas com sucesso'),
            new OA\Response(response: 403, description: 'Sem permissão'),
            new OA\Response(response: 404, description: 'OS não encontrada')
        ]
    )]
    public function index(Request $request, $id)
    {
        $order = ServiceOrder::findOrFail($id);
        $user = auth()->user();

        // Verificar permissão
        if (!$this->canAccessOrder($user, $order)) {
            return response()->json([
                'message' => 'Sem permissão para visualizar estas mensagens'
            ], 403);
        }

        $perPage = $request->input('per_page', 20);
        
        $messages = ServiceOrderMessage::with(['user'])
            ->where('service_order_id', $order->id)
            ->orderBy('created_at', 'asc')
            ->paginate($perPage);

        // Marcar mensagens como lidas
        $this->markMessagesAsRead($order->id, $user->id);

        return response()->json([
            'service_order' => [
                'id' => $order->id,
                'protocol' => $order->protocol,
                'title' => $order->title,
                'status' => $order->status,
                'priority' => $order->priority,
            ],
            'messages' => $messages->through(function ($message) {
                return [
                    'id' => $message->id,
                    'user_id' => $message->user_id,
                    'user_name' => $message->user_name,
                    'user_avatar' => $message->user_avatar,
                    'message' => $message->message,
                    'is_system_message' => $message->is_system_message,
                    'has_attachment' => $message->hasAttachment(),
                    'attachment_url' => $message->attachment_url,
                    'attachment_info' => $message->getAttachmentInfo(),
                    'is_read' => $message->is_read,
                    'created_at' => $message->formatted_date,
                    'created_at_human' => $message->created_at->diffForHumans(),
                ];
            }),
            'meta' => [
                'total' => $messages->total(),
                'per_page' => $messages->perPage(),
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'unread_count' => ServiceOrderMessage::where('service_order_id', $order->id)
                    ->whereNull('read_at')
                    ->where('user_id', '!=', $user->id)
                    ->count(),
            ]
        ]);
    }

    #[OA\Post(
        path: '/api/v1/service-orders/{id}/messages',
        summary: 'Adicionar mensagem na OS',
        description: 'Adiciona uma nova mensagem com ou sem anexo',
        tags: ['Mensagens OS'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['message'],
                    properties: [
                        new OA\Property(property: 'message', type: 'string', maxLength: 5000),
                        new OA\Property(property: 'attachment', type: 'string', format: 'binary')
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Mensagem adicionada com sucesso'),
            new OA\Response(response: 403, description: 'Sem permissão'),
            new OA\Response(response: 422, description: 'Erro de validação')
        ]
    )]
    public function store(Request $request, $id)
    {
        $order = ServiceOrder::findOrFail($id);
        $user = auth()->user();

        if (!$this->canAccessOrder($user, $order)) {
            return response()->json([
                'message' => 'Sem permissão para enviar mensagens'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:5000',
            'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,txt|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Erro de validação',
                'errors' => $validator->errors()
            ], 422);
        }

        // Criar mensagem
        $message = ServiceOrderMessage::createUserMessage(
            $order->id,
            $user->id,
            $request->message,
            $request->file('attachment')
        );

        // Carregar o usuário
        $message->load('user');

        // Enviar notificações
        $this->sendMessageNotifications($order, $message, $user);

        // Adicionar mensagem de sistema se for mudança de status
        if ($request->has('status_change')) {
            $this->addStatusChangeMessage($order, $request->status_change, $user);
        }

        return response()->json([
            'message' => 'Mensagem enviada com sucesso!',
            'data' => [
                'id' => $message->id,
                'user_id' => $message->user_id,
                'user_name' => $message->user_name,
                'message' => $message->message,
                'has_attachment' => $message->hasAttachment(),
                'attachment_url' => $message->attachment_url,
                'created_at' => $message->formatted_date,
                'created_at_human' => $message->created_at->diffForHumans(),
            ]
        ], 201);
    }

    #[OA\Put(
        path: '/api/v1/service-orders/messages/{id}/read',
        summary: 'Marcar mensagem como lida',
        tags: ['Mensagens OS'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Mensagem marcada como lida'),
            new OA\Response(response: 404, description: 'Mensagem não encontrada')
        ]
    )]
    public function markAsRead($id)
    {
        $message = ServiceOrderMessage::findOrFail($id);
        
        // Verificar se o usuário tem permissão
        if (!$this->canAccessOrder(auth()->user(), $message->serviceOrder)) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $message->markAsRead();

        return response()->json(['message' => 'Mensagem marcada como lida']);
    }

    #[OA\Delete(
        path: '/api/v1/service-orders/messages/{id}',
        summary: 'Excluir mensagem',
        description: 'Apenas o autor ou administrador podem excluir',
        tags: ['Mensagens OS'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Mensagem excluída'),
            new OA\Response(response: 403, description: 'Sem permissão')
        ]
    )]
    public function destroy($id)
    {
        $message = ServiceOrderMessage::findOrFail($id);
        $user = auth()->user();

        // Apenas autor ou admin
        if ($message->user_id !== $user->id && !$user->is_admin) {
            return response()->json([
                'message' => 'Sem permissão para excluir esta mensagem'
            ], 403);
        }

        // Remover anexo
        if ($message->hasAttachment()) {
            Storage::disk('public')->delete($message->attachment_path);
        }

        $message->delete();

        return response()->json(['message' => 'Mensagem excluída com sucesso']);
    }

    // ========== MÉTODOS PRIVADOS ==========

    private function canAccessOrder($user, $order)
    {
        if ($user->is_admin) return true;
        if ($order->user_id === $user->id) return true;
        if ($order->technician_id === $user->id) return true;
        
        if ($order->group_id) {
            $groupIds = $user->groups->pluck('id')->toArray();
            if (in_array($order->group_id, $groupIds)) {
                return true;
            }
        }
        
        return false;
    }

    private function sendMessageNotifications($order, $message, $sender)
    {
        $recipients = collect();

        // Solicitante
        if ($order->user_id !== $sender->id) {
            $recipients->push($order->user);
        }

        // Técnico
        if ($order->technician_id && $order->technician_id !== $sender->id) {
            $recipients->push($order->technician);
        }

        // Admins
        $admins = User::where('is_admin', true)
            ->where('id', '!=', $sender->id)
            ->get();
        $recipients = $recipients->merge($admins);

        // Membros do grupo
        if ($order->group_id) {
            $members = User::whereHas('groups', function($q) use ($order) {
                $q->where('groups.id', $order->group_id);
            })->where('id', '!=', $sender->id)->get();
            $recipients = $recipients->merge($members);
        }

        // Remover duplicados
        $recipients = $recipients->unique('id');

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new NewMessageNotification($order, $message, $sender));
            } catch (\Exception $e) {
                \Log::error('Erro ao enviar notificação: ' . $e->getMessage());
            }
        }
    }

    private function markMessagesAsRead($orderId, $userId)
    {
        ServiceOrderMessage::where('service_order_id', $orderId)
            ->where('user_id', '!=', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    private function addStatusChangeMessage($order, $status, $user)
    {
        $statusMessages = [
            'open' => '📌 OS aberta por ' . $user->name,
            'in_progress' => '🔄 OS em andamento - ' . $user->name . ' assumiu o atendimento',
            'completed' => '✅ OS concluída por ' . $user->name,
            'canceled' => '❌ OS cancelada por ' . $user->name,
        ];

        if (isset($statusMessages[$status])) {
            ServiceOrderMessage::createSystemMessage(
                $order->id,
                $statusMessages[$status],
                $user->id
            );
        }
    }
}
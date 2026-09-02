<?php

namespace App\Http\Controllers\ServiceOrder;

use App\Http\Controllers\Controller;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderMessage;
use App\Models\User;
use App\Notifications\ServiceOrderNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Mensagens de OS', description: 'Gerenciamento de mensagens em ordens de serviço')]
class ServiceOrderMessageController extends Controller
{
    #[OA\Get(
        path: '/api/v1/service-orders/{serviceOrderId}/messages',
        summary: 'Listar mensagens da OS',
        description: 'Retorna todas as mensagens de uma ordem de serviço, com paginação e informações de leitura.',
        tags: ['Mensagens de OS'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(
                name: 'serviceOrderId',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                description: 'ID da ordem de serviço'
            ),
            new OA\Parameter(
                name: 'page',
                in: 'query',
                schema: new OA\Schema(type: 'integer', default: 1)
            ),
            new OA\Parameter(
                name: 'per_page',
                in: 'query',
                schema: new OA\Schema(type: 'integer', default: 20)
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Mensagens recuperadas com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'service_order', type: 'object'),
                        new OA\Property(property: 'messages', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'id', type: 'integer'),
                                new OA\Property(property: 'user_id', type: 'integer'),
                                new OA\Property(property: 'user', type: 'object'),
                                new OA\Property(property: 'message', type: 'string'),
                                new OA\Property(property: 'is_system_message', type: 'boolean'),
                                new OA\Property(property: 'has_attachment', type: 'boolean'),
                                new OA\Property(property: 'attachment_url', type: 'string', nullable: true),
                                new OA\Property(property: 'created_at_formatted', type: 'string'),
                                new OA\Property(property: 'created_at_human', type: 'string'),
                            ]
                        )),
                        new OA\Property(property: 'meta', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 403, description: 'Sem permissão')
        ]
    )]
    public function index(Request $request, $serviceOrderId)
    {
        try {
            $order = ServiceOrder::with(['user', 'technician', 'group'])->findOrFail($serviceOrderId);
            $user = auth()->user();

            if (!$this->canAccessOrder($user, $order)) {
                return response()->json([
                    'message' => 'Sem permissão para visualizar estas mensagens'
                ], 403);
            }

            $perPage = $request->input('per_page', 20);
            
            $messages = ServiceOrderMessage::with(['user'])
                ->where('service_order_id', $order->id)
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            ServiceOrderMessage::where('service_order_id', $order->id)
                ->where('user_id', '!=', $user->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            $unreadCount = ServiceOrderMessage::where('service_order_id', $order->id)
                ->where('user_id', '!=', $user->id)
                ->whereNull('read_at')
                ->count();

            return response()->json([
                'service_order' => [
                    'id' => $order->id,
                    'protocol' => $order->protocol,
                    'title' => $order->title,
                    'status' => $order->status,
                    'priority' => $order->priority,
                    'user_id' => $order->user_id,
                    'technician_id' => $order->technician_id,
                ],
                'messages' => $messages->through(function ($message) {
                    return $this->formatMessage($message);
                }),
                'meta' => [
                    'total' => $messages->total(),
                    'per_page' => $messages->perPage(),
                    'current_page' => $messages->currentPage(),
                    'last_page' => $messages->lastPage(),
                    'unread_count' => $unreadCount,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao listar mensagens:', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Erro ao carregar mensagens',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    #[OA\Post(
        path: '/api/v1/service-orders/{serviceOrderId}/messages',
        summary: 'Adicionar mensagem na OS',
        description: 'Envia uma nova mensagem para uma ordem de serviço, com suporte a anexo.',
        tags: ['Mensagens de OS'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(
                name: 'serviceOrderId',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                description: 'ID da ordem de serviço'
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['message'],
                    properties: [
                        new OA\Property(property: 'message', type: 'string', maxLength: 5000, example: 'Problema resolvido!'),
                        new OA\Property(property: 'attachment', type: 'string', format: 'binary', description: 'Arquivo anexado (máx 10MB)')
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Mensagem enviada com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'data', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 403, description: 'Sem permissão'),
            new OA\Response(response: 422, description: 'Erro de validação ou chamado fechado')
        ]
    )]
    public function store(Request $request, $serviceOrderId)
    {
        try {
            $order = ServiceOrder::findOrFail($serviceOrderId);
            $user = auth()->user();

            if ($order->status === 'closed') {
                return response()->json([
                    'message' => 'Não é possível enviar mensagens em um chamado fechado'
                ], 422);
            }

            if (!$this->canAccessOrder($user, $order)) {
                return response()->json([
                    'message' => 'Sem permissão para enviar mensagens nesta OS'
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

            $path = null;
            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');
                $originalName = $file->getClientOriginalName();
                $path = $file->storeAs(
                    'message_attachments/' . $order->id,
                    time() . '_' . $originalName,
                    'public'
                );
            }

            $message = ServiceOrderMessage::create([
                'service_order_id' => $order->id,
                'user_id' => $user->id,
                'message' => $request->message,
                'attachment_path' => $path,
                'is_system_message' => false,
            ]);

            $message->load('user');

            try {
                $this->sendNotifications($order, $message, $user);
            } catch (\Exception $e) {
                Log::error('Erro ao enviar notificações:', [
                    'order_id' => $order->id,
                    'message_id' => $message->id,
                    'error' => $e->getMessage()
                ]);
            }

            return response()->json([
                'message' => 'Mensagem enviada com sucesso!',
                'data' => $this->formatMessage($message)
            ], 201);
        } catch (\Exception $e) {
            Log::error('Erro ao enviar mensagem:', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Erro ao enviar mensagem',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    #[OA\Put(
        path: '/api/v1/service-orders/{serviceOrderId}/messages/{messageId}',
        summary: 'Atualizar mensagem',
        description: 'Edita o conteúdo de uma mensagem existente. Apenas o autor ou um admin podem editar.',
        tags: ['Mensagens de OS'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(
                name: 'serviceOrderId',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                description: 'ID da ordem de serviço'
            ),
            new OA\Parameter(
                name: 'messageId',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                description: 'ID da mensagem'
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['message'],
                properties: [
                    new OA\Property(property: 'message', type: 'string', maxLength: 5000, example: 'Corrigindo a mensagem anterior...')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Mensagem atualizada com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'data', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 403, description: 'Sem permissão'),
            new OA\Response(response: 422, description: 'Erro de validação')
        ]
    )]
    public function update(Request $request, $serviceOrderId, $messageId)
    {
        try {
            $message = ServiceOrderMessage::where('service_order_id', $serviceOrderId)
                ->findOrFail($messageId);
            
            $user = auth()->user();

            if ($message->user_id !== $user->id && !$user->is_admin) {
                return response()->json([
                    'message' => 'Sem permissão para editar esta mensagem'
                ], 403);
            }

            if ($message->is_system_message) {
                return response()->json([
                    'message' => 'Não é possível editar mensagens do sistema'
                ], 422);
            }

            $validator = Validator::make($request->all(), [
                'message' => 'required|string|max:5000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Erro de validação',
                    'errors' => $validator->errors()
                ], 422);
            }

            $message->update([
                'message' => $request->message
            ]);

            $message->load('user');

            return response()->json([
                'message' => 'Mensagem atualizada com sucesso!',
                'data' => $this->formatMessage($message)
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar mensagem:', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Erro ao atualizar mensagem',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    #[OA\Delete(
        path: '/api/v1/service-orders/{serviceOrderId}/messages/{messageId}',
        summary: 'Excluir mensagem',
        description: 'Remove uma mensagem e seu anexo, se houver. Apenas o autor ou admin podem excluir.',
        tags: ['Mensagens de OS'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(
                name: 'serviceOrderId',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                description: 'ID da ordem de serviço'
            ),
            new OA\Parameter(
                name: 'messageId',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                description: 'ID da mensagem'
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Mensagem excluída com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Mensagem excluída com sucesso')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 403, description: 'Sem permissão'),
            new OA\Response(response: 404, description: 'Mensagem não encontrada')
        ]
    )]
    public function destroy($serviceOrderId, $messageId)
    {
        try {
            $message = ServiceOrderMessage::where('service_order_id', $serviceOrderId)
                ->findOrFail($messageId);
            
            $user = auth()->user();

            if ($message->user_id !== $user->id && !$user->is_admin) {
                return response()->json([
                    'message' => 'Sem permissão para excluir esta mensagem'
                ], 403);
            }

            if ($message->attachment_path && Storage::disk('public')->exists($message->attachment_path)) {
                Storage::disk('public')->delete($message->attachment_path);
            }

            $message->delete();

            return response()->json([
                'message' => 'Mensagem excluída com sucesso'
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao excluir mensagem:', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Erro ao excluir mensagem',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ================================================================
    // 🔥 MÉTODOS PRIVADOS
    // ================================================================

    private function canAccessOrder($user, $order)
    {
        if ($user->is_admin) {
            return true;
        }

        if ($order->user_id === $user->id) {
            return true;
        }

        if ($order->technician_id === $user->id) {
            return true;
        }

        if ($order->group_id) {
            $groupIds = $user->groups->pluck('id')->toArray();
            if (in_array($order->group_id, $groupIds)) {
                return true;
            }
        }

        return false;
    }

    private function sendNotifications($order, $message, $sender)
    {
        $recipients = collect();

        if ($order->user_id !== $sender->id && $order->user) {
            $recipients->push($order->user);
        }

        if ($order->technician_id && $order->technician_id !== $sender->id && $order->technician) {
            $recipients->push($order->technician);
        }

        $admins = User::where('is_admin', true)
            ->where('id', '!=', $sender->id)
            ->get();
        $recipients = $recipients->merge($admins);

        if ($order->group_id) {
            $members = User::whereHas('groups', function($query) use ($order) {
                $query->where('groups.id', $order->group_id);
            })
            ->where('id', '!=', $sender->id)
            ->get();
            $recipients = $recipients->merge($members);
        }

        $recipients = $recipients->unique('id');

        Log::info('Enviando notificações:', [
            'order_id' => $order->id,
            'message_id' => $message->id,
            'recipients_count' => $recipients->count()
        ]);

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new ServiceOrderNotification($order, $message, $sender));
                Log::info('Notificação enviada para:', [
                    'user_id' => $recipient->id,
                    'email' => $recipient->email
                ]);
            } catch (\Exception $e) {
                Log::error('Erro ao enviar notificação para o usuário ' . $recipient->id, [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
    }

    private function formatMessage($message)
    {
        $user = $message->user;
        
        return [
            'id' => $message->id,
            'user_id' => $message->user_id,
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar ? Storage::url($user->avatar) : null,
            ] : null,
            'message' => $message->message,
            'is_system_message' => (bool) $message->is_system_message,
            'has_attachment' => $message->hasAttachment(),
            'attachment_path' => $message->attachment_path,
            'attachment_url' => $message->attachment_url,
            'attachment_info' => $message->getAttachmentInfo(),
            'is_read' => !is_null($message->read_at),
            'read_at' => $message->read_at,
            'created_at' => $message->created_at->toISOString(),
            'created_at_formatted' => $message->created_at->format('d/m/Y H:i'),
            'created_at_human' => $message->created_at->diffForHumans(),
            'updated_at' => $message->updated_at->format('d/m/Y H:i'),
        ];
    }
}
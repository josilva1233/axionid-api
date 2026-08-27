<?php

namespace App\Http\Controllers\ServiceOrder;

use App\Http\Controllers\Controller;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class ServiceOrderMessageController extends Controller
{
    /**
     * Listar todas as mensagens de uma OS específica.
     * Apenas usuários com acesso à OS podem ver.
     */
    #[OA\Get(
        path: '/api/v1/service-orders/{serviceOrderId}/messages',
        summary: 'Listar mensagens de uma OS',
        tags: ['Ordens de Serviço - Mensagens'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'serviceOrderId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Mensagens recuperadas'),
            new OA\Response(response: 403, description: 'Sem permissão')
        ]
    )]
    public function index($serviceOrderId)
    {
        $order = ServiceOrder::findOrFail($serviceOrderId);
        
        // Verifica se o usuário tem acesso à OS (mesma lógica do index do ServiceOrderController)
        $user = auth()->user();
        $hasAccess = $user->is_admin || 
                     $order->user_id == $user->id || 
                     ($order->group_id && $user->groups->contains($order->group_id));
        
        if (!$hasAccess) {
            return response()->json(['message' => 'Você não tem permissão para ver mensagens desta OS.'], 403);
        }

        $messages = $order->messages()->with('user')->paginate(15);

        return response()->json($messages);
    }

    /**
     * Criar uma nova mensagem na OS.
     */
    #[OA\Post(
        path: '/api/v1/service-orders/{serviceOrderId}/messages',
        summary: 'Adicionar mensagem a uma OS',
        tags: ['Ordens de Serviço - Mensagens'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'serviceOrderId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Estou analisando o problema.'),
                    new OA\Property(property: 'attachment', type: 'string', format: 'binary', description: 'Arquivo opcional')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Mensagem criada'),
            new OA\Response(response: 403, description: 'Sem permissão')
        ]
    )]
    public function store(Request $request, $serviceOrderId)
    {
        $order = ServiceOrder::findOrFail($serviceOrderId);
        
        // Mesma verificação de acesso (pode enviar mensagem quem tem acesso)
        $user = auth()->user();
        $hasAccess = $user->is_admin || 
                     $order->user_id == $user->id || 
                     ($order->group_id && $user->groups->contains($order->group_id));
        
        if (!$hasAccess) {
            return response()->json(['message' => 'Você não tem permissão para enviar mensagens nesta OS.'], 403);
        }

        $validated = $request->validate([
            'message' => 'required|string|max:5000',
            'attachment' => 'nullable|file|mimes:pdf,jpg,png,doc,docx|max:5120',
        ]);

        $path = null;
        if ($request->hasFile('attachment')) {
            $path = $request->file('attachment')->store('message_attachments', 'public');
        }

        $message = ServiceOrderMessage::create([
            'service_order_id' => $order->id,
            'user_id' => $user->id,
            'message' => $validated['message'],
            'attachment_path' => $path,
        ]);

        // (Opcional) Registrar no log de auditoria
        // \App\Models\AuditLog::log('message_created', 'service_order_messages', $message->id, ['order_id' => $order->id]);

        // (Opcional) Disparar evento para notificações em tempo real
        // event(new NewServiceOrderMessage($message));

        return response()->json($message->load('user'), 201);
    }

    /**
     * Atualizar uma mensagem (apenas o próprio autor ou admin).
     */
    #[OA\Put(
        path: '/api/v1/service-orders/{serviceOrderId}/messages/{messageId}',
        summary: 'Atualizar mensagem',
        tags: ['Ordens de Serviço - Mensagens'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'serviceOrderId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'messageId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Mensagem corrigida.')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Mensagem atualizada'),
            new OA\Response(response: 403, description: 'Sem permissão')
        ]
    )]
    public function update(Request $request, $serviceOrderId, $messageId)
    {
        $message = ServiceOrderMessage::where('service_order_id', $serviceOrderId)
                                      ->findOrFail($messageId);
        
        $user = auth()->user();
        // Só permite editar se for o autor ou admin
        if (!$user->is_admin && $message->user_id !== $user->id) {
            return response()->json(['message' => 'Você não pode editar esta mensagem.'], 403);
        }

        $validated = $request->validate([
            'message' => 'required|string|max:5000',
        ]);

        $message->message = $validated['message'];
        $message->save();

        return response()->json($message->load('user'));
    }

    /**
     * Excluir uma mensagem (apenas admin ou autor).
     */
    #[OA\Delete(
        path: '/api/v1/service-orders/{serviceOrderId}/messages/{messageId}',
        summary: 'Excluir mensagem',
        tags: ['Ordens de Serviço - Mensagens'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'serviceOrderId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'messageId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Mensagem excluída'),
            new OA\Response(response: 403, description: 'Sem permissão')
        ]
    )]
    public function destroy($serviceOrderId, $messageId)
    {
        $message = ServiceOrderMessage::where('service_order_id', $serviceOrderId)
                                      ->findOrFail($messageId);
        
        $user = auth()->user();
        if (!$user->is_admin && $message->user_id !== $user->id) {
            return response()->json(['message' => 'Você não pode excluir esta mensagem.'], 403);
        }

        // Remove anexo se existir
        if ($message->attachment_path) {
            Storage::disk('public')->delete($message->attachment_path);
        }

        $message->delete();

        return response()->json(['message' => 'Mensagem removida com sucesso.']);
    }
}
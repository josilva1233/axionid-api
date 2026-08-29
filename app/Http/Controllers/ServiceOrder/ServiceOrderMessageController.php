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
use OpenApi\Attributes as OA;

class ServiceOrderMessageController extends Controller
{
    /**
     * Listar mensagens da OS
     */
    public function index(Request $request, $serviceOrderId)
    {
        $order = ServiceOrder::with(['user', 'technician', 'group'])->findOrFail($serviceOrderId);
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

        // Marcar mensagens como lidas (exceto as do próprio usuário)
        ServiceOrderMessage::where('service_order_id', $order->id)
            ->where('user_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        // Contar não lidas
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
    }

    /**
     * Adicionar mensagem na OS
     */
    public function store(Request $request, $serviceOrderId)
    {
        $order = ServiceOrder::findOrFail($serviceOrderId);
        $user = auth()->user();

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

        // Salvar anexo
        $path = null;
        if ($request->hasFile('attachment')) {
            $path = $request->file('attachment')->store('message_attachments', 'public');
        }

        // Criar mensagem
        $message = ServiceOrderMessage::create([
            'service_order_id' => $order->id,
            'user_id' => $user->id,
            'message' => $request->message,
            'attachment_path' => $path,
            'is_system_message' => false,
        ]);

        $message->load('user');

        // Enviar notificações
        $this->sendNotifications($order, $message, $user);

        return response()->json([
            'message' => 'Mensagem enviada com sucesso!',
            'data' => $this->formatMessage($message)
        ], 201);
    }

    /**
     * Atualizar mensagem
     */
    public function update(Request $request, $serviceOrderId, $messageId)
    {
        $message = ServiceOrderMessage::where('service_order_id', $serviceOrderId)
            ->findOrFail($messageId);
        
        $user = auth()->user();

        // Apenas o autor pode editar
        if ($message->user_id !== $user->id && !$user->is_admin) {
            return response()->json([
                'message' => 'Sem permissão para editar esta mensagem'
            ], 403);
        }

        // Não permite editar mensagens do sistema
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

        return response()->json([
            'message' => 'Mensagem atualizada com sucesso!',
            'data' => $this->formatMessage($message)
        ]);
    }

    /**
     * Marcar mensagem como lida
     */
    public function markAsRead($serviceOrderId, $messageId)
    {
        $message = ServiceOrderMessage::where('service_order_id', $serviceOrderId)
            ->findOrFail($messageId);
        
        $user = auth()->user();

        // Verificar permissão
        $order = $message->serviceOrder;
        if (!$this->canAccessOrder($user, $order)) {
            return response()->json([
                'message' => 'Sem permissão'
            ], 403);
        }

        $message->markAsRead();

        return response()->json([
            'message' => 'Mensagem marcada como lida'
        ]);
    }

    /**
     * Excluir mensagem
     */
    public function destroy($serviceOrderId, $messageId)
    {
        $message = ServiceOrderMessage::where('service_order_id', $serviceOrderId)
            ->findOrFail($messageId);
        
        $user = auth()->user();

        // Apenas autor ou admin
        if ($message->user_id !== $user->id && !$user->is_admin) {
            return response()->json([
                'message' => 'Sem permissão para excluir esta mensagem'
            ], 403);
        }

        // Remover anexo
        if ($message->attachment_path && Storage::disk('public')->exists($message->attachment_path)) {
            Storage::disk('public')->delete($message->attachment_path);
        }

        $message->delete();

        return response()->json([
            'message' => 'Mensagem excluída com sucesso'
        ]);
    }

    // ========== MÉTODOS PRIVADOS ==========

    private function canAccessOrder($user, $order)
    {
        // Admin tem acesso total
        if ($user->is_admin) {
            return true;
        }

        // Solicitante
        if ($order->user_id === $user->id) {
            return true;
        }

        // Técnico
        if ($order->technician_id === $user->id) {
            return true;
        }

        // Membro do grupo
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

        // Solicitante (se não for o remetente)
        if ($order->user_id !== $sender->id && $order->user) {
            $recipients->push($order->user);
        }

        // Técnico (se houver e não for o remetente)
        if ($order->technician_id && $order->technician_id !== $sender->id && $order->technician) {
            $recipients->push($order->technician);
        }

        // Administradores
        $admins = User::where('is_admin', true)
            ->where('id', '!=', $sender->id)
            ->get();
        $recipients = $recipients->merge($admins);

        // Membros do grupo
        if ($order->group_id) {
            $members = User::whereHas('groups', function($query) use ($order) {
                $query->where('groups.id', $order->group_id);
            })
            ->where('id', '!=', $sender->id)
            ->get();
            $recipients = $recipients->merge($members);
        }

        // Remover duplicados
        $recipients = $recipients->unique('id');

        foreach ($recipients as $recipient) {
            try {
                // ✅ CORRIGIDO: NewMessageNotification → ServiceOrderNotification
                $recipient->notify(new ServiceOrderNotification($order, $message, $sender));
            } catch (\Exception $e) {
                \Log::error('Erro ao enviar notificação de mensagem', [
                    'recipient_id' => $recipient->id,
                    'order_id' => $order->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function formatMessage($message)
    {
        return [
            'id' => $message->id,
            'user_id' => $message->user_id,
            'user_name' => $message->user ? $message->user->name : 'Sistema',
            'user_email' => $message->user ? $message->user->email : null,
            'user_avatar' => $message->user && $message->user->avatar ? Storage::url($message->user->avatar) : null,
            'message' => $message->message,
            'is_system_message' => (bool) $message->is_system_message,
            'has_attachment' => $message->hasAttachment(),
            'attachment_url' => $message->attachment_url,
            'attachment_info' => $message->getAttachmentInfo(),
            'is_read' => $message->is_read,
            'created_at' => $message->formatted_date,
            'created_at_human' => $message->created_at->diffForHumans(),
            'updated_at' => $message->updated_at->format('d/m/Y H:i'),
        ];
    }
}
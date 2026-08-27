<?php

namespace App\Http\Controllers\ServiceOrder;

use App\Http\Controllers\Controller;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderMessage;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

class ServiceOrderMessageController extends Controller
{
    // ... (index mantido igual)

    public function store(Request $request, $serviceOrderId)
    {
        $order = ServiceOrder::findOrFail($serviceOrderId);
        
        $user = auth()->user();
        $hasAccess = $user->is_admin || 
                     $order->user_id == $user->id || 
                     ($order->group_id && $user->groups->contains($order->group_id));
        
        if (!$hasAccess) {
            return response()->json(['message' => 'Sem permissão'], 403);
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

        // --- LOG DE AUDITORIA ---
        AuditLog::create([
            'user_id' => $user->id,
            'method' => 'POST',
            'url' => $request->fullUrl(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'payload' => [
                'action' => 'message_created',
                'service_order_id' => $order->id,
                'message_id' => $message->id,
                'message_preview' => substr($message->message, 0, 100),
            ],
        ]);

        return response()->json($message->load('user'), 201);
    }

    public function update(Request $request, $serviceOrderId, $messageId)
    {
        $message = ServiceOrderMessage::where('service_order_id', $serviceOrderId)
                                      ->findOrFail($messageId);
        
        $user = auth()->user();
        if (!$user->is_admin && $message->user_id !== $user->id) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        $validated = $request->validate([
            'message' => 'required|string|max:5000',
        ]);

        $oldMessage = $message->message; // guarda antes de mudar

        $message->message = $validated['message'];
        $message->save();

        // --- LOG DE AUDITORIA ---
        AuditLog::create([
            'user_id' => $user->id,
            'method' => 'PUT',
            'url' => $request->fullUrl(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'payload' => [
                'action' => 'message_updated',
                'service_order_id' => $serviceOrderId,
                'message_id' => $message->id,
                'old_message' => $oldMessage,
                'new_message' => $message->message,
            ],
        ]);

        return response()->json($message->load('user'));
    }

    public function destroy($serviceOrderId, $messageId)
    {
        $message = ServiceOrderMessage::where('service_order_id', $serviceOrderId)
                                      ->findOrFail($messageId);
        
        $user = auth()->user();
        if (!$user->is_admin && $message->user_id !== $user->id) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        // Guarda dados antes de deletar
        $messageData = [
            'message' => $message->message,
            'attachment' => $message->attachment_path,
        ];

        if ($message->attachment_path) {
            Storage::disk('public')->delete($message->attachment_path);
        }

        $message->delete();

        // --- LOG DE AUDITORIA ---
        AuditLog::create([
            'user_id' => $user->id,
            'method' => 'DELETE',
            'url' => request()->fullUrl(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'payload' => [
                'action' => 'message_deleted',
                'service_order_id' => $serviceOrderId,
                'message_id' => $messageId,
                'deleted_message' => $messageData['message'],
            ],
        ]);

        return response()->json(['message' => 'Mensagem removida com sucesso.']);
    }
}
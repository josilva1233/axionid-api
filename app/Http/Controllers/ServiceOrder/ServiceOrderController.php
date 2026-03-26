<?php

namespace App\Http\Controllers\ServiceOrder;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use App\Models\ServiceOrder;
use OpenApi\Attributes as OA;
use Illuminate\Support\Facades\Storage;

class ServiceOrderController extends Controller
{
    #[OA\Get(
        path: '/api/v1/service-orders',
        summary: 'Listar Ordens de Serviço',
        description: 'Retorna as OSs vinculadas ao usuário, ao seu grupo ou todas se for admin.',
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
        $os = ServiceOrder::findOrFail($id);
        
        if (!auth()->user()->is_admin && $os->user_id !== auth()->id()) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        if ($request->has('status')) {
            $os->status = $request->status;
        }

        if ($request->status === 'in_progress') {
            $os->technician_id = auth()->id();
        }

        $os->save();
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
        
        // Apenas admin pode excluir
        if (!auth()->user()->is_admin) {
            return response()->json(['message' => 'Apenas administradores podem excluir ordens de serviço.'], 403);
        }
        
        // Remove o anexo se existir
        if ($order->attachment_path) {
            Storage::disk('public')->delete($order->attachment_path);
        }
        
        $order->delete();
        
        return response()->json(['message' => 'Ordem de serviço excluída com sucesso.'], 200);
    }
}
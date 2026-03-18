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

class ServiceOrderController extends Controller
{
    #[OA\Get(
        path: '/api/v1/service-orders',
        summary: 'Listar Ordens de Serviço',
        description: 'Retorna as OSs vinculadas ao usuário, ao seu grupo ou todas se for admin.',
        tags: ['Ordens de Serviço'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Lista de OS recuperada com sucesso'),
            new OA\Response(response: 401, description: 'Não autenticado')
        ]
    )]
    public function index()
    {
        $user = auth()->user();
        
        if ($user->is_admin) {
            return ServiceOrder::with(['user', 'group'])->latest()->get();
        }

        $groupIds = $user->groups->pluck('id');
        return ServiceOrder::where('user_id', $user->id)
            ->orWhereIn('group_id', $groupIds)
            ->with(['user', 'group'])
            ->latest()
            ->get();
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
}
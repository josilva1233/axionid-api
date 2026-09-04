<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Categorias', description: 'Gerenciamento de categorias de chamados')]
class CategoryController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('admin')->except(['index', 'show']);
    }

    #[OA\Get(
        path: '/api/v1/categories',
        summary: 'Listar categorias ativas',
        tags: ['Categorias'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Lista de categorias')
        ]
    )]
    public function index()
    {
        return Category::active()->with('defaultGroup')->get();
    }

    #[OA\Post(
        path: '/api/v1/categories',
        summary: 'Criar nova categoria (admin)',
        tags: ['Categorias'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'sla_first_response_hours', 'sla_resolution_hours'],
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'default_group_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'sla_first_response_hours', type: 'integer', example: 4),
                    new OA\Property(property: 'sla_resolution_hours', type: 'integer', example: 24),
                    new OA\Property(property: 'default_priority', type: 'string', enum: ['low','medium','high','urgent'], example: 'medium'),
                    new OA\Property(property: 'is_active', type: 'boolean', example: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Categoria criada'),
            new OA\Response(response: 422, description: 'Erro de validação')
        ]
    )]
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:categories',
            'description' => 'nullable|string',
            'default_group_id' => 'nullable|exists:groups,id',
            'sla_first_response_hours' => 'required|integer|min:1|max:720',
            'sla_resolution_hours' => 'required|integer|min:1|max:720',
            'default_priority' => 'sometimes|in:low,medium,high,urgent',
            'is_active' => 'boolean',
        ]);

        $data['slug'] = Str::slug($data['name']);
        $category = Category::create($data);

        return response()->json($category, 201);
    }

    // PUT, DELETE, etc. (semelhante ao padrão)
}
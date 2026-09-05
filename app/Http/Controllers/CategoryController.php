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
        $this->middleware('admin')->except(['index', 'show', 'tree']);
    }

    /**
     * Retorna a árvore completa de categorias (pai → filhos)
     */
    #[OA\Get(
        path: '/api/v1/categories/tree',
        summary: 'Árvore de categorias',
        tags: ['Categorias'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Árvore hierárquica')
        ]
    )]
    public function tree()
    {
        return response()->json(Category::getTree());
    }

    /**
     * Lista todas as categorias ativas (formato plano, com nível)
     * 🔥 AGORA COM RELACIONAMENTO DEFAULTGROUP CARREGADO
     */
    #[OA\Get(
        path: '/api/v1/categories',
        summary: 'Listar categorias ativas (plano)',
        tags: ['Categorias'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Lista plana de categorias')
        ]
    )]
    public function index()
    {
        // 🔥 Carrega todas as categorias com o relacionamento defaultGroup
        $categories = Category::with('defaultGroup')->get();
        
        // 🔥 Constrói a lista plana com níveis (recursivo)
        return $this->buildFlatList($categories);
    }

    /**
     * Constrói uma lista plana com níveis a partir de uma coleção de categorias
     */
    private function buildFlatList($categories, $parentId = null, $depth = 0, &$list = [])
    {
        foreach ($categories as $cat) {
            if ($cat->parent_id == $parentId) {
                $cat->level = $depth;
                $list[] = $cat;
                $this->buildFlatList($categories, $cat->id, $depth + 1, $list);
            }
        }
        return $list;
    }

    /**
     * Detalhes de uma categoria (com pai, filhos e grupo padrão)
     */
    #[OA\Get(
        path: '/api/v1/categories/{id}',
        summary: 'Obter detalhes de uma categoria',
        tags: ['Categorias'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detalhes da categoria'),
            new OA\Response(response: 404, description: 'Não encontrada')
        ]
    )]
    public function show($id)
    {
        $category = Category::with(['parent', 'children', 'defaultGroup'])->findOrFail($id);
        return response()->json($category);
    }

    /**
     * Criar nova categoria (apenas admin)
     */
    #[OA\Post(
        path: '/api/v1/admin/categories',
        summary: 'Criar nova categoria',
        tags: ['Categorias'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'sla_first_response_hours', 'sla_resolution_hours'],
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'parent_id', type: 'integer', nullable: true, description: 'ID da categoria pai (opcional)'),
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
        // 🔥 Converte strings vazias em null para campos opcionais
        $data = $request->all();
        foreach (['parent_id', 'default_group_id'] as $field) {
            if (isset($data[$field]) && $data[$field] === '') {
                $data[$field] = null;
            }
        }

        // Validação com os dados já convertidos
        $validated = validator($data, [
            'name' => 'required|string|max:255|unique:categories',
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:categories,id',
            'default_group_id' => 'nullable|exists:groups,id',
            'sla_first_response_hours' => 'required|integer|min:1|max:720',
            'sla_resolution_hours' => 'required|integer|min:1|max:720',
            'default_priority' => 'sometimes|in:low,medium,high,urgent',
            'is_active' => 'boolean',
        ])->validate();

        // 🔥 Gera slug único (evita conflitos)
        $slug = Str::slug($validated['name']);
        $count = Category::where('slug', 'like', $slug . '%')->count();
        if ($count > 0) {
            $slug = $slug . '-' . ($count + 1);
        }
        $validated['slug'] = $slug;

        $category = Category::create($validated);
        return response()->json($category, 201);
    }

    /**
     * Atualizar categoria (apenas admin)
     */
    #[OA\Put(
        path: '/api/v1/admin/categories/{id}',
        summary: 'Atualizar categoria',
        tags: ['Categorias'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'parent_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'default_group_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'sla_first_response_hours', type: 'integer'),
                    new OA\Property(property: 'sla_resolution_hours', type: 'integer'),
                    new OA\Property(property: 'default_priority', type: 'string', enum: ['low','medium','high','urgent']),
                    new OA\Property(property: 'is_active', type: 'boolean'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Categoria atualizada'),
            new OA\Response(response: 422, description: 'Erro de validação')
        ]
    )]
    public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);

        // 🔥 Converte strings vazias em null para campos opcionais
        $data = $request->all();
        foreach (['parent_id', 'default_group_id'] as $field) {
            if (isset($data[$field]) && $data[$field] === '') {
                $data[$field] = null;
            }
        }

        $validated = validator($data, [
            'name' => 'required|string|max:255|unique:categories,name,' . $id,
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:categories,id|not_in:' . $id,
            'default_group_id' => 'nullable|exists:groups,id',
            'sla_first_response_hours' => 'required|integer|min:1|max:720',
            'sla_resolution_hours' => 'required|integer|min:1|max:720',
            'default_priority' => 'sometimes|in:low,medium,high,urgent',
            'is_active' => 'boolean',
        ])->validate();

        // 🔥 Gera slug único (se o nome mudou)
        if (isset($validated['name']) && $validated['name'] !== $category->name) {
            $slug = Str::slug($validated['name']);
            $count = Category::where('slug', 'like', $slug . '%')
                              ->where('id', '!=', $id)
                              ->count();
            if ($count > 0) {
                $slug = $slug . '-' . ($count + 1);
            }
            $validated['slug'] = $slug;
        }

        $category->update($validated);
        return response()->json($category);
    }

    /**
     * Excluir categoria (apenas admin) – só se não tiver filhos ou chamados
     */
    #[OA\Delete(
        path: '/api/v1/admin/categories/{id}',
        summary: 'Excluir categoria',
        tags: ['Categorias'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Categoria excluída'),
            new OA\Response(response: 422, description: 'Não pode ser excluída (tem filhos ou chamados)')
        ]
    )]
    public function destroy($id)
    {
        $category = Category::withCount(['children', 'serviceOrders'])->findOrFail($id);

        if ($category->children_count > 0 || $category->service_orders_count > 0) {
            return response()->json([
                'message' => 'Não é possível excluir categoria com subcategorias ou chamados vinculados.'
            ], 422);
        }

        $category->delete();
        return response()->json(['message' => 'Categoria excluída com sucesso.']);
    }
}
<?php
// app/Http/Controllers/Auth/TermController.php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Term;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class TermController extends Controller
{
    /**
     * Verifica se o usuário precisa aceitar os termos
     */
    public function checkStatus(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'authenticated' => false,
                'needs_acceptance' => false,
            ]);
        }

        $currentTerm = Term::getActiveTerm();
        
        if (!$currentTerm) {
            return response()->json([
                'authenticated' => true,
                'needs_acceptance' => false,
                'message' => 'Nenhum termo ativo disponível',
            ]);
        }

        $hasAccepted = $user->hasAcceptedCurrentTerm();

        return response()->json([
            'authenticated' => true,
            'needs_acceptance' => !$hasAccepted,
            'term' => !$hasAccepted ? [
                'id' => $currentTerm->id,
                'content' => $currentTerm->content,
                'version' => $currentTerm->version,
                'published_at' => $currentTerm->published_at,
            ] : null,
        ]);
    }

    /**
     * Aceitar os termos de uso
     */
    public function accept(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Usuário não autenticado'], 401);
        }

        $validator = Validator::make($request->all(), [
            'term_id' => 'required|exists:terms,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $term = Term::find($request->term_id);

        if (!$term->is_active || !$term->published_at) {
            return response()->json([
                'message' => 'Este termo não está mais ativo ou não foi publicado',
            ], 422);
        }

        // Verifica se já aceitou este termo específico
        if ($term->isAcceptedByUser($user)) {
            return response()->json([
                'message' => 'Você já aceitou este termo',
                'already_accepted' => true,
            ]);
        }

        try {
            $acceptance = $user->acceptTerm(
                $term,
                $request->ip(),
                $request->userAgent()
            );

            Log::info('Termo de uso aceito', [
                'user_id' => $user->id,
                'term_id' => $term->id,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Termos de uso aceitos com sucesso',
                'accepted' => true,
                'accepted_at' => $acceptance->accepted_at,
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao aceitar termos', [
                'user_id' => $user->id,
                'term_id' => $term->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Erro ao aceitar os termos',
            ], 500);
        }
    }

    /**
     * Obter termo atual (público)
     */
    public function getCurrentTerm()
    {
        $term = Term::getActiveTerm();

        if (!$term) {
            return response()->json(['message' => 'Nenhum termo ativo disponível'], 404);
        }

        return response()->json([
            'id' => $term->id,
            'content' => $term->content,
            'version' => $term->version,
            'published_at' => $term->published_at,
        ]);
    }

    // =========================================================
    // ADMIN: Gestão de Termos
    // =========================================================

    /**
     * Listar todos os termos (Admin)
     */
    public function index(Request $request)
    {
        if (!Auth::user()->is_admin) {
            return response()->json(['message' => 'Acesso negado'], 403);
        }

        $query = Term::with('creator');
        
        if ($request->has('search')) {
            $query->where('content', 'like', '%' . $request->search . '%');
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->is_active === 'true');
        }

        return response()->json($query->orderBy('created_at', 'desc')->paginate(15));
    }

    /**
     * Criar novo termo (Admin)
     */
    public function store(Request $request)
    {
        if (!Auth::user()->is_admin) {
            return response()->json(['message' => 'Acesso negado'], 403);
        }

        $validator = Validator::make($request->all(), [
            'content' => 'required|string|min:10',
            'version' => 'required|string|max:20',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Se este termo for ativo, desativa os outros
        if ($request->is_active) {
            Term::where('is_active', true)->update(['is_active' => false]);
        }

        $term = Term::create([
            'content' => $request->content,
            'version' => $request->version,
            'is_active' => $request->is_active ?? false,
            'created_by' => Auth::id(),
            'published_at' => $request->is_active ? now() : null,
        ]);

        Log::info('Novo termo de uso criado', [
            'term_id' => $term->id,
            'created_by' => Auth::id(),
            'version' => $term->version,
        ]);

        return response()->json([
            'message' => 'Termo criado com sucesso',
            'term' => $term->load('creator'),
        ], 201);
    }

    /**
     * Atualizar termo (Admin)
     */
    public function update(Request $request, $id)
    {
        if (!Auth::user()->is_admin) {
            return response()->json(['message' => 'Acesso negado'], 403);
        }

        $term = Term::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'content' => 'sometimes|string|min:10',
            'version' => 'sometimes|string|max:20',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Se for ativar este termo, desativa os outros
        if ($request->has('is_active') && $request->is_active === true) {
            Term::where('is_active', true)
                ->where('id', '!=', $term->id)
                ->update(['is_active' => false]);
        }

        $term->update($request->only(['content', 'version', 'is_active']));

        // Atualiza published_at se for ativado
        if ($request->has('is_active') && $request->is_active === true && !$term->published_at) {
            $term->update(['published_at' => now()]);
        }

        Log::info('Termo de uso atualizado', [
            'term_id' => $term->id,
            'updated_by' => Auth::id(),
        ]);

        return response()->json([
            'message' => 'Termo atualizado com sucesso',
            'term' => $term->load('creator'),
        ]);
    }

    /**
     * Excluir termo (Admin)
     */
    public function destroy($id)
    {
        if (!Auth::user()->is_admin) {
            return response()->json(['message' => 'Acesso negado'], 403);
        }

        $term = Term::findOrFail($id);

        // Não permite excluir um termo que já foi aceito
        if ($term->acceptances()->count() > 0) {
            return response()->json([
                'message' => 'Não é possível excluir um termo que já foi aceito por usuários',
            ], 422);
        }

        $term->delete();

        Log::info('Termo de uso excluído', [
            'term_id' => $id,
            'deleted_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Termo excluído com sucesso']);
    }

    /**
     * Ativar/Desativar termo (Admin)
     */
    public function toggleStatus($id)
    {
        if (!Auth::user()->is_admin) {
            return response()->json(['message' => 'Acesso negado'], 403);
        }

        $term = Term::findOrFail($id);

        // Se for ativar, desativa os outros
        if (!$term->is_active) {
            Term::where('is_active', true)->update(['is_active' => false]);
            $term->update([
                'is_active' => true,
                'published_at' => $term->published_at ?? now(),
            ]);
        } else {
            $term->update(['is_active' => false]);
        }

        return response()->json([
            'message' => 'Status do termo atualizado',
            'is_active' => $term->is_active,
        ]);
    }
}
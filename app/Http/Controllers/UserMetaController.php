<?php

namespace App\Http\Controllers;

use App\Models\UserMeta;
use Illuminate\Http\Request;

class UserMetaController extends Controller
{
    public function index(Request $request)
    {
        $q = UserMeta::with('user:id,name');

        $user = auth()->user();
        if ($user && $user->role === 'corretor') {
            $q->where('user_id', $user->id);
        } elseif ($request->filled('user_id')) {
            $q->where('user_id', (int) $request->user_id);
        }

        if ($request->filled('mes'))  $q->where('mes', (int) $request->mes);
        if ($request->filled('ano'))  $q->where('ano', (int) $request->ano);

        return response()->json(
            $q->orderBy('ano', 'desc')->orderBy('mes', 'desc')->get()
        );
    }

    public function show(UserMeta $userMeta)
    {
        $user = auth()->user();
        if ($user && $user->role === 'corretor' && $userMeta->user_id !== $user->id) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        return response()->json($userMeta->load('user:id,name'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id'           => 'required|exists:users,id',
            'mes'               => 'required|integer|min:1|max:12',
            'ano'               => 'required|integer|min:2024|max:2100',
            'meta_leads'        => 'nullable|integer|min:0',
            'meta_atendimentos' => 'nullable|integer|min:0',
            'meta_vendas'       => 'nullable|integer|min:0',
        ]);

        $meta = UserMeta::updateOrCreate(
            [
                'user_id' => $data['user_id'],
                'mes'     => $data['mes'],
                'ano'     => $data['ano'],
            ],
            [
                'meta_leads'        => $data['meta_leads']        ?? 0,
                'meta_atendimentos' => $data['meta_atendimentos'] ?? 0,
                'meta_vendas'       => $data['meta_vendas']       ?? 0,
            ]
        );

        return response()->json($meta->load('user:id,name'), 201);
    }

    public function update(Request $request, UserMeta $userMeta)
    {
        $data = $request->validate([
            'meta_leads'        => 'nullable|integer|min:0',
            'meta_atendimentos' => 'nullable|integer|min:0',
            'meta_vendas'       => 'nullable|integer|min:0',
        ]);

        $userMeta->update($data);

        return response()->json($userMeta->fresh()->load('user:id,name'));
    }

    public function destroy(UserMeta $userMeta)
    {
        $userMeta->delete();
        return response()->json(['message' => 'Meta removida']);
    }
}

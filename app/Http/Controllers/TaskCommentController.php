<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\TaskComment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * TaskCommentController — CRUD leve de comentários em tarefas.
 *
 * Regras:
 *   - LEITURA / CRIAÇÃO: quem pode VER a tarefa pode comentar.
 *     Ou seja: admin/gestor (sempre), dono (user_id=self), e qualquer
 *     corretor se scope='company'.
 *   - EXCLUSÃO: só o AUTOR do comentário ou admin/gestor.
 *
 * Nota: reaproveitamos a lógica de "quem vê a tarefa" inline pra
 * evitar depender de Policy; o projeto segue esse padrão nos demais
 * controllers de tarefa (ver TaskController).
 */
class TaskCommentController extends Controller
{
    /* ==================================================================
     * INDEX — lista comentários da tarefa
     * ================================================================== */
    public function index(int $taskId)
    {
        $task = Appointment::tasks()->findOrFail($taskId);
        $this->authorizeView($task);

        return response()->json(
            $task->comments()->get()
        );
    }

    /* ==================================================================
     * STORE — novo comentário
     * ================================================================== */
    public function store(Request $request, int $taskId)
    {
        $task = Appointment::tasks()->findOrFail($taskId);
        $this->authorizeView($task);

        $data = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $comment = TaskComment::create([
            'task_id' => $task->id,
            'user_id' => Auth::id(),
            'body'    => trim($data['body']),
        ]);

        return response()->json(
            $comment->load('user:id,name'),
            201
        );
    }

    /* ==================================================================
     * DESTROY — remove o comentário
     * ================================================================== */
    public function destroy(int $taskId, int $commentId)
    {
        $comment = TaskComment::where('task_id', $taskId)
            ->findOrFail($commentId);

        $user = Auth::user();
        $isManager = in_array(
            strtolower(trim((string) ($user->role ?? ''))),
            ['admin', 'gestor'],
            true
        );

        abort_if(
            !$isManager && (int) $comment->user_id !== (int) $user->id,
            403,
            'Só quem escreveu o comentário (ou admin/gestor) pode excluir.'
        );

        $comment->delete();

        return response()->json(['success' => true]);
    }

    /* ==================================================================
     * HELPER — autoriza visualização da tarefa (mesma regra de leitura
     * usada no TaskController).
     * ================================================================== */
    private function authorizeView(Appointment $task): void
    {
        $user = Auth::user();
        $role = strtolower(trim((string) ($user->role ?? '')));

        if (in_array($role, ['admin', 'gestor'], true)) {
            return;
        }

        $ok = (int) $task->user_id === (int) $user->id
            || (int) $task->created_by === (int) $user->id
            || $task->scope === 'company';

        abort_if(!$ok, 403, 'Sem permissão pra acessar esta tarefa.');
    }
}

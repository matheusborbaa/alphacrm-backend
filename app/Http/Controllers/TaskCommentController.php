<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\TaskComment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TaskCommentController extends Controller
{

    public function index(int $taskId)
    {
        $task = Appointment::tasks()->findOrFail($taskId);
        $this->authorizeView($task);

        return response()->json(
            $task->comments()->get()
        );
    }

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

    private function authorizeView(Appointment $task): void
    {
        $user = Auth::user();
        $role = strtolower(trim((string) ($user->role ?? '')));
        $isManager = in_array($role, ['admin', 'gestor'], true);

        if ($isManager) {
            $othersPrivate = $task->scope === 'private'
                && is_null($task->lead_id)
                && (int) $task->user_id    !== (int) $user->id
                && (int) $task->created_by !== (int) $user->id;

            abort_if(
                $othersPrivate,
                403,
                'Esta é uma tarefa pessoal do corretor.'
            );
            return;
        }

        $ok = (int) $task->user_id === (int) $user->id
            || (int) $task->created_by === (int) $user->id
            || $task->scope === 'company';

        abort_if(!$ok, 403, 'Sem permissão pra acessar esta tarefa.');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\AcademyCourse;
use App\Models\AcademyQuizAttempt;
use App\Models\AcademyQuizQuestion;
use App\Models\AcademyUserProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// Quiz: admin manda perguntas; user faz submit, backend calcula nota e marca passed se >= quiz_min_score.
// Endpoint user nunca devolve correct_index pra não vazar o gabarito.
class AcademyQuizController extends Controller
{

    public function adminListQuestions(AcademyCourse $course)
    {
        return $course->quizQuestions()->orderBy('order')->get();
    }

    public function adminStoreQuestion(Request $request, AcademyCourse $course)
    {
        $data = $this->validateQuestion($request);
        $data['course_id'] = $course->id;
        $data['order']     = $data['order'] ?? ($course->quizQuestions()->max('order') + 1);
        return AcademyQuizQuestion::create($data);
    }

    public function adminUpdateQuestion(Request $request, AcademyQuizQuestion $question)
    {
        $data = $this->validateQuestion($request, true);
        $question->update($data);
        return $question->fresh();
    }

    public function adminDestroyQuestion(AcademyQuizQuestion $question)
    {
        $question->delete();
        return response()->json(['success' => true]);
    }

    private function validateQuestion(Request $request, bool $partial = false): array
    {
        $rule = $partial ? 'sometimes|' : 'required|';
        return $request->validate([
            'question'      => $rule . 'string|max:500',
            'options'       => $rule . 'array|min:2|max:6',
            'options.*'     => 'string|max:300',
            'correct_index' => $rule . 'integer|min:0',
            'order'         => 'nullable|integer|min:0',
        ]);
    }


    public function userGetQuiz(AcademyCourse $course)
    {
        abort_unless($course->published, 404);
        abort_unless($course->has_quiz, 422, 'Esse curso não tem quiz.');


        if (!$this->allLessonsDone($course)) {
            return response()->json([
                'message' => 'Conclua todas as aulas antes de fazer o quiz.',
            ], 422);
        }

        $questions = $course->quizQuestions()->orderBy('order')->get()->map(fn($q) => [
            'id'       => $q->id,
            'question' => $q->question,
            'options'  => $q->options,

        ]);

        $bestAttempt = AcademyQuizAttempt::where('user_id', Auth::id())
            ->where('course_id', $course->id)
            ->orderByDesc('score')
            ->first();

        return response()->json([
            'course_id'      => $course->id,
            'min_score'      => $course->quiz_min_score,
            'questions'      => $questions,
            'best_score'     => $bestAttempt?->score,
            'best_passed'    => (bool) ($bestAttempt?->passed ?? false),
            'attempts_count' => AcademyQuizAttempt::where('user_id', Auth::id())->where('course_id', $course->id)->count(),
        ]);
    }

    public function userSubmitQuiz(Request $request, AcademyCourse $course)
    {
        abort_unless($course->published, 404);
        abort_unless($course->has_quiz, 422);

        if (!$this->allLessonsDone($course)) {
            return response()->json(['message' => 'Conclua todas as aulas antes do quiz.'], 422);
        }

        $data = $request->validate([
            'answers'             => 'required|array',
            'answers.*.question_id' => 'required|integer|exists:academy_quiz_questions,id',
            'answers.*.choice'    => 'required|integer|min:0',
        ]);

        $questions = $course->quizQuestions()->get()->keyBy('id');
        $total = $questions->count();
        if ($total === 0) {
            return response()->json(['message' => 'Quiz sem perguntas cadastradas.'], 422);
        }

        $correct = 0;
        $detailed = [];

        foreach ($data['answers'] as $a) {
            $q = $questions[$a['question_id']] ?? null;
            if (!$q) continue;

            $isCorrect = (int) $a['choice'] === (int) $q->correct_index;
            if ($isCorrect) $correct++;

            $detailed[] = [
                'question_id'  => $q->id,
                'choice'       => (int) $a['choice'],
                'correct'      => $isCorrect,
                'correct_index'=> (int) $q->correct_index,
            ];
        }

        $score  = (int) round(($correct / $total) * 100);
        $passed = $score >= (int) $course->quiz_min_score;

        $attempt = AcademyQuizAttempt::create([
            'user_id'      => Auth::id(),
            'course_id'    => $course->id,
            'answers'      => $detailed,
            'score'        => $score,
            'passed'       => $passed,
            'attempted_at' => now(),
        ]);

        return response()->json([
            'attempt_id'   => $attempt->id,
            'score'        => $score,
            'correct'      => $correct,
            'total'        => $total,
            'passed'       => $passed,
            'min_score'    => (int) $course->quiz_min_score,
            'detailed'     => $detailed,
        ]);
    }

    public function userListAttempts(AcademyCourse $course)
    {
        return AcademyQuizAttempt::where('user_id', Auth::id())
            ->where('course_id', $course->id)
            ->orderByDesc('attempted_at')
            ->limit(10)
            ->get(['id','score','passed','attempted_at']);
    }


    private function allLessonsDone(AcademyCourse $course): bool
    {
        $lessonIds = $course->lessons()->pluck('id');
        if ($lessonIds->isEmpty()) return false;

        $doneCount = AcademyUserProgress::where('user_id', Auth::id())
            ->whereIn('lesson_id', $lessonIds)
            ->whereNotNull('completed_at')
            ->count();

        return $doneCount === $lessonIds->count();
    }
}

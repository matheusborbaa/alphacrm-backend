<?php

namespace App\Http\Controllers;

use App\Models\AcademyCategory;
use App\Models\AcademyCertificate;
use App\Models\AcademyCourse;
use App\Models\AcademyLesson;
use App\Models\AcademyQuizAttempt;
use App\Models\AcademyUserProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// Endpoints que o corretor usa pra consumir cursos. Tracking de progresso vem aqui.
class AcademyController extends Controller
{
    public function listCategories()
    {
        $cats = AcademyCategory::orderBy('order')->orderBy('name')
            ->withCount(['courses as published_count' => fn($q) => $q->where('published', true)])
            ->get();
        return response()->json($cats);
    }

    public function listCourses(Request $request)
    {
        $userId = Auth::id();

        $q = AcademyCourse::where('published', true)
            ->with(['category', 'lessons:id,course_id,duration_seconds'])
            ->orderBy('order')
            ->orderBy('title');

        if ($request->filled('category_id')) {
            $q->where('category_id', (int) $request->category_id);
        }

        $courses = $q->get();


        $lessonIds = $courses->flatMap->lessons->pluck('id');
        $progressByLesson = AcademyUserProgress::where('user_id', $userId)
            ->whereIn('lesson_id', $lessonIds)
            ->get()
            ->keyBy('lesson_id');

        $payload = $courses->map(function ($course) use ($progressByLesson) {
            $totalLessons = $course->lessons->count();
            $completedLessons = 0;
            foreach ($course->lessons as $l) {
                if ($progressByLesson->has($l->id) && $progressByLesson[$l->id]->completed_at) {
                    $completedLessons++;
                }
            }
            $progressPct = $totalLessons > 0 ? round(($completedLessons / $totalLessons) * 100) : 0;

            return [
                'id'                  => $course->id,
                'title'               => $course->title,
                'description'         => $course->description,
                'cover_image'         => $course->cover_image,
                'category'            => $course->category ? ['id' => $course->category->id, 'name' => $course->category->name, 'color' => $course->category->color] : null,
                'lessons_count'       => $totalLessons,
                'completed_count'     => $completedLessons,
                'progress_pct'        => $progressPct,
                'has_quiz'            => (bool) $course->has_quiz,
                'certificate_enabled' => (bool) $course->certificate_enabled,
                'is_completed'        => $totalLessons > 0 && $completedLessons === $totalLessons,
            ];
        });

        return response()->json($payload);
    }

    public function showCourse(AcademyCourse $course)
    {
        abort_unless($course->published, 404, 'Curso não está publicado.');

        $course->load(['category', 'lessons.materials', 'quizQuestions']);

        $userId = Auth::id();
        $progressByLesson = AcademyUserProgress::where('user_id', $userId)
            ->whereIn('lesson_id', $course->lessons->pluck('id'))
            ->get()
            ->keyBy('lesson_id');

        $lessonsPayload = $course->lessons->map(function ($l) use ($progressByLesson) {
            $p = $progressByLesson[$l->id] ?? null;
            return [
                'id'                    => $l->id,
                'title'                 => $l->title,
                'description'           => $l->description,
                'video_path'            => $l->video_path,
                'duration_seconds'      => $l->duration_seconds,
                'order'                 => $l->order,
                'materials'             => $l->materials->map(fn($m) => [
                    'id'        => $m->id,
                    'name'      => $m->name,
                    'file_path' => $m->file_path,
                    'size_kb'   => round($m->file_size_bytes / 1024),
                ]),
                'progress' => [
                    'watch_seconds'         => $p?->watch_seconds ?? 0,
                    'last_position_seconds' => $p?->last_position_seconds ?? 0,
                    'completed_at'          => $p?->completed_at?->toIso8601String(),
                ],
            ];
        });

        $totalLessons = $course->lessons->count();
        $completedLessons = $course->lessons->filter(fn($l) =>
            isset($progressByLesson[$l->id]) && $progressByLesson[$l->id]->completed_at
        )->count();

        return response()->json([
            'id'                  => $course->id,
            'title'               => $course->title,
            'description'         => $course->description,
            'cover_image'         => $course->cover_image,
            'category'            => $course->category,
            'has_quiz'            => (bool) $course->has_quiz,
            'quiz_min_score'      => $course->quiz_min_score,
            'certificate_enabled' => (bool) $course->certificate_enabled,
            'progress_pct'        => $totalLessons > 0 ? round(($completedLessons / $totalLessons) * 100) : 0,
            'completed_count'     => $completedLessons,
            'lessons_count'       => $totalLessons,
            'lessons'             => $lessonsPayload,
            'all_lessons_done'    => $totalLessons > 0 && $completedLessons === $totalLessons,
        ]);
    }

    public function updateProgress(Request $request, AcademyLesson $lesson)
    {
        $data = $request->validate([
            'watch_seconds'         => 'required|integer|min:0|max:86400',
            'last_position_seconds' => 'nullable|integer|min:0|max:86400',
            'auto_complete'         => 'nullable|boolean',
        ]);

        $userId = Auth::id();

        $progress = AcademyUserProgress::firstOrCreate(
            ['user_id' => $userId, 'lesson_id' => $lesson->id],
            ['started_at' => now()]
        );


        // Só sobe — protege contra um payload atrasado zerar o progresso de quem foi mais longe.
        $newWatch = max((int) $progress->watch_seconds, (int) $data['watch_seconds']);
        $progress->watch_seconds = $newWatch;
        if (isset($data['last_position_seconds'])) {
            $progress->last_position_seconds = (int) $data['last_position_seconds'];
        }

        // 90% do vídeo conta como assistido. Acima disso fecha automático.
        if ($progress->completed_at === null
            && $lesson->duration_seconds > 0
            && $newWatch >= (int) ($lesson->duration_seconds * 0.9)) {
            $progress->completed_at = now();
            $progress->completed_via = 'auto';
        }

        $progress->save();

        return response()->json([
            'watch_seconds' => $progress->watch_seconds,
            'completed'     => $progress->completed_at !== null,
            'completed_at'  => $progress->completed_at?->toIso8601String(),
        ]);
    }

    public function markComplete(Request $request, AcademyLesson $lesson)
    {
        $userId = Auth::id();

        $progress = AcademyUserProgress::firstOrCreate(
            ['user_id' => $userId, 'lesson_id' => $lesson->id],
            ['started_at' => now()]
        );

        if ($progress->completed_at === null) {
            $progress->completed_at = now();
            $progress->completed_via = 'manual';
            if ($lesson->duration_seconds > 0 && $progress->watch_seconds < $lesson->duration_seconds) {
                $progress->watch_seconds = $lesson->duration_seconds;
            }
            $progress->save();
        }

        return response()->json([
            'completed'    => true,
            'completed_at' => $progress->completed_at?->toIso8601String(),
        ]);
    }


    // Histórico do próprio usuário pra aba "Academy" no perfil. Devolve todos cursos onde tem progresso.
    public function myHistory()
    {
        $userId = Auth::id();


        // Cursos onde o user tem ao menos 1 progresso registrado.
        $courseIds = DB::table('academy_user_progress as p')
            ->join('academy_lessons as l', 'l.id', '=', 'p.lesson_id')
            ->where('p.user_id', $userId)
            ->distinct()
            ->pluck('l.course_id')
            ->all();

        if (empty($courseIds)) {
            return response()->json([]);
        }

        $courses = AcademyCourse::with(['category', 'lessons:id,course_id,duration_seconds'])
            ->whereIn('id', $courseIds)
            ->get();

        $progressByLesson = AcademyUserProgress::where('user_id', $userId)
            ->whereIn('lesson_id', $courses->flatMap->lessons->pluck('id'))
            ->get()
            ->keyBy('lesson_id');

        $quizByCourse = AcademyQuizAttempt::where('user_id', $userId)
            ->whereIn('course_id', $courseIds)
            ->select('course_id', DB::raw('MAX(score) as best_score'), DB::raw('MAX(passed) as any_passed'), DB::raw('COUNT(*) as attempts'))
            ->groupBy('course_id')
            ->get()
            ->keyBy('course_id');

        $certs = AcademyCertificate::where('user_id', $userId)
            ->whereIn('course_id', $courseIds)
            ->get()
            ->keyBy('course_id');

        $payload = $courses->map(function ($c) use ($progressByLesson, $quizByCourse, $certs, $userId) {
            $totalLessons = $c->lessons->count();
            $done = 0; $startedAt = null; $lastAct = null;
            foreach ($c->lessons as $l) {
                $p = $progressByLesson[$l->id] ?? null;
                if ($p) {
                    if ($p->completed_at) $done++;
                    if (!$startedAt || ($p->started_at && $p->started_at < $startedAt)) $startedAt = $p->started_at;
                    if (!$lastAct || ($p->updated_at && $p->updated_at > $lastAct)) $lastAct = $p->updated_at;
                }
            }
            $pct = $totalLessons > 0 ? (int) round(($done / $totalLessons) * 100) : 0;
            $allDone = $totalLessons > 0 && $done >= $totalLessons;
            $qb = $quizByCourse[$c->id] ?? null;
            $ct = $certs[$c->id] ?? null;

            return [
                'course_id'              => $c->id,
                'title'                  => $c->title,
                'description'            => $c->description,
                'cover_image'            => $c->cover_image,
                'category'               => $c->category ? ['name' => $c->category->name, 'color' => $c->category->color] : null,
                'lessons_total'          => $totalLessons,
                'lessons_completed'      => $done,
                'progress_pct'           => $pct,
                'is_completed'           => $allDone,
                'started_at'             => $startedAt?->toIso8601String(),
                'last_activity_at'       => $lastAct?->toIso8601String(),
                'has_quiz'               => (bool) $c->has_quiz,
                'quiz_min_score'         => (int) $c->quiz_min_score,
                'best_quiz_score'        => $qb ? (int) $qb->best_score : null,
                'quiz_passed'            => $qb ? (bool) $qb->any_passed : false,
                'quiz_attempts'          => $qb ? (int) $qb->attempts : 0,
                'certificate_enabled'    => (bool) $c->certificate_enabled,
                'has_certificate'        => $ct !== null,
                'certificate_number'     => $ct?->certificate_number,
                'certificate_issued_at'  => $ct?->issued_at?->toIso8601String(),
            ];
        })->sortByDesc('last_activity_at')->values();

        return response()->json($payload);
    }


    public function myStats()
    {
        $userId = Auth::id();

        $totalCourses = AcademyCourse::where('published', true)->count();

        $myCompletedLessons = AcademyUserProgress::where('user_id', $userId)
            ->whereNotNull('completed_at')
            ->pluck('lesson_id');

        $courseIds = AcademyLesson::whereIn('id', $myCompletedLessons)->pluck('course_id')->unique();
        $coursesCompleted = 0;
        foreach ($courseIds as $cId) {
            $course = AcademyCourse::find($cId);
            if (!$course) continue;
            $totalL = $course->lessons()->count();
            $doneL = AcademyUserProgress::where('user_id', $userId)
                ->whereIn('lesson_id', $course->lessons()->pluck('id'))
                ->whereNotNull('completed_at')
                ->count();
            if ($totalL > 0 && $doneL === $totalL) $coursesCompleted++;
        }

        return response()->json([
            'total_courses_available' => $totalCourses,
            'courses_completed'       => $coursesCompleted,
            'lessons_completed'       => $myCompletedLessons->count(),
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\AcademyCategory;
use App\Models\AcademyCertificate;
use App\Models\AcademyCourse;
use App\Models\AcademyLesson;
use App\Models\AcademyLessonMaterial;
use App\Models\AcademyQuizAttempt;
use App\Models\AcademyUserProgress;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// CRUD do Academy pra admin/gestor. Rotas em /admin/academy/* gateadas pela permission no router.
class AcademyAdminController extends Controller
{

    public function indexCategories()
    {
        return AcademyCategory::orderBy('order')->orderBy('name')->get();
    }

    public function storeCategory(Request $request)
    {
        $data = $request->validate([
            'name'  => 'required|string|max:80',
            'color' => 'nullable|string|max:16',
            'order' => 'nullable|integer|min:0',
        ]);
        $data['slug']  = $this->uniqueSlug(AcademyCategory::class, $data['name']);
        $data['order'] = $data['order'] ?? 0;
        return AcademyCategory::create($data);
    }

    public function updateCategory(Request $request, AcademyCategory $category)
    {
        $data = $request->validate([
            'name'  => 'sometimes|string|max:80',
            'color' => 'nullable|string|max:16',
            'order' => 'nullable|integer|min:0',
        ]);
        $category->update($data);
        return $category->fresh();
    }

    public function destroyCategory(AcademyCategory $category)
    {
        $category->delete();
        return response()->json(['success' => true]);
    }


    public function indexCourses(Request $request)
    {
        $q = AcademyCourse::with('category', 'lessons');
        if ($request->filled('category_id')) {
            $q->where('category_id', (int) $request->category_id);
        }
        return $q->orderBy('order')->orderBy('title')->get();
    }

    public function showCourse(AcademyCourse $course)
    {
        return $course->load('category', 'lessons.materials', 'quizQuestions');
    }

    public function storeCourse(Request $request)
    {
        $data = $this->validateCourse($request);
        return AcademyCourse::create($data);
    }

    public function updateCourse(Request $request, AcademyCourse $course)
    {
        $data = $this->validateCourse($request, true);
        $course->update($data);
        return $course->fresh(['category', 'lessons.materials']);
    }

    public function destroyCourse(AcademyCourse $course)
    {

        foreach ($course->lessons as $lesson) {
            $this->deleteLessonFiles($lesson);
        }
        $course->delete();
        return response()->json(['success' => true]);
    }

    public function uploadCourseCover(Request $request, AcademyCourse $course)
    {
        $request->validate(['cover' => 'required|image|max:4096']);
        $path = $request->file('cover')->store('academy/covers', 'public');

        if ($course->cover_image && Storage::disk('public')->exists($course->cover_image)) {
            try { Storage::disk('public')->delete($course->cover_image); } catch (\Throwable $e) {}
        }
        $course->update(['cover_image' => $path]);
        return response()->json([
            'cover_image'     => $path,
            'cover_image_url' => Storage::url($path),
        ]);
    }

    public function uploadCourseBanner(Request $request, AcademyCourse $course)
    {
        $request->validate(['banner' => 'required|image|max:4096']);
        $path = $request->file('banner')->store('academy/banners', 'public');

        if ($course->cover_banner && Storage::disk('public')->exists($course->cover_banner)) {
            try { Storage::disk('public')->delete($course->cover_banner); } catch (\Throwable $e) {}
        }
        $course->update(['cover_banner' => $path]);
        return response()->json([
            'cover_banner'     => $path,
            'cover_banner_url' => Storage::url($path),
        ]);
    }


    public function storeLesson(Request $request, AcademyCourse $course)
    {
        $data = $request->validate([
            'title'            => 'required|string|max:200',
            'description'      => 'nullable|string',
            'duration_seconds' => 'nullable|integer|min:0',
            'order'            => 'nullable|integer|min:0',
        ]);
        $data['course_id'] = $course->id;
        $data['video_path'] = '';
        $data['order'] = $data['order'] ?? ($course->lessons()->max('order') + 1);
        return AcademyLesson::create($data);
    }

    public function updateLesson(Request $request, AcademyLesson $lesson)
    {
        $data = $request->validate([
            'title'            => 'sometimes|string|max:200',
            'description'      => 'nullable|string',
            'duration_seconds' => 'nullable|integer|min:0',
            'order'            => 'nullable|integer|min:0',
        ]);
        $lesson->update($data);
        return $lesson->fresh('materials');
    }

    public function destroyLesson(AcademyLesson $lesson)
    {
        $this->deleteLessonFiles($lesson);
        $lesson->delete();
        return response()->json(['success' => true]);
    }


    public function uploadLessonVideo(Request $request, AcademyLesson $lesson)
    {
        // 512 MB em KB. Lembrar de bater com upload_max_filesize e client_max_body_size do nginx.
        $request->validate([
            'video' => 'required|file|mimes:mp4,webm,mov,m4v|max:512000',
        ]);

        $file = $request->file('video');
        $path = $file->store('academy/videos/' . $lesson->course_id, 'public');


        if ($lesson->video_path && Storage::disk('public')->exists($lesson->video_path)) {
            try { Storage::disk('public')->delete($lesson->video_path); } catch (\Throwable $e) {}
        }

        $lesson->update(['video_path' => $path]);

        return response()->json([
            'video_path' => $path,
            'video_url'  => Storage::url($path),
            'size_mb'    => round($file->getSize() / 1024 / 1024, 2),
        ]);
    }


    public function uploadMaterial(Request $request, AcademyLesson $lesson)
    {
        $request->validate([
            'file' => 'required|file|max:51200',
            'name' => 'nullable|string|max:200',
        ]);

        $file = $request->file('file');
        $path = $file->store('academy/materials/' . $lesson->course_id, 'public');

        $material = AcademyLessonMaterial::create([
            'lesson_id'       => $lesson->id,
            'name'            => $request->input('name') ?: $file->getClientOriginalName(),
            'file_path'       => $path,
            'file_size_bytes' => $file->getSize(),
            'mime_type'       => $file->getClientMimeType(),
        ]);

        return response()->json([
            'id'        => $material->id,
            'name'      => $material->name,
            'file_path' => $material->file_path,
            'file_url'  => Storage::url($material->file_path),
            'size_bytes'=> $material->file_size_bytes,
        ]);
    }

    public function destroyMaterial(AcademyLessonMaterial $material)
    {
        if ($material->file_path && Storage::disk('public')->exists($material->file_path)) {
            try { Storage::disk('public')->delete($material->file_path); } catch (\Throwable $e) {}
        }
        $material->delete();
        return response()->json(['success' => true]);
    }



    // Histórico geral. Lista pares (user, curso) onde o user tem qualquer progresso, com agregados.
    // Filtros: course_id, user_id, status (in_progress|completed|certified), q (busca em nome/email).
    public function indexEnrollments(Request $request)
    {
        $courseId = $request->filled('course_id') ? (int) $request->course_id : null;
        $userId   = $request->filled('user_id')   ? (int) $request->user_id   : null;
        $status   = $request->input('status');
        $q        = trim((string) $request->input('q', ''));


        // Base: agrega progresso por (user_id, course_id) via join lessons → user_progress.
        $base = DB::table('academy_user_progress as p')
            ->join('academy_lessons as l', 'l.id', '=', 'p.lesson_id')
            ->join('academy_courses as c', 'c.id', '=', 'l.course_id')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->leftJoin('academy_categories as cat', 'cat.id', '=', 'c.category_id')
            ->select(
                'u.id as user_id',
                'u.name as user_name',
                'u.email as user_email',
                'c.id as course_id',
                'c.title as course_title',
                'c.has_quiz',
                'c.quiz_min_score',
                'c.certificate_enabled',
                'cat.name as category_name',
                'cat.color as category_color',
                DB::raw('MIN(p.started_at) as started_at'),
                DB::raw('MAX(p.updated_at) as last_activity_at'),
                DB::raw('COUNT(DISTINCT p.lesson_id) as lessons_started'),
                DB::raw('SUM(CASE WHEN p.completed_at IS NOT NULL THEN 1 ELSE 0 END) as lessons_completed')
            )
            ->groupBy(
                'u.id', 'u.name', 'u.email',
                'c.id', 'c.title', 'c.has_quiz', 'c.quiz_min_score', 'c.certificate_enabled',
                'cat.name', 'cat.color'
            );

        if ($courseId) $base->where('c.id', $courseId);
        if ($userId)   $base->where('u.id', $userId);
        if ($q !== '') {
            $base->where(function ($w) use ($q) {
                $w->where('u.name', 'like', "%{$q}%")
                  ->orWhere('u.email', 'like', "%{$q}%")
                  ->orWhere('c.title', 'like', "%{$q}%");
            });
        }

        $rows = $base->orderByDesc(DB::raw('MAX(p.updated_at)'))->get();


        // Total de aulas por curso pra calcular % e flag de concluído.
        $courseIds = $rows->pluck('course_id')->unique()->all();
        $totalsByCourse = DB::table('academy_lessons')
            ->select('course_id', DB::raw('COUNT(*) as total'))
            ->whereIn('course_id', $courseIds)
            ->groupBy('course_id')
            ->pluck('total', 'course_id')
            ->all();


        // Melhor tentativa de quiz por (user, course).
        $quizBest = AcademyQuizAttempt::whereIn('course_id', $courseIds)
            ->select('user_id', 'course_id', DB::raw('MAX(score) as best_score'), DB::raw('MAX(passed) as any_passed'))
            ->groupBy('user_id', 'course_id')
            ->get()
            ->keyBy(fn($r) => $r->user_id . '_' . $r->course_id);


        // Certificados emitidos.
        $certs = AcademyCertificate::whereIn('course_id', $courseIds)
            ->get()
            ->keyBy(fn($c) => $c->user_id . '_' . $c->course_id);

        $payload = $rows->map(function ($r) use ($totalsByCourse, $quizBest, $certs) {
            $totalLessons = (int) ($totalsByCourse[$r->course_id] ?? 0);
            $done = (int) $r->lessons_completed;
            $allDone = $totalLessons > 0 && $done >= $totalLessons;
            $key = $r->user_id . '_' . $r->course_id;
            $qb = $quizBest[$key] ?? null;
            $ct = $certs[$key] ?? null;

            $isCertified = $ct !== null;
            $status = $isCertified ? 'certified'
                : ($allDone ? ($r->has_quiz && !($qb && $qb->any_passed) ? 'completed' : 'completed') : 'in_progress');

            return [
                'user_id'                => (int) $r->user_id,
                'user_name'              => $r->user_name,
                'user_email'             => $r->user_email,
                'course_id'              => (int) $r->course_id,
                'course_title'           => $r->course_title,
                'category_name'          => $r->category_name,
                'category_color'         => $r->category_color,
                'started_at'             => $r->started_at,
                'last_activity_at'       => $r->last_activity_at,
                'lessons_total'          => $totalLessons,
                'lessons_completed'      => $done,
                'progress_pct'           => $totalLessons > 0 ? (int) round(($done / $totalLessons) * 100) : 0,
                'is_completed'           => $allDone,
                'has_quiz'               => (bool) $r->has_quiz,
                'quiz_min_score'         => (int) $r->quiz_min_score,
                'best_quiz_score'        => $qb ? (int) $qb->best_score : null,
                'quiz_passed'            => $qb ? (bool) $qb->any_passed : false,
                'certificate_enabled'    => (bool) $r->certificate_enabled,
                'has_certificate'        => $isCertified,
                'certificate_number'     => $ct?->certificate_number,
                'certificate_issued_at'  => $ct?->issued_at?->toIso8601String(),
                'status'                 => $status,
            ];
        });


        // Filtra por status no lado do PHP (mais simples que reescrever o group-by).
        if ($status === 'in_progress') {
            $payload = $payload->filter(fn($r) => !$r['is_completed']);
        } elseif ($status === 'completed') {
            $payload = $payload->filter(fn($r) => $r['is_completed'] && !$r['has_certificate']);
        } elseif ($status === 'certified') {
            $payload = $payload->filter(fn($r) => $r['has_certificate']);
        }

        return response()->json($payload->values());
    }



    // Drill-down: tudo que o usuário fez naquele curso. Inclui aula a aula + tentativa de quiz com gabarito.
    public function userCourseDetails(Request $request, int $userId, int $courseId)
    {
        $user = User::findOrFail($userId);
        $course = AcademyCourse::with(['category', 'lessons.materials', 'quizQuestions'])->findOrFail($courseId);

        $progressByLesson = AcademyUserProgress::where('user_id', $userId)
            ->whereIn('lesson_id', $course->lessons->pluck('id'))
            ->get()
            ->keyBy('lesson_id');

        $lessonsPayload = $course->lessons->map(function ($l) use ($progressByLesson) {
            $p = $progressByLesson[$l->id] ?? null;
            return [
                'id'                 => $l->id,
                'title'              => $l->title,
                'order'              => $l->order,
                'duration_seconds'   => $l->duration_seconds,
                'started_at'         => $p?->started_at?->toIso8601String(),
                'completed_at'       => $p?->completed_at?->toIso8601String(),
                'completed_via'      => $p?->completed_via,
                'watch_seconds'      => (int) ($p?->watch_seconds ?? 0),
                'last_position'      => (int) ($p?->last_position_seconds ?? 0),
                'last_seen_at'       => $p?->updated_at?->toIso8601String(),
            ];
        });


        // Tentativas de quiz com gabarito embutido (qual opção foi escolhida vs correta).
        $questionsById = $course->quizQuestions->keyBy('id');

        $attempts = AcademyQuizAttempt::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->orderByDesc('attempted_at')
            ->get()
            ->map(function ($a) use ($questionsById) {
                $detail = collect($a->answers ?? [])->map(function ($ans) use ($questionsById) {
                    $qid = $ans['question_id'] ?? null;
                    $q = $qid ? ($questionsById[$qid] ?? null) : null;
                    if (!$q) {
                        return [
                            'question_id'    => $qid,
                            'question'       => '(pergunta removida)',
                            'options'        => [],
                            'chosen_index'   => $ans['choice'] ?? null,
                            'chosen_text'    => null,
                            'correct_index'  => null,
                            'correct_text'   => null,
                            'is_correct'     => false,
                        ];
                    }
                    $chosen = $ans['choice'] ?? null;
                    $opts = $q->options ?? [];
                    return [
                        'question_id'    => $q->id,
                        'question'       => $q->question,
                        'options'        => $opts,
                        'chosen_index'   => $chosen,
                        'chosen_text'    => is_int($chosen) ? ($opts[$chosen] ?? null) : null,
                        'correct_index'  => $q->correct_index,
                        'correct_text'   => $opts[$q->correct_index] ?? null,
                        'is_correct'     => is_int($chosen) && $chosen === (int) $q->correct_index,
                    ];
                });
                return [
                    'id'             => $a->id,
                    'attempted_at'   => $a->attempted_at?->toIso8601String(),
                    'score'          => (int) $a->score,
                    'passed'         => (bool) $a->passed,
                    'total'          => $detail->count(),
                    'correct_count'  => $detail->where('is_correct', true)->count(),
                    'answers'        => $detail->values(),
                ];
            });


        $cert = AcademyCertificate::where('user_id', $userId)->where('course_id', $courseId)->first();

        return response()->json([
            'user' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
            'course' => [
                'id'                  => $course->id,
                'title'               => $course->title,
                'description'         => $course->description,
                'cover_image'         => $course->cover_image,
                'category'            => $course->category ? ['name' => $course->category->name, 'color' => $course->category->color] : null,
                'has_quiz'            => (bool) $course->has_quiz,
                'quiz_min_score'      => (int) $course->quiz_min_score,
                'certificate_enabled' => (bool) $course->certificate_enabled,
            ],
            'lessons'     => $lessonsPayload,
            'attempts'    => $attempts,
            'certificate' => $cert ? [
                'number'    => $cert->certificate_number,
                'issued_at' => $cert->issued_at?->toIso8601String(),
            ] : null,
        ]);
    }


    // Snapshot agregado pro topo da tela: contadores rápidos de adesão.
    public function enrollmentsSummary()
    {
        $totalCourses = AcademyCourse::where('published', true)->count();
        $usersWithProgress = DB::table('academy_user_progress')->distinct('user_id')->count('user_id');
        $totalCertificates = AcademyCertificate::count();
        $totalCompletions = DB::table('academy_user_progress')->whereNotNull('completed_at')->count();

        return response()->json([
            'published_courses'     => $totalCourses,
            'users_with_progress'   => $usersWithProgress,
            'lessons_completed'     => $totalCompletions,
            'certificates_issued'   => $totalCertificates,
        ]);
    }


    private function validateCourse(Request $request, bool $partial = false): array
    {
        $rule = $partial ? 'sometimes|' : 'required|';
        return $request->validate([
            'category_id'         => 'nullable|exists:academy_categories,id',
            'title'               => $rule . 'string|max:200',
            'description'         => 'nullable|string',
            'published'           => 'nullable|boolean',
            'order'               => 'nullable|integer|min:0',
            'has_quiz'            => 'nullable|boolean',
            'quiz_min_score'      => 'nullable|integer|min:0|max:100',
            'certificate_enabled' => 'nullable|boolean',
        ]);
    }

    private function deleteLessonFiles(AcademyLesson $lesson): void
    {
        if ($lesson->video_path && Storage::disk('public')->exists($lesson->video_path)) {
            try { Storage::disk('public')->delete($lesson->video_path); } catch (\Throwable $e) {}
        }
        foreach ($lesson->materials as $m) {
            if ($m->file_path && Storage::disk('public')->exists($m->file_path)) {
                try { Storage::disk('public')->delete($m->file_path); } catch (\Throwable $e) {}
            }
        }
    }

    private function uniqueSlug(string $modelClass, string $name): string
    {
        $base = Str::slug($name);
        if (!$base) $base = 'cat';
        $slug = $base;
        $i = 2;
        while ($modelClass::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }
}

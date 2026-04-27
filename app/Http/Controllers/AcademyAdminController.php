<?php

namespace App\Http\Controllers;

use App\Models\AcademyCategory;
use App\Models\AcademyCourse;
use App\Models\AcademyLesson;
use App\Models\AcademyLessonMaterial;
use Illuminate\Http\Request;
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

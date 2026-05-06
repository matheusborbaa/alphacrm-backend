<?php

namespace App\Http\Controllers;

use App\Models\AcademyCertificate;
use App\Models\AcademyCourse;
use App\Models\AcademyQuizAttempt;
use App\Models\AcademyUserProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// Renderiza HTML com auto-print no lugar de gerar PDF — sai vetorial e poupa dependência de lib.
class AcademyCertificateController extends Controller
{
    public function download(AcademyCourse $course)
    {
        abort_unless($course->published, 404);
        abort_unless($course->certificate_enabled, 422, 'Esse curso não emite certificado.');

        $user = Auth::user();


        $lessonIds = $course->lessons()->pluck('id');
        if ($lessonIds->isEmpty()) {
            abort(422, 'O curso não tem aulas pra concluir.');
        }

        $doneLessons = AcademyUserProgress::where('user_id', $user->id)
            ->whereIn('lesson_id', $lessonIds)
            ->whereNotNull('completed_at')
            ->count();

        if ($doneLessons < $lessonIds->count()) {
            abort(422, 'Você precisa concluir todas as aulas antes de baixar o certificado.');
        }


        if ($course->has_quiz) {
            $passed = AcademyQuizAttempt::where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->where('passed', true)
                ->exists();
            if (!$passed) {
                abort(422, 'Você precisa passar no quiz pra baixar o certificado.');
            }
        }


        $cert = AcademyCertificate::firstOrCreate(
            ['user_id' => $user->id, 'course_id' => $course->id],
            [
                'certificate_number' => $this->generateNumber(),
                'issued_at'          => now(),
            ]
        );


        return response()->view('academy.certificate', [
            'cert'      => $cert,
            'user'      => $user,
            'course'    => $course,
            'companyName' => \App\Models\Setting::get('company_name', 'AlphaCRM'),
        ])->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function generateNumber(): string
    {
        do {
            $candidate = 'AC-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
        } while (AcademyCertificate::where('certificate_number', $candidate)->exists());
        return $candidate;
    }
}

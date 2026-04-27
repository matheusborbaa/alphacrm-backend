<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I3 — Academy.
 *
 * Sistema de cursos internos com vídeos, quiz, materiais e certificado.
 *
 *   academy_categories      — agrupamento de cursos (Vendas, Atendimento, etc)
 *   academy_courses         — curso (título, descrição, capa, categoria)
 *   academy_lessons         — aula dentro do curso (vídeo + descrição)
 *   academy_lesson_materials— PDFs/arquivos anexos a uma aula
 *   academy_quiz_questions  — perguntas múltipla escolha (no nível do curso)
 *   academy_user_progress   — uma row por (user, lesson) com watch_seconds
 *   academy_quiz_attempts   — tentativas de quiz por (user, course)
 *   academy_certificates    — certificados emitidos (user, course, número)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academy_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 80)->unique();
            $table->string('color', 16)->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();
        });

        Schema::create('academy_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('academy_categories')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('cover_image')->nullable();
            $table->boolean('published')->default(false);
            $table->integer('order')->default(0);


            $table->boolean('has_quiz')->default(false);
            $table->integer('quiz_min_score')->default(70);


            $table->boolean('certificate_enabled')->default(true);

            $table->timestamps();
            $table->index(['published', 'order']);
        });

        Schema::create('academy_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('academy_courses')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('video_path');
            $table->integer('duration_seconds')->default(0);
            $table->integer('order')->default(0);
            $table->timestamps();
            $table->index(['course_id', 'order']);
        });

        Schema::create('academy_lesson_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('academy_lessons')->cascadeOnDelete();
            $table->string('name');
            $table->string('file_path');
            $table->integer('file_size_bytes')->default(0);
            $table->string('mime_type', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('academy_quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('academy_courses')->cascadeOnDelete();
            $table->text('question');
            $table->json('options');
            $table->integer('correct_index');
            $table->integer('order')->default(0);
            $table->timestamps();
            $table->index(['course_id', 'order']);
        });

        Schema::create('academy_user_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('academy_lessons')->cascadeOnDelete();
            $table->integer('watch_seconds')->default(0);
            $table->integer('last_position_seconds')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('completed_via', 20)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'lesson_id'], 'academy_progress_unique');
            $table->index('completed_at');
        });

        Schema::create('academy_quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('academy_courses')->cascadeOnDelete();
            $table->json('answers');
            $table->integer('score');
            $table->boolean('passed')->default(false);
            $table->timestamp('attempted_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'course_id']);
        });

        Schema::create('academy_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('academy_courses')->cascadeOnDelete();
            $table->string('certificate_number', 32)->unique();
            $table->timestamp('issued_at');
            $table->string('pdf_path')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'course_id'], 'academy_cert_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academy_certificates');
        Schema::dropIfExists('academy_quiz_attempts');
        Schema::dropIfExists('academy_user_progress');
        Schema::dropIfExists('academy_quiz_questions');
        Schema::dropIfExists('academy_lesson_materials');
        Schema::dropIfExists('academy_lessons');
        Schema::dropIfExists('academy_courses');
        Schema::dropIfExists('academy_categories');
    }
};

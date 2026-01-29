<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('certifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('lab'); // GIA, AGS, IGI, EGL, HRD, etc.
            $table->string('certificate_number')->unique();
            $table->date('issue_date')->nullable();
            $table->string('report_type')->nullable(); // Diamond Grading Report, Diamond Dossier, etc.
            $table->string('shape')->nullable();
            $table->decimal('carat_weight', 8, 3)->nullable();
            $table->string('color_grade')->nullable();
            $table->string('clarity_grade')->nullable();
            $table->string('cut_grade')->nullable();
            $table->string('polish')->nullable();
            $table->string('symmetry')->nullable();
            $table->string('fluorescence')->nullable();
            $table->json('measurements')->nullable();
            $table->json('proportions')->nullable();
            $table->string('inscription')->nullable();
            $table->text('comments')->nullable();
            $table->string('verification_url')->nullable();
            $table->string('pdf_path')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'lab']);
            $table->index('certificate_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('certifications');
    }
};

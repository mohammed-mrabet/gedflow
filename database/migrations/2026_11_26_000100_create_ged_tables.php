<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Types de documents et leur circuit de validation (étapes ordonnées).
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();          // préfixe des références : FAC, CTR…
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('retention_years')->default(10); // durée de conservation légale
            $table->string('default_confidentiality', 20)->default('department');
            $table->timestamps();
        });

        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name');
            // Service dont les valideurs traitent l'étape ; null = service émetteur du document.
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('due_hours')->default(48); // délai de traitement (SLA)
            $table->timestamps();
            $table->unique(['document_type_id', 'position']);
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();     // FAC-2027-00012
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('draft')->index();
            $table->string('confidentiality', 20)->default('department');
            $table->json('tags')->nullable();
            $table->unsignedSmallInteger('review_round')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->date('retention_until')->nullable()->index();
            // Texte indexé : titre, référence, mots-clés, description et contenu de la version courante.
            $table->longText('search_text')->nullable();
            $table->timestamps();
            $table->index(['department_id', 'status']);
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64)->index();            // empreinte pour vérifier l'intégrité
            $table->string('comment', 500)->nullable();
            $table->longText('extracted_text')->nullable();
            $table->timestamp('text_extracted_at')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['document_id', 'number']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('current_version_id')->nullable()->after('owner_id')
                ->constrained('document_versions')->nullOnDelete();
        });

        // Tâches de validation : une ligne par étape et par tour de validation.
        Schema::create('review_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('round');
            $table->unsignedSmallInteger('position');
            $table->string('step_name');                      // copie : modifier le circuit ne réécrit pas l'historique
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('due_at');
            $table->timestamp('reminded_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'round', 'position']);
            $table->index(['status', 'due_at']);
        });

        // Partage d'un document confidentiel avec des personnes précises.
        Schema::create('document_shares', function (Blueprint $table) {
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shared_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->primary(['document_id', 'user_id']);
        });

        // Journal d'audit : lignes ajoutées, jamais modifiées.
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40)->index();
            $table->json('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->index();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        // Recherche plein texte native sur PostgreSQL (index GIN, dictionnaire français).
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('documents', function (Blueprint $table) {
                $table->fullText('search_text')->language('french');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('document_shares');
        Schema::dropIfExists('review_tasks');
        Schema::table('documents', fn (Blueprint $table) => $table->dropConstrainedForeignId('current_version_id'));
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('document_types');
    }
};

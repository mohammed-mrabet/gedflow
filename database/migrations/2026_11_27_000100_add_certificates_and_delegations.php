<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Certificat de validation : PDF généré à l'approbation, vérifiable par QR code.
        Schema::table('documents', function (Blueprint $table) {
            $table->string('verification_token', 40)->nullable()->unique();
            $table->string('certificate_path')->nullable();
            $table->timestamp('certificate_generated_at')->nullable();
        });

        // Responsable de service : destinataire des escalades.
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('review_tasks', function (Blueprint $table) {
            // Visa donné par un remplaçant : on garde le valideur absent « pour le compte duquel » il a décidé.
            $table->foreignId('decided_on_behalf_of')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('escalated_at')->nullable();
        });

        // Délégation pendant une absence : le remplaçant traite les étapes du service du valideur absent.
        Schema::create('delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delegator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delegate_id')->constrained('users')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('reason', 200)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['delegate_id', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delegations');
        Schema::table('review_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decided_on_behalf_of');
            $table->dropColumn('escalated_at');
        });
        Schema::table('departments', fn (Blueprint $table) => $table->dropConstrainedForeignId('manager_id'));
        Schema::table('documents', fn (Blueprint $table) => $table->dropColumn(['verification_token', 'certificate_path', 'certificate_generated_at']));
    }
};

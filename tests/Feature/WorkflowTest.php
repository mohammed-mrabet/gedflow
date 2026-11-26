<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\DocumentType;
use App\Models\User;
use App\Notifications\DocumentDecided;
use App\Notifications\ReviewOverdue;
use App\Notifications\ReviewRequested;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    public function test_un_document_suit_toutes_les_etapes_puis_est_approuve(): void
    {
        Notification::fake();
        $document = $this->draft();

        $this->actingAs($this->author)->post("/documents/{$document->id}/soumettre")->assertSessionHasNoErrors();
        $first = $this->pendingTask($document);
        $this->assertSame('Visa du service demandeur', $first->step_name);
        $this->assertSame($this->purchasing->id, $first->department_id); // étape « service émetteur »
        Notification::assertSentTo($this->buyerValidator, ReviewRequested::class);
        Notification::assertNotSentTo($this->author, ReviewRequested::class);

        $this->actingAs($this->buyerValidator)->post("/validations/{$first->id}/decision", ['decision' => 'approve'])->assertSessionHasNoErrors();
        $second = $this->pendingTask($document);
        $this->assertSame($this->finance->id, $second->department_id);
        Notification::assertSentTo($this->financeValidator, ReviewRequested::class);

        $this->actingAs($this->financeValidator)->post("/validations/{$second->id}/decision", ['decision' => 'approve', 'comment' => 'OK'])->assertSessionHasNoErrors();

        $document->refresh();
        $this->assertSame(DocumentStatus::Approved, $document->status);
        $this->assertSame(now()->addYears(10)->toDateString(), $document->retention_until->toDateString());
        Notification::assertSentTo($this->author, DocumentDecided::class, fn ($n) => $n->approved);
        $this->assertSame(['created', 'version_uploaded', 'submitted', 'step_approved', 'step_approved', 'approved'],
            $document->activities()->reorder('id')->pluck('action')->all());
    }

    public function test_un_rejet_exige_un_motif_puis_une_nouvelle_version_relance_un_second_tour(): void
    {
        Notification::fake();
        $document = $this->draft();
        app(WorkflowService::class)->submit($document, $this->author);
        $task = $this->pendingTask($document);

        $this->actingAs($this->buyerValidator)->post("/validations/{$task->id}/decision", ['decision' => 'reject'])
            ->assertSessionHasErrors('comment');
        $this->actingAs($this->buyerValidator)->post("/validations/{$task->id}/decision", ['decision' => 'reject', 'comment' => 'Montant erroné'])
            ->assertSessionHasNoErrors();
        $this->assertSame(DocumentStatus::Rejected, $document->fresh()->status);
        Notification::assertSentTo($this->author, DocumentDecided::class, fn ($n) => ! $n->approved && $n->comment === 'Montant erroné');

        $this->actingAs($this->author)->post("/documents/{$document->id}/versions", ['file' => $this->pdf('corrigée', 'v2.pdf'), 'comment' => 'Montant corrigé'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->author)->post("/documents/{$document->id}/soumettre")->assertSessionHasNoErrors();

        $document->refresh();
        $this->assertSame(2, $document->review_round);
        $round2 = $this->pendingTask($document);
        $this->assertSame(2, $round2->round);
        $this->assertSame(2, $round2->version->number); // la tâche porte sur la version corrigée
        $this->assertSame(TaskStatus::Rejected, $document->tasks()->where('round', 1)->first()->status); // historique conservé
    }

    public function test_l_auteur_ne_peut_pas_viser_son_propre_document(): void
    {
        $authorValidator = User::factory()->role(Role::Validator, $this->purchasing)->create();
        $document = $this->draft(owner: $authorValidator);
        app(WorkflowService::class)->submit($document, $authorValidator);
        $task = $this->pendingTask($document);

        $this->actingAs($authorValidator)->post("/validations/{$task->id}/decision", ['decision' => 'approve'])->assertForbidden();
        $this->assertSame(TaskStatus::Pending, $task->fresh()->status);
    }

    public function test_seuls_les_valideurs_du_service_de_l_etape_peuvent_decider(): void
    {
        $document = $this->draft();
        app(WorkflowService::class)->submit($document, $this->author);
        $task = $this->pendingTask($document); // étape du service Achats

        $this->actingAs($this->financeValidator)->post("/validations/{$task->id}/decision", ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($this->admin)->post("/validations/{$task->id}/decision", ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($this->author)->post("/validations/{$task->id}/decision", ['decision' => 'approve'])->assertForbidden();
    }

    public function test_une_etape_deja_traitee_ne_peut_pas_etre_decidee_deux_fois(): void
    {
        $document = $this->draft();
        app(WorkflowService::class)->submit($document, $this->author);
        $task = $this->pendingTask($document);
        $other = User::factory()->role(Role::Validator, $this->purchasing)->create();

        $this->actingAs($this->buyerValidator)->post("/validations/{$task->id}/decision", ['decision' => 'approve']);
        $this->actingAs($other)->post("/validations/{$task->id}/decision", ['decision' => 'reject', 'comment' => 'trop tard'])->assertForbidden();
        $this->assertSame(TaskStatus::Approved, $task->fresh()->status);
    }

    public function test_pas_de_nouvelle_version_pendant_la_validation_mais_retrait_possible(): void
    {
        $document = $this->draft();
        app(WorkflowService::class)->submit($document, $this->author);

        $this->actingAs($this->author)->post("/documents/{$document->id}/versions", ['file' => $this->pdf()])->assertForbidden();

        $this->actingAs($this->author)->post("/documents/{$document->id}/retirer")->assertSessionHasNoErrors();
        $this->assertSame(DocumentStatus::Draft, $document->fresh()->status);
        $this->assertSame(TaskStatus::Cancelled, $document->tasks()->first()->status);
    }

    public function test_un_type_sans_circuit_est_approuve_des_la_soumission(): void
    {
        $type = DocumentType::create(['code' => 'INF', 'name' => 'Information', 'retention_years' => 3, 'default_confidentiality' => 'internal']);
        $document = $this->draft(['document_type_id' => $type->id]);

        app(WorkflowService::class)->submit($document, $this->author);

        $this->assertSame(DocumentStatus::Approved, $document->fresh()->status);
        $this->assertSame(0, $document->tasks()->count());
    }

    public function test_les_etapes_en_retard_sont_relancees_une_fois_par_jour(): void
    {
        Notification::fake();
        $document = $this->draft();
        app(WorkflowService::class)->submit($document, $this->author);

        $this->travel(25)->hours(); // délai de 24 h dépassé
        $this->artisan('ged:remind-overdue')->expectsOutputToContain('1 étape(s)')->assertSuccessful();
        Notification::assertSentToTimes($this->buyerValidator, ReviewOverdue::class, 1);

        $this->travel(2)->hours();
        $this->artisan('ged:remind-overdue')->expectsOutputToContain('0 étape(s)');
        $this->travel(1)->days();
        $this->artisan('ged:remind-overdue')->expectsOutputToContain('1 étape(s)');
        Notification::assertSentToTimes($this->buyerValidator, ReviewOverdue::class, 2);
    }

    public function test_seul_un_document_approuve_peut_etre_archive_par_l_administrateur(): void
    {
        $document = $this->draft();
        $this->actingAs($this->admin)->post("/documents/{$document->id}/archiver")->assertForbidden();

        $type = DocumentType::create(['code' => 'INF', 'name' => 'Information', 'retention_years' => 3, 'default_confidentiality' => 'internal']);
        $approved = $this->draft(['document_type_id' => $type->id]);
        app(WorkflowService::class)->submit($approved, $this->author);

        $this->actingAs($this->author)->post("/documents/{$approved->id}/archiver")->assertForbidden();
        $this->actingAs($this->admin)->post("/documents/{$approved->id}/archiver")->assertSessionHasNoErrors();
        $this->assertSame(DocumentStatus::Archived, $approved->fresh()->status);
    }
}

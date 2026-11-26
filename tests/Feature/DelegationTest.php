<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\Delegation;
use App\Models\ReviewTask;
use App\Models\User;
use App\Notifications\ReviewEscalated;
use App\Notifications\ReviewRequested;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DelegationTest extends TestCase
{
    private User $deputy; // contributeur du service Juridique, remplaçant de Karim (Achats)

    protected function setUp(): void
    {
        parent::setUp();
        $this->deputy = User::factory()->role(Role::Contributor, $this->legal)->create(['name' => 'Youssef']);
    }

    private function delegate(string $from = 'today', string $to = '+5 days'): void
    {
        $this->actingAs($this->buyerValidator)->post('/absences', [
            'delegate_id' => $this->deputy->id,
            'starts_on' => now()->modify($from)->toDateString(),
            'ends_on' => now()->modify($to)->toDateString(),
            'reason' => 'Congés',
        ])->assertSessionHasNoErrors();
    }

    public function test_le_remplacant_vise_pour_le_compte_du_valideur_absent(): void
    {
        Notification::fake();
        $this->delegate();
        $document = $this->draft();
        app(WorkflowService::class)->submit($document, $this->author);
        $task = $this->pendingTask($document);

        Notification::assertSentTo($this->deputy, ReviewRequested::class);
        $this->assertTrue(ReviewTask::actionableBy($this->deputy)->whereKey($task->id)->exists());
        $this->actingAs($this->deputy)->get("/documents/{$document->id}")->assertOk(); // il voit le document à viser

        $this->actingAs($this->deputy)->post("/validations/{$task->id}/decision", ['decision' => 'approve'])->assertSessionHasNoErrors();

        $task->refresh();
        $this->assertSame(TaskStatus::Approved, $task->status);
        $this->assertSame($this->deputy->id, $task->decided_by);
        $this->assertSame($this->buyerValidator->id, $task->decided_on_behalf_of);
        $this->assertSame('Karim', $document->activities()->where('action', 'step_approved')->first()->details['on_behalf_of']);
    }

    public function test_sans_delegation_active_le_remplacant_ne_peut_rien_viser(): void
    {
        $this->delegate('+3 days', '+6 days'); // absence future
        $document = $this->draft();
        app(WorkflowService::class)->submit($document, $this->author);
        $task = $this->pendingTask($document);

        $this->actingAs($this->deputy)->post("/validations/{$task->id}/decision", ['decision' => 'approve'])->assertForbidden();

        $this->travel(4)->days();
        $this->actingAs($this->deputy)->post("/validations/{$task->id}/decision", ['decision' => 'approve'])->assertSessionHasNoErrors();
    }

    public function test_une_delegation_annulee_retire_immediatement_les_droits(): void
    {
        $this->delegate();
        $delegation = Delegation::firstOrFail();
        $this->actingAs($this->buyerValidator)->post("/absences/{$delegation->id}/annuler")->assertSessionHasNoErrors();

        $document = $this->draft();
        app(WorkflowService::class)->submit($document, $this->author);
        $this->actingAs($this->deputy)->post("/validations/{$this->pendingTask($document)->id}/decision", ['decision' => 'approve'])->assertForbidden();
    }

    public function test_le_remplacant_ne_vise_jamais_ses_propres_documents(): void
    {
        $this->delegate();
        $document = $this->draft(['department_id' => $this->purchasing->id], owner: $this->deputy);
        app(WorkflowService::class)->submit($document, $this->deputy);

        $this->actingAs($this->deputy)->post("/validations/{$this->pendingTask($document)->id}/decision", ['decision' => 'approve'])->assertForbidden();
    }

    public function test_une_meme_personne_ne_vise_pas_deux_etapes_du_meme_tour(): void
    {
        // Nadia (Finance) délègue à Karim (Achats), qui vise déjà la première étape.
        $this->actingAs($this->financeValidator)->post('/absences', [
            'delegate_id' => $this->buyerValidator->id, 'starts_on' => now()->toDateString(), 'ends_on' => now()->addDays(3)->toDateString(),
        ])->assertSessionHasNoErrors();
        $document = $this->draft();
        app(WorkflowService::class)->submit($document, $this->author);
        $this->actingAs($this->buyerValidator)->post("/validations/{$this->pendingTask($document)->id}/decision", ['decision' => 'approve']);

        $financeStep = $this->pendingTask($document);
        $this->assertFalse(ReviewTask::actionableBy($this->buyerValidator)->whereKey($financeStep->id)->exists());
        $this->actingAs($this->buyerValidator)->post("/validations/{$financeStep->id}/decision", ['decision' => 'approve'])->assertForbidden();
    }

    public function test_regles_de_saisie_d_une_delegation(): void
    {
        $this->delegate();
        $post = fn (array $data) => $this->actingAs($this->buyerValidator)->post('/absences', [
            'delegate_id' => $this->deputy->id, 'starts_on' => now()->toDateString(), 'ends_on' => now()->addDay()->toDateString(), ...$data,
        ]);

        $post([])->assertSessionHasErrors('starts_on'); // chevauchement
        $post(['starts_on' => now()->addDays(10)->toDateString(), 'ends_on' => now()->addDays(80)->toDateString()])->assertSessionHasErrors('ends_on');
        $post(['delegate_id' => $this->buyerValidator->id])->assertSessionHasErrors('delegate_id');
        $post(['starts_on' => now()->subDay()->toDateString()])->assertSessionHasErrors('starts_on');

        $this->actingAs($this->author)->post('/absences', [])->assertForbidden(); // seuls les valideurs délèguent
        $reader = User::factory()->role(Role::Reader, $this->purchasing)->create();
        $this->actingAs($reader)->get('/absences')->assertForbidden();
    }

    public function test_une_etape_bloquee_est_escaladee_une_seule_fois_au_responsable_et_a_l_administrateur(): void
    {
        Notification::fake();
        $manager = User::factory()->role(Role::Validator, $this->purchasing)->create(['name' => 'Responsable Achats']);
        $this->purchasing->update(['manager_id' => $manager->id]);
        $document = $this->draft();
        app(WorkflowService::class)->submit($document, $this->author);

        $this->travel(24 + 47)->hours(); // échéance (24 h) dépassée de 47 h : relance, pas encore d'escalade
        $this->artisan('ged:remind-overdue')->expectsOutputToContain('0 escaladée(s)');

        $this->travel(2)->hours();
        $this->artisan('ged:remind-overdue')->expectsOutputToContain('1 escaladée(s)');
        Notification::assertSentTo([$manager, $this->admin], ReviewEscalated::class);
        Notification::assertNotSentTo($this->author, ReviewEscalated::class);

        $this->travel(1)->days();
        $this->artisan('ged:remind-overdue')->expectsOutputToContain('0 escaladée(s)');
        $this->assertNotNull($this->pendingTask($document)->escalated_at);
    }
}

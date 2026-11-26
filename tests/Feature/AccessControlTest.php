<?php

namespace Tests\Feature;

use App\Enums\Confidentiality;
use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\User;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    public function test_la_liste_affiche_exactement_les_documents_autorises_par_la_policy(): void
    {
        $colleague = User::factory()->role(Role::Contributor, $this->purchasing)->create();
        $outsider = User::factory()->role(Role::Reader, $this->legal)->create();
        $shared = User::factory()->role(Role::Reader, $this->legal)->create();

        $internal = $this->draft(['title' => 'Interne', 'confidentiality' => Confidentiality::Internal->value]);
        $department = $this->draft(['title' => 'Service', 'confidentiality' => Confidentiality::Department->value]);
        $restricted = $this->draft(['title' => 'Restreint', 'confidentiality' => Confidentiality::Restricted->value]);
        $restricted->sharedWith()->attach($shared->id, ['shared_by' => $this->author->id]);
        $inReview = $this->draft(['title' => 'En validation', 'confidentiality' => Confidentiality::Restricted->value]);
        app(WorkflowService::class)->submit($inReview, $this->author);
        $this->actingAs($this->buyerValidator);
        app(WorkflowService::class)->decide($this->pendingTask($inReview), $this->buyerValidator, true, null); // passe à l'étape Finance

        $expected = [
            'author' => ['Interne', 'Service', 'Restreint', 'En validation'],
            'colleague' => ['Interne', 'Service'],
            'outsider' => ['Interne'],
            'shared' => ['Interne', 'Restreint'],
            'financeValidator' => ['Interne', 'En validation'], // via l'étape confiée à son service
            'admin' => ['Interne', 'Service', 'Restreint', 'En validation'],
        ];
        $users = ['author' => $this->author, 'colleague' => $colleague, 'outsider' => $outsider, 'shared' => $shared,
            'financeValidator' => $this->financeValidator, 'admin' => $this->admin];

        foreach ($users as $key => $user) {
            $listed = Document::visibleTo($user)->orderBy('title')->pluck('title')->all();
            $allowed = Document::orderBy('title')->get()->filter(fn ($d) => Gate::forUser($user)->allows('view', $d))->pluck('title')->values()->all();
            $wanted = $expected[$key];
            sort($wanted);
            $this->assertSame($wanted, $listed, "Liste incorrecte pour {$key}");
            $this->assertSame($listed, $allowed, "Scope SQL et policy divergent pour {$key}");
        }

        $this->actingAs($outsider)->get("/documents/{$restricted->id}")->assertForbidden();
        $this->actingAs($shared)->get("/documents/{$restricted->id}")->assertOk();
    }

    public function test_telechargement_controle_et_trace_mais_pas_l_apercu(): void
    {
        $document = $this->draft(['confidentiality' => Confidentiality::Restricted->value]);
        $version = $document->currentVersion;
        $outsider = User::factory()->role(Role::Reader, $this->legal)->create();

        $this->actingAs($outsider)->get("/documents/{$document->id}/versions/{$version->id}/fichier")->assertForbidden();

        $this->actingAs($this->author)->get("/documents/{$document->id}/versions/{$version->id}/fichier?inline=1")
            ->assertOk()->assertHeader('content-type', 'application/pdf')->assertHeader('x-content-type-options', 'nosniff');
        $this->assertSame(0, $document->activities()->where('action', 'downloaded')->count());

        $response = $this->actingAs($this->author)->get("/documents/{$document->id}/versions/{$version->id}/fichier");
        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
        $this->assertSame(1, $document->activities()->where('action', 'downloaded')->count());
    }

    public function test_une_version_d_un_autre_document_n_est_pas_accessible_par_son_url(): void
    {
        $mine = $this->draft(['confidentiality' => Confidentiality::Internal->value]);
        $secret = $this->draft(['confidentiality' => Confidentiality::Restricted->value]);
        $outsider = User::factory()->role(Role::Reader, $this->legal)->create();

        $this->actingAs($outsider)->get("/documents/{$mine->id}/versions/{$secret->current_version_id}/fichier")->assertNotFound();
    }

    public function test_le_journal_d_audit_ne_peut_etre_ni_modifie_ni_supprime(): void
    {
        $this->draft();
        $entry = ActivityLog::first();

        $this->expectException(LogicException::class);
        $entry->update(['action' => 'falsifié']);
    }

    public function test_les_pages_d_administration_sont_reservees_a_l_administrateur(): void
    {
        $this->actingAs($this->buyerValidator)->get('/admin/circuits')->assertForbidden();
        $this->actingAs($this->buyerValidator)->get('/admin/journal')->assertForbidden();
        $this->actingAs($this->admin)->get('/admin/circuits')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Workflows')->has('types', 1)->has('types.0.steps', 2));
    }

    public function test_un_visiteur_est_redirige_vers_la_connexion_et_la_connexion_est_limitee(): void
    {
        $this->get('/documents')->assertRedirect('/login');

        foreach (range(1, 5) as $_) {
            $this->post('/login', ['email' => $this->author->email, 'password' => 'mauvais'])->assertSessionHasErrors('email');
        }
        // Même avec le bon mot de passe, la 6e tentative dans la minute est bloquée.
        $this->post('/login', ['email' => $this->author->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertStringStartsWith('Trop de tentatives', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_un_lecteur_ne_peut_pas_deposer_de_document(): void
    {
        $reader = User::factory()->role(Role::Reader, $this->purchasing)->create();
        $this->actingAs($reader)->get('/documents/nouveau')->assertForbidden();
        $this->actingAs($reader)->post('/documents', [])->assertForbidden();
    }
}

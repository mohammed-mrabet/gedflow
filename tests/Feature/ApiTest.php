<?php

namespace Tests\Feature;

use App\Enums\Confidentiality;
use App\Enums\Role;
use App\Models\Document;
use App\Models\User;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiTest extends TestCase
{
    public function test_sans_jeton_401_et_capacites_verifiees(): void
    {
        $this->getJson('/api/v1/documents')->assertUnauthorized();

        Sanctum::actingAs($this->author, ['documents:read']);
        $this->getJson('/api/v1/documents')->assertOk();
        $this->postJson('/api/v1/documents', [])->assertForbidden(); // capacité documents:write absente
        $this->getJson('/api/v1/tasks')->assertForbidden();
    }

    public function test_depot_soumission_et_lecture_via_l_api(): void
    {
        Sanctum::actingAs($this->author, ['documents:read', 'documents:write']);

        $response = $this->post('/api/v1/documents', [
            'title' => 'Facture via API', 'document_type_id' => $this->invoice->id, 'department_id' => $this->purchasing->id,
            'tags' => ['erp'], 'file' => $this->pdf('Import automatique depuis l\'ERP'), 'submit' => true,
        ], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonPath('data.status', 'in_review')->assertJsonPath('data.reference', 'FAC-'.now()->year.'-00001');
        $id = $response->json('data.id');
        $this->assertStringEndsWith("/api/v1/documents/{$id}", $response->headers->get('Location'));

        $this->getJson("/api/v1/documents/{$id}")->assertOk()
            ->assertJsonPath('data.tasks.0.step', 'Visa du service demandeur')
            ->assertJsonPath('data.current_version.sha256', Document::find($id)->currentVersion->sha256);

        $this->getJson('/api/v1/documents?q=erp')->assertOk()->assertJsonPath('meta.total', 1);
        $versionId = Document::find($id)->current_version_id;
        $this->get("/api/v1/documents/{$id}/versions/{$versionId}/file")->assertOk()->assertDownload();
        $this->assertSame(1, Document::find($id)->activities()->where('action', 'downloaded')->count());
    }

    public function test_les_droits_de_confidentialite_s_appliquent_aussi_a_l_api(): void
    {
        $secret = $this->draft(['confidentiality' => Confidentiality::Restricted->value]);
        $outsider = User::factory()->role(Role::Contributor, $this->legal)->create();
        Sanctum::actingAs($outsider, ['documents:read']);

        $this->getJson("/api/v1/documents/{$secret->id}")->assertForbidden();
        $this->getJson('/api/v1/documents')->assertJsonPath('meta.total', 0);
    }

    public function test_visa_via_l_api(): void
    {
        $document = $this->draft();
        app(WorkflowService::class)->submit($document, $this->author);
        Sanctum::actingAs($this->buyerValidator, ['tasks:decide']);

        $this->getJson('/api/v1/tasks')->assertOk()->assertJsonCount(1, 'data');
        $taskId = $this->pendingTask($document)->id;
        $this->postJson("/api/v1/tasks/{$taskId}/decision", ['decision' => 'reject'])->assertUnprocessable()->assertJsonValidationErrors('comment');
        $this->postJson("/api/v1/tasks/{$taskId}/decision", ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_gestion_des_jetons_selon_le_role(): void
    {
        $this->actingAs($this->author)->post('/profil/api', ['name' => 'ERP', 'abilities' => ['documents:read', 'tasks:decide']])
            ->assertSessionHasErrors('abilities.1'); // un contributeur ne vise pas

        $this->actingAs($this->author)->post('/profil/api', ['name' => 'ERP', 'abilities' => ['documents:read', 'documents:write']])
            ->assertSessionHas('newToken');
        $plain = session('newToken');
        $token = $this->author->tokens()->first();
        $this->assertNotNull($token->expires_at);

        $this->withHeader('Authorization', "Bearer {$plain}")->getJson('/api/v1/me')->assertOk()->assertJsonPath('name', 'Sara');

        $this->actingAs($this->author)->delete("/profil/api/{$token->id}")->assertSessionHas('success');
        $this->assertSame(0, $this->author->tokens()->count());
    }

    /** La documentation OpenAPI décrit exactement les routes de l'API (ni plus, ni moins). */
    public function test_la_specification_openapi_couvre_toutes_les_routes(): void
    {
        $spec = $this->getJson('/api/openapi.json')->assertOk()->json();
        $documented = collect($spec['paths'])->flatMap(fn ($ops, $path) => collect(array_keys($ops))->map(fn ($m) => strtoupper($m).' '.$path))->sort()->values()->all();

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/'))
            ->flatMap(fn ($r) => collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->map(fn ($m) => $m.' '.substr($r->uri(), strlen('api/v1'))))
            ->sort()->values()->all();

        $this->assertSame($routes, $documented);
        $this->get('/api/docs')->assertOk()->assertSee('swagger-ui');
    }
}

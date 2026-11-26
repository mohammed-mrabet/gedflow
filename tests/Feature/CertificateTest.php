<?php

namespace Tests\Feature;

use App\Enums\Confidentiality;
use App\Enums\Role;
use App\Models\Document;
use App\Models\User;
use App\Services\WorkflowService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class CertificateTest extends TestCase
{
    private function approved(array $overrides = []): Document
    {
        $document = $this->draft($overrides);
        $workflow = app(WorkflowService::class);
        $workflow->submit($document, $this->author);
        $workflow->decide($this->pendingTask($document), $this->buyerValidator, true, 'Conforme au bon de commande');
        $workflow->decide($this->pendingTask($document), $this->financeValidator, true, null);

        return $document->fresh();
    }

    public function test_l_approbation_produit_un_certificat_pdf_avec_les_visas_et_l_empreinte(): void
    {
        $document = $this->approved();

        $this->assertSame(40, strlen($document->verification_token));
        $this->assertNotNull($document->certificate_generated_at);
        $text = (new Parser)->parseContent(Storage::disk('local')->get($document->certificate_path))->getText();

        $this->assertStringContainsString($document->reference, $text);
        $this->assertStringContainsString($document->currentVersion->sha256, $text);
        $this->assertStringContainsString('Visé par Karim', $text);
        $this->assertStringContainsString('Conforme au bon de commande', $text);
        $this->assertStringContainsString("/verifier/{$document->verification_token}", $text);

        $this->actingAs($this->author)->get("/documents/{$document->id}/certificat")
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_pas_de_certificat_pour_un_brouillon_ni_pour_un_utilisateur_sans_droit(): void
    {
        $draft = $this->draft();
        $this->actingAs($this->author)->get("/documents/{$draft->id}/certificat")->assertNotFound();

        $restricted = $this->approved(['confidentiality' => Confidentiality::Restricted->value]);
        $outsider = User::factory()->role(Role::Reader, $this->legal)->create();
        $this->actingAs($outsider)->get("/documents/{$restricted->id}/certificat")->assertForbidden();
    }

    public function test_la_page_publique_confirme_le_document_et_verifie_un_fichier(): void
    {
        $document = $this->approved();
        $original = Storage::disk('local')->path($document->currentVersion->path);

        $this->get("/verifier/{$document->verification_token}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Verify')
                ->where('certificate.reference', $document->reference)
                ->where('certificate.title', 'Facture de test')
                ->has('certificate.visas', 2));

        $same = new UploadedFile($original, 'facture-reçue.pdf', 'application/pdf', null, true);
        $this->post("/verifier/{$document->verification_token}", ['file' => $same])->assertSessionHas('verification', fn ($r) => $r['match'] === true);

        $forged = UploadedFile::fake()->createWithContent('facture-modifiée.pdf', 'contenu modifié');
        $this->post("/verifier/{$document->verification_token}", ['file' => $forged])->assertSessionHas('verification', fn ($r) => $r['match'] === false);

        $this->assertSame(2, $document->activities()->where('action', 'external_verification')->count());
        $this->assertCount(2, Storage::disk('local')->allFiles()); // la version et le certificat : les fichiers vérifiés ne sont pas conservés
    }

    public function test_un_document_restreint_ne_revele_pas_son_titre_et_un_jeton_inconnu_ne_renvoie_rien(): void
    {
        $restricted = $this->approved(['confidentiality' => Confidentiality::Restricted->value]);
        $this->get("/verifier/{$restricted->verification_token}")
            ->assertInertia(fn (Assert $page) => $page->where('certificate.title', null)->where('certificate.reference', $restricted->reference));

        $this->get('/verifier/'.str_repeat('x', 40))->assertInertia(fn (Assert $page) => $page->where('certificate', null));
        $this->post('/verifier/'.str_repeat('x', 40), ['file' => UploadedFile::fake()->create('a.pdf')])->assertNotFound();
    }
}

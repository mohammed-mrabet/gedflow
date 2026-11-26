<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\TextExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

class DocumentTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Facture Atlas',
            'document_type_id' => $this->invoice->id,
            'department_id' => $this->purchasing->id,
            'confidentiality' => 'department',
            'tags' => ['Fournitures ', 'atlas', 'atlas'],
            'file' => $this->pdf('Cartouches d\'encre et ramettes de papier'),
            ...$overrides,
        ];
    }

    public function test_depot_reference_empreinte_indexation_et_soumission_immediate(): void
    {
        $this->actingAs($this->author)->post('/documents', $this->payload(['submit' => true]))->assertRedirect();

        $document = Document::firstOrFail();
        $version = $document->currentVersion;
        $this->assertSame('FAC-'.now()->year.'-00001', $document->reference);
        $this->assertSame(['fournitures', 'atlas'], $document->tags);
        $this->assertSame(DocumentStatus::InReview, $document->status);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($version->path)), $version->sha256);
        $this->assertStringContainsString('ramettes de papier', $version->extracted_text);
        $this->assertStringNotContainsString('facture', basename($version->path)); // nom aléatoire sur le disque

        $this->actingAs($this->author)->post('/documents', $this->payload(['title' => 'Deuxième']));
        $this->assertSame('FAC-'.now()->year.'-00002', Document::latest('id')->first()->reference);
    }

    public function test_la_recherche_trouve_le_contenu_du_fichier_et_montre_un_extrait(): void
    {
        $this->draft(['title' => 'Facture A'], content: 'Prestation de climatisation et maintenance préventive');
        $this->draft(['title' => 'Facture B'], content: 'Achat de mobilier de bureau');

        $this->actingAs($this->author)->get('/documents?q=climatisation')
            ->assertInertia(fn (Assert $page) => $page->component('Documents/Index')
                ->has('documents.data', 1)
                ->where('documents.data.0.title', 'Facture A')
                ->where('documents.data.0.excerpt', fn ($e) => str_contains($e, 'climatisation')));

        $this->actingAs($this->author)->get('/documents?q=MOBILIER%20bureau')
            ->assertInertia(fn (Assert $page) => $page->has('documents.data', 1)->where('documents.data.0.title', 'Facture B'));
    }

    public function test_les_fichiers_executables_et_trop_lourds_sont_refuses(): void
    {
        $this->actingAs($this->author)->post('/documents', $this->payload(['file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')]))
            ->assertSessionHasErrors('file');
        $this->actingAs($this->author)->post('/documents', $this->payload(['file' => UploadedFile::fake()->create('gros.pdf', 25_000, 'application/pdf')]))
            ->assertSessionHasErrors('file');
        $this->assertSame(0, Document::count());
    }

    public function test_la_verification_d_integrite_detecte_un_fichier_modifie(): void
    {
        $document = $this->draft();
        $version = $document->currentVersion;

        $this->actingAs($this->author)->post("/documents/{$document->id}/versions/{$version->id}/verification")
            ->assertSessionHas('success');

        Storage::disk('local')->put($version->path, 'contenu falsifié');
        $this->actingAs($this->author)->post("/documents/{$document->id}/versions/{$version->id}/verification")
            ->assertSessionHas('error');
        $this->assertFalse($document->activities()->where('action', 'integrity_checked')->latest('id')->first()->details['intact']);
    }

    public function test_extraction_du_texte_d_un_document_word(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<w:document><w:body><w:p><w:r><w:t>Procédure</w:t></w:r></w:p><w:p><w:r><w:t>d&apos;accueil</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();

        $text = (new TextExtractor)->extract($path, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->assertSame("Procédure d'accueil", $text);
    }

    public function test_les_metadonnees_ne_sont_plus_modifiables_apres_soumission(): void
    {
        $document = $this->draft();
        $this->actingAs($this->author)->put("/documents/{$document->id}", ['title' => 'Nouveau titre', 'confidentiality' => 'internal', 'tags' => ['x']])
            ->assertSessionHasNoErrors();
        $this->assertSame('Nouveau titre', $document->fresh()->title);

        $this->actingAs($this->author)->post("/documents/{$document->id}/soumettre");
        $this->actingAs($this->author)->put("/documents/{$document->id}", ['title' => 'Trop tard', 'confidentiality' => 'internal'])->assertForbidden();
    }

    public function test_l_export_csv_du_journal_neutralise_les_formules(): void
    {
        $this->draft(['title' => '=HYPERLINK("http://pirate")']);
        $document = Document::first();
        $document->update(['reference' => '=1+1']);

        $csv = $this->actingAs($this->admin)->get('/admin/journal/export')->assertOk()->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringNotContainsString(';=1+1', $csv);
    }

    public function test_tableau_de_bord(): void
    {
        $this->draft();
        $this->actingAs($this->author)->get('/')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard')->where('stats.total', 1)->where('stats.myDrafts', 1));
    }
}

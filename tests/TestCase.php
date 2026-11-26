<?php

namespace Tests;

use App\Enums\Confidentiality;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\ReviewTask;
use App\Models\User;
use App\Services\DocumentService;
use App\Support\SimplePdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected Department $purchasing;

    protected Department $finance;

    protected Department $legal;

    protected DocumentType $invoice;   // circuit : service émetteur (24 h) puis Finance (48 h)

    protected User $author;            // contributrice Achats

    protected User $buyerValidator;    // valideur Achats

    protected User $financeValidator;  // valideuse Finance

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');

        $this->purchasing = Department::create(['code' => 'ACH', 'name' => 'Achats']);
        $this->finance = Department::create(['code' => 'FIN', 'name' => 'Finance']);
        $this->legal = Department::create(['code' => 'JUR', 'name' => 'Juridique']);

        $this->invoice = DocumentType::create(['code' => 'FAC', 'name' => 'Facture fournisseur', 'retention_years' => 10,
            'default_confidentiality' => Confidentiality::Department]);
        $this->invoice->steps()->create(['position' => 1, 'name' => 'Visa du service demandeur', 'department_id' => null, 'due_hours' => 24]);
        $this->invoice->steps()->create(['position' => 2, 'name' => 'Contrôle Finance', 'department_id' => $this->finance->id, 'due_hours' => 48]);

        $this->author = User::factory()->role(Role::Contributor, $this->purchasing)->create(['name' => 'Sara']);
        $this->buyerValidator = User::factory()->role(Role::Validator, $this->purchasing)->create(['name' => 'Karim']);
        $this->financeValidator = User::factory()->role(Role::Validator, $this->finance)->create(['name' => 'Nadia']);
        $this->admin = User::factory()->role(Role::Admin, $this->legal)->create(['name' => 'Amina']);
    }

    protected function pdf(string $text = 'Facture fournisseur', string $name = 'facture.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'test').'.pdf';
        file_put_contents($path, SimplePdf::make('Document de test', [$text]));

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    protected function draft(array $overrides = [], ?User $owner = null, string $content = 'Facture fournisseur'): Document
    {
        return app(DocumentService::class)->create([
            'title' => 'Facture de test',
            'document_type_id' => $this->invoice->id,
            'department_id' => ($owner ?? $this->author)->department_id,
            'confidentiality' => Confidentiality::Department->value,
            'tags' => ['test'],
            ...$overrides,
        ], $this->pdf($content), $owner ?? $this->author);
    }

    protected function pendingTask(Document $document): ReviewTask
    {
        return $document->fresh()->tasks()->where('status', TaskStatus::Pending)->firstOrFail();
    }
}

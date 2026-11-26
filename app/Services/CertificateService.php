<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Support\PdfDocument;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Illuminate\Support\Facades\Storage;

/**
 * Certificat de validation : une page PDF qui récapitule le document approuvé (version, empreinte SHA-256)
 * et chaque visa du circuit, avec un QR code vers la page publique de vérification.
 */
class CertificateService
{
    public function generate(Document $document): string
    {
        $document->loadMissing(['type', 'department', 'owner', 'currentVersion']);
        $version = $document->currentVersion;
        $tasks = $document->tasks()->with(['decider', 'onBehalfOf', 'department'])
            ->where('round', $document->review_round)->where('status', TaskStatus::Approved)->get();
        $url = $this->verificationUrl($document);
        $tz = config('app.display_timezone');

        $pdf = new PdfDocument;
        $ink = [0.09, 0.11, 0.16];
        $muted = [0.36, 0.40, 0.49];
        $amber = [0.72, 0.47, 0.12];

        // En-tête
        $pdf->rect(0, 782, PdfDocument::WIDTH, 60, [0.09, 0.11, 0.16]);
        $pdf->text(48, 812, 'GedFlow', 18, true, [1, 1, 1]);
        $pdf->text(48, 795, 'Certificat de validation', 11, false, [0.96, 0.77, 0.42]);
        $pdf->text(400, 805, $document->reference, 13, true, [1, 1, 1]);

        // Document
        $y = 750;
        $y = $pdf->paragraph(48, $y, $document->title, 330, 15, true, $ink) - 6;
        foreach ([
            ['Type', $document->type->name],
            ['Service émetteur', $document->department->name],
            ['Déposé par', $document->owner->name],
            ['Approuvé le', $document->approved_at?->timezone($tz)->format('d/m/Y à H:i')],
            ['Conservation jusqu\'au', $document->retention_until?->format('d/m/Y')],
            ['Tour de validation', (string) $document->review_round],
        ] as [$label, $value]) {
            $pdf->text(48, $y, $label, 9, false, $muted)->text(170, $y, (string) $value, 10, false, $ink);
            $y -= 17;
        }

        // QR code de vérification
        $pdf->qr(440, 642, $this->matrix($url), 102);
        $pdf->paragraph(440, 630, 'Scannez pour vérifier l\'authenticité de ce document', 120, 7.5, false, $muted, 1.3);

        // Fichier approuvé
        $y -= 14;
        $pdf->line(48, $y + 8, 547, $y + 8);
        $pdf->text(48, $y - 8, 'Fichier approuvé', 11, true, $ink);
        $y -= 26;
        $pdf->text(48, $y, "Version {$version->number} : ".$this->shorten($version->original_name, 70), 10, false, $ink);
        $y -= 16;
        $pdf->text(48, $y, 'Empreinte SHA-256', 9, false, $muted);
        $pdf->rect(48, $y - 22, 499, 16, [0.96, 0.96, 0.97]);
        $pdf->text(54, $y - 17.5, $version->sha256, 8.6, false, $ink);
        $y -= 50;

        // Visas
        $pdf->line(48, $y + 12, 547, $y + 12);
        $pdf->text(48, $y - 4, 'Visas du circuit de validation', 11, true, $ink);
        $y -= 24;
        foreach ($tasks as $task) {
            $pdf->rect(48, $y - 3, 4, 14, $amber);
            $pdf->text(60, $y, "{$task->position}. {$task->step_name}", 10, true, $ink);
            $pdf->text(330, $y, $task->decided_at->timezone($tz)->format('d/m/Y H:i'), 9.5, false, $muted);
            $y -= 14;
            $who = $task->decider?->name.' ('.$task->department->name.')'
                .($task->onBehalfOf ? ' pour le compte de '.$task->onBehalfOf->name : '');
            $pdf->text(60, $y, 'Visé par '.$who, 9.5, false, $ink);
            $y -= 13;
            if ($task->comment) {
                $y = $pdf->paragraph(60, $y, '« '.$task->comment.' »', 470, 9, false, $muted);
            }
            $y -= 10;
        }
        if ($tasks->isEmpty()) {
            $pdf->text(60, $y, 'Type de document sans circuit : approuvé à la soumission.', 9.5, false, $muted);
        }

        // Pied de page
        $pdf->line(48, 92, 547, 92);
        $pdf->paragraph(48, 76, 'Ce certificat atteste que le fichier dont l\'empreinte figure ci-dessus a été approuvé selon le circuit de validation indiqué. '
            .'Pour vérifier un fichier, déposez-le sur la page de vérification : son empreinte est recalculée et comparée.', 499, 8, false, $muted);
        $pdf->text(48, 44, 'Vérification : '.$url, 8, false, $amber);
        $pdf->text(48, 30, 'Généré le '.now()->timezone($tz)->format('d/m/Y à H:i').' par GedFlow', 7.5, false, $muted);

        $path = "certificates/{$document->id}/certificat-{$document->reference}.pdf";
        Storage::disk(DocumentVersion::DISK)->put($path, $pdf->render("Certificat {$document->reference}"));
        $document->forceFill(['certificate_path' => $path, 'certificate_generated_at' => now()])->saveQuietly();

        return $path;
    }

    public function verificationUrl(Document $document): string
    {
        return url("/verifier/{$document->verification_token}");
    }

    /** @return array<int, array<int, bool>> */
    private function matrix(string $data): array
    {
        $matrix = Encoder::encode($data, ErrorCorrectionLevel::M())->getMatrix();
        $rows = [];
        for ($y = 0; $y < $matrix->getHeight(); $y++) {
            for ($x = 0; $x < $matrix->getWidth(); $x++) {
                $rows[$y][$x] = $matrix->get($x, $y) === 1;
            }
        }

        return $rows;
    }

    private function shorten(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }
}

<?php

namespace Database\Seeders;

use App\Enums\Confidentiality;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\Delegation;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\WorkflowService;
use App\Support\SimplePdf;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Données de démonstration FICTIVES : sociétés, personnes et montants inventés.
 * Les documents passent par les vrais services (dépôt, circuit, décisions) pour produire
 * un historique et un journal d'audit cohérents.
 */
class DatabaseSeeder extends Seeder
{
    private DocumentService $documents;

    private WorkflowService $workflow;

    public function run(DocumentService $documents, WorkflowService $workflow): void
    {
        $this->documents = $documents;
        $this->workflow = $workflow;
        config(['queue.default' => 'sync']); // extraction du texte immédiate pour la recherche

        $dept = collect([
            'DG' => 'Direction générale', 'FIN' => 'Finance', 'ACH' => 'Achats',
            'JUR' => 'Juridique', 'RH' => 'Ressources humaines', 'DSI' => "Systèmes d'information",
        ])->map(fn ($name, $code) => Department::create(['code' => $code, 'name' => $name]));

        $u = collect([
            'admin' => ['Amina El Idrissi', 'admin@gedflow.test', Role::Admin, 'DSI', 'Responsable GED'],
            'sara' => ['Sara Bennani', 'sara.contributrice@gedflow.test', Role::Contributor, 'ACH', 'Acheteuse'],
            'karim' => ['Karim Alaoui', 'karim.valideur@gedflow.test', Role::Validator, 'ACH', 'Responsable Achats'],
            'nadia' => ['Nadia Tazi', 'nadia.finance@gedflow.test', Role::Validator, 'FIN', 'Contrôleuse de gestion'],
            'youssef' => ['Youssef Amrani', 'youssef.juridique@gedflow.test', Role::Validator, 'JUR', 'Juriste'],
            'leila' => ['Leïla Fassi', 'leila.rh@gedflow.test', Role::Validator, 'RH', 'Responsable RH'],
            'omar' => ['Omar Kettani', 'omar.rh@gedflow.test', Role::Contributor, 'RH', 'Chargé RH'],
            'hamza' => ['Hamza Ouali', 'hamza.dg@gedflow.test', Role::Validator, 'DG', 'Directeur général'],
            'imane' => ['Imane Berrada', 'imane.lecture@gedflow.test', Role::Reader, 'FIN', 'Auditrice interne'],
        ])->map(fn ($p) => User::create([
            'name' => $p[0], 'email' => $p[1], 'password' => 'password', 'role' => $p[2],
            'department_id' => $dept[$p[3]]->id, 'job_title' => $p[4], 'email_verified_at' => now(),
        ]));

        foreach (['ACH' => 'karim', 'FIN' => 'nadia', 'JUR' => 'youssef', 'RH' => 'leila', 'DG' => 'hamza', 'DSI' => 'admin'] as $code => $key) {
            $dept[$code]->update(['manager_id' => $u[$key]->id]); // destinataire des escalades
        }

        $types = [];
        foreach ([
            ['FAC', 'Facture fournisseur', 10, Confidentiality::Department, [['Visa du service demandeur', null, 24], ['Contrôle et mise en paiement', 'FIN', 48]]],
            ['BDC', 'Bon de commande', 10, Confidentiality::Department, [['Validation responsable Achats', 'ACH', 24], ['Engagement budgétaire', 'FIN', 48]]],
            ['CTR', 'Contrat', 10, Confidentiality::Restricted, [['Revue juridique', 'JUR', 72], ['Signature de la Direction', 'DG', 48]]],
            ['NDS', 'Note de service', 5, Confidentiality::Internal, [['Validation de la Direction', 'DG', 24]]],
            ['PRQ', 'Procédure qualité', 5, Confidentiality::Internal, [['Visa du responsable du service', null, 48], ['Approbation de la Direction', 'DG', 72]]],
        ] as [$code, $name, $years, $conf, $steps]) {
            $type = DocumentType::create(['code' => $code, 'name' => $name, 'retention_years' => $years, 'default_confidentiality' => $conf]);
            foreach ($steps as $i => [$stepName, $deptCode, $hours]) {
                $type->steps()->create(['position' => $i + 1, 'name' => $stepName, 'department_id' => $deptCode ? $dept[$deptCode]->id : null, 'due_hours' => $hours]);
            }
            $types[$code] = $type;
        }

        $now = now();

        // 1. Facture approuvée (circuit complet : Achats puis Finance).
        $this->at($now->copy()->subDays(12), fn () => $doc = $this->doc($types['FAC'], $u['sara'], 'Facture Atlas Bureautique – fournitures T4', [
            'Fournisseur : Atlas Bureautique SARL (société fictive), Casablanca',
            'Facture n° AB-2026-0981 du 10/11/2026',
            'Objet : fournitures de bureau et cartouches d\'encre pour le quatrième trimestre.',
            'Montant HT : 10 400,00 DH - TVA 20 % : 2 080,00 DH - Total TTC : 12 480,00 DH',
            'Conditions de paiement : virement à 30 jours fin de mois.',
        ], ['fournitures', 'atlas'], submit: true));
        $fac = Document::where('title', 'like', 'Facture Atlas%')->first();
        $this->at($now->copy()->subDays(11), fn () => $this->decide($fac, $u['karim'], true, 'Livraison conforme au bon de commande.'));
        $this->at($now->copy()->subDays(10), fn () => $this->decide($fac, $u['nadia'], true, 'Imputation budgétaire 6064 – OK pour paiement.'));

        // 2. Bon de commande en attente à l'étape Finance (bientôt en retard).
        $this->at($now->copy()->subHours(30), fn () => $this->doc($types['BDC'], $u['sara'], 'Bon de commande – 12 postes de travail', [
            'Demandeur : service Achats pour le compte de la DSI',
            'Fournisseur proposé : Maghreb Informatique (société fictive)',
            '12 ordinateurs portables 16 Go RAM, garantie 3 ans, avec stations d\'accueil.',
            'Montant estimé : 168 000,00 DH TTC – budget investissement 2027.',
        ], ['informatique', 'investissement'], submit: true));
        $bdc = Document::where('title', 'like', 'Bon de commande – 12%')->first();
        $this->at($now->copy()->subHours(28), fn () => $this->decide($bdc, $u['karim'], true, 'Besoin validé avec la DSI.'));

        // 3. Contrat rejeté par le Juridique, puis version 2 déposée (brouillon à resoumettre).
        $this->at($now->copy()->subDays(6), fn () => $this->doc($types['CTR'], $u['sara'], 'Contrat de maintenance – climatisation des locaux', [
            'Prestataire : Froid Services Maroc (société fictive)',
            'Durée : 24 mois – maintenance préventive trimestrielle et dépannage sous 48 heures.',
            'Montant annuel : 54 000,00 DH HT. Pénalités de retard : non prévues.',
            'Clause de résiliation : préavis de 6 mois.',
        ], ['maintenance', 'prestataire'], submit: true));
        $ctr = Document::where('title', 'like', 'Contrat de maintenance%')->first();
        $this->at($now->copy()->subDays(4), fn () => $this->decide($ctr, $u['youssef'], false,
            'Ajouter des pénalités de retard et ramener le préavis de résiliation à 3 mois.'));
        $this->at($now->copy()->subDays(2), fn () => $this->documents->addVersion($ctr->fresh(), $this->pdf('contrat-maintenance-v2.pdf', 'Contrat de maintenance – version 2', [
            'Prestataire : Froid Services Maroc (société fictive)',
            'Durée : 24 mois – maintenance préventive trimestrielle et dépannage sous 48 heures.',
            'Montant annuel : 54 000,00 DH HT. Pénalités de retard : 1 % par jour, plafonnées à 10 %.',
            'Clause de résiliation : préavis de 3 mois.',
        ]), $u['sara'], 'Pénalités ajoutées et préavis réduit, selon la revue juridique.'));

        // 4. Note de service approuvée puis archivée.
        $this->at($now->copy()->subDays(20), fn () => $this->doc($types['NDS'], $u['omar'], 'Note de service – horaires du mois de Ramadan', [
            'Direction générale – diffusion à l\'ensemble du personnel',
            'Pendant le mois de Ramadan, les horaires de travail sont fixés de 9h00 à 15h00 du lundi au vendredi.',
            'Les services en contact avec la clientèle organisent une permanence jusqu\'à 16h00.',
        ], ['horaires', 'personnel'], submit: true, department: $dept['RH']));
        $nds = Document::where('title', 'like', 'Note de service – horaires%')->first();
        $this->at($now->copy()->subDays(19), fn () => $this->decide($nds, $u['hamza'], true, null));
        $this->at($now->copy()->subDays(18), fn () => $this->workflow->archive($nds->fresh(), $u['admin']));

        // 5. Procédure qualité en attente du visa du chef de service RH.
        $this->at($now->copy()->subHours(5), fn () => $this->doc($types['PRQ'], $u['omar'], 'Procédure d\'accueil des nouveaux collaborateurs', [
            'Objectif : garantir un accueil homogène des nouveaux arrivants dès le premier jour.',
            'Étapes : préparation du poste par la DSI, remise du livret d\'accueil, présentation de l\'équipe,',
            'formation sécurité informatique et rendez-vous de suivi à un mois avec le responsable.',
        ], ['onboarding', 'qualité'], submit: true, department: $dept['RH']));

        // 6. Facture en retard : l'étape « Visa du service demandeur » a dépassé son délai.
        $this->at($now->copy()->subDays(4), fn () => $this->doc($types['FAC'], $u['sara'], 'Facture Transport Rapide – livraisons octobre', [
            'Fournisseur : Transport Rapide du Nord (société fictive), Oujda',
            'Facture n° TRN-7741 – 18 livraisons entre Oujda, Berkane et Nador.',
            'Total TTC : 7 920,00 DH.',
        ], ['transport', 'logistique'], submit: true));

        // 7. Brouillons et document confidentiel partagé.
        $this->at($now->copy()->subDay(), fn () => $this->doc($types['BDC'], $u['sara'], 'Bon de commande – mobilier salle de réunion', [
            'Table de réunion 10 places, 10 fauteuils et un écran mural 75 pouces.',
            'Trois devis comparés : Bureau Design, Meubles Atlas et Ergo Maroc (sociétés fictives).',
        ], ['mobilier']));
        $this->at($now->copy()->subHours(3), fn () => $this->doc($types['CTR'], $u['omar'], 'Avenant – contrat de prestation de formation', [
            'Organisme : Académie Digitale de l\'Oriental (société fictive)',
            'Avenant n° 1 : ajout de deux sessions de formation Laravel et React pour l\'équipe DSI.',
        ], ['formation'], department: $dept['RH']));

        // 8. Ancienne procédure dont la durée de conservation est échue (à examiner pour destruction).
        $this->at($now->copy()->subYears(6), fn () => $this->doc($types['PRQ'], $u['omar'], 'Procédure de gestion des congés (2020)', [
            'Ancienne procédure de demande et de validation des congés, remplacée par le portail RH.',
        ], ['congés', 'archive'], submit: true, department: $dept['RH']));
        $old = Document::where('title', 'like', 'Procédure de gestion des congés%')->first();
        $this->at($now->copy()->subYears(6)->addDay(), fn () => $this->decide($old, $u['leila'], true, null));
        $this->at($now->copy()->subYears(6)->addDays(2), fn () => $this->decide($old, $u['hamza'], true, null));
        $this->at($now->copy()->subYears(5)->addDays(3), fn () => $this->workflow->archive($old->fresh(), $u['admin']));

        $ctr->fresh()->sharedWith()->attach($u['nadia']->id, ['shared_by' => $u['sara']->id]);

        $this->history($types, $u, $now);

        // Nadia (Finance) est absente cette semaine : Youssef vise les étapes Finance pour son compte.
        Delegation::create(['delegator_id' => $u['nadia']->id, 'delegate_id' => $u['youssef']->id,
            'starts_on' => $now->copy()->subDay()->toDateString(), 'ends_on' => $now->copy()->addDays(4)->toDateString(), 'reason' => 'Congés']);

        // Relances et escalade des étapes en retard, comme le ferait le planificateur.
        $this->workflow->remindOverdue();
    }

    /**
     * Six mois d'historique pour les statistiques : délais réalistes par service
     * (Achats rapide, Finance plus lente : goulot), quelques rejets et une délégation passée.
     */
    private function history(array $types, $u, Carbon $now): void
    {
        mt_srand(2027);
        $delays = ['ACH' => [2, 20], 'FIN' => [20, 96], 'JUR' => [24, 80], 'DG' => [4, 40], 'RH' => [6, 30]];
        $absence = [$now->copy()->subDays(70), $now->copy()->subDays(63)]; // Nadia absente : Youssef la remplace
        Delegation::create(['delegator_id' => $u['nadia']->id, 'delegate_id' => $u['youssef']->id,
            'starts_on' => $absence[0]->toDateString(), 'ends_on' => $absence[1]->toDateString(), 'reason' => 'Formation']);
        $validators = ['ACH' => 'karim', 'FIN' => 'nadia', 'JUR' => 'youssef', 'DG' => 'hamza', 'RH' => 'leila'];
        $subjects = [
            'FAC' => ['Facture Atlas Bureautique', 'Facture Maghreb Informatique', 'Facture Transport Rapide du Nord', 'Facture Nettoyage Pro Oriental'],
            'BDC' => ['Bon de commande – licences logicielles', 'Bon de commande – fournitures', 'Bon de commande – écrans 27 pouces'],
            'PRQ' => ['Procédure de sauvegarde des données', 'Procédure de gestion des achats', 'Procédure de télétravail'],
            'NDS' => ['Note de service – inventaire annuel', 'Note de service – sécurité incendie'],
        ];

        for ($i = 0; $i < 42; $i++) {
            $code = ['FAC', 'FAC', 'BDC', 'BDC', 'PRQ', 'NDS'][mt_rand(0, 5)];
            $owner = $code === 'PRQ' || $code === 'NDS' ? $u['omar'] : $u['sara'];
            $title = $subjects[$code][mt_rand(0, count($subjects[$code]) - 1)].' n° '.(100 + $i);
            $moment = $now->copy()->subDays(mt_rand(8, 175))->setTime(mt_rand(8, 16), mt_rand(0, 59));

            $document = $this->at($moment, fn () => $this->doc($types[$code], $owner, $title, [
                $title, 'Document généré pour l\'historique de démonstration.',
            ], ['historique'], submit: true));
            $rejectAt = mt_rand(1, 9) === 1 ? 1 : null; // environ 1 document sur 9 rejeté à la première étape

            while (($task = $document->fresh()->tasks()->where('status', TaskStatus::Pending)->first()) !== null) {
                $deptCode = $task->department->code;
                [$min, $max] = $delays[$deptCode];
                $moment = $task->created_at->copy()->addMinutes(mt_rand($min * 60, $max * 60));
                if ($moment->isFuture()) {
                    break; // reste en attente : fait partie de la charge actuelle
                }
                $validator = $u[$validators[$deptCode]];
                if ($deptCode === 'FIN' && $moment->between($absence[0], $absence[1]->copy()->endOfDay())) {
                    $validator = $u['youssef'];
                }
                $reject = $rejectAt === $task->position && $task->round === 1;
                $this->at($moment, fn () => $this->workflow->decide($task, $validator, ! $reject, $reject ? 'Pièce justificative manquante.' : null));
                if ($reject) {
                    $moment->addHours(mt_rand(4, 30));
                    $this->at($moment, function () use ($document, $owner, $title) {
                        $this->documents->addVersion($document->fresh(), $this->pdf('v2.pdf', $title.' – v2', [$title, 'Version corrigée.']), $owner, 'Pièce ajoutée.');
                        $this->workflow->submit($document->fresh(), $owner);
                    });
                }
            }
        }
    }

    private function doc(DocumentType $type, User $owner, string $title, array $lines, array $tags, bool $submit = false, ?Department $department = null): Document
    {
        $document = $this->documents->create([
            'title' => $title,
            'document_type_id' => $type->id,
            'department_id' => ($department ?? $owner->department)->id,
            'confidentiality' => $type->default_confidentiality->value,
            'tags' => $tags,
        ], $this->pdf(Str::slug($title).'.pdf', $title, $lines), $owner);

        if ($submit) {
            $this->workflow->submit($document, $owner);
        }

        return $document;
    }

    private function decide(Document $document, User $validator, bool $approve, ?string $comment): void
    {
        $task = $document->fresh()->tasks()->where('status', TaskStatus::Pending)->firstOrFail();
        $this->workflow->decide($task, $validator, $approve, $comment);
    }

    private function pdf(string $name, string $title, array $lines): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ged').'.pdf';
        file_put_contents($path, SimplePdf::make($title, [...$lines, '', 'Document de démonstration GedFlow – données fictives.']));

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    /** Exécute une action à une date donnée, pour un historique réaliste (dates de dépôt, de visa…). */
    private function at(Carbon $moment, callable $action): mixed
    {
        Carbon::setTestNow($moment);
        try {
            return $action();
        } finally {
            Carbon::setTestNow();
        }
    }
}

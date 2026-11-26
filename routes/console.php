<?php

use App\Models\User;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('ged:remind-overdue', function (WorkflowService $workflow) {
    $result = $workflow->remindOverdue();
    $this->info("{$result['reminded']} étape(s) en retard relancée(s), {$result['escalated']} escaladée(s).");
})->purpose('Relance les valideurs des étapes en retard et escalade les étapes bloquées');

// Le planificateur tourne dans le conteneur « scheduler » (php artisan schedule:work).
Schedule::command('ged:remind-overdue')->hourly()->withoutOverlapping();

Artisan::command('ged:install-demo', function () {
    if (User::query()->exists()) {
        $this->info('Base déjà initialisée : données de démonstration non rechargées.');

        return;
    }
    $this->call('db:seed', ['--force' => true]);
    $this->info('Données de démonstration chargées (comptes en @gedflow.test, mot de passe : password).');
})->purpose('Charge les données de démonstration si la base est vide (premier démarrage Docker)');

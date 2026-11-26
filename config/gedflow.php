<?php

return [
    // Une étape en retard depuis plus de N heures est escaladée au responsable du service et aux administrateurs.
    'escalation_after_hours' => (int) env('GEDFLOW_ESCALATION_AFTER_HOURS', 48),

    // Durée maximale d'une délégation pendant une absence.
    'delegation_max_days' => 60,
];

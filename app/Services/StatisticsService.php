<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Department;
use App\Models\Document;
use App\Models\ReviewTask;
use Illuminate\Support\Collection;

/**
 * Indicateurs de pilotage des circuits : délais de traitement par étape et par service,
 * respect des échéances, taux de rejet, goulots d'étranglement et volume mensuel.
 * Calculés en PHP sur les tâches de la période : identique sur SQLite et PostgreSQL.
 */
class StatisticsService
{
    public function compute(int $days): array
    {
        $since = now()->subDays($days);
        $decided = ReviewTask::with(['document.type', 'department'])
            ->whereIn('status', [TaskStatus::Approved, TaskStatus::Rejected])
            ->where('decided_at', '>=', $since)->get();

        $hours = fn (ReviewTask $t) => $t->created_at->diffInMinutes($t->decided_at) / 60;

        $steps = $decided->groupBy(fn ($t) => $t->step_name.'|'.$t->department->name)->map(function (Collection $tasks, $key) use ($hours) {
            [$step, $department] = explode('|', $key);
            $durations = $tasks->map($hours)->sort()->values();

            return [
                'step' => $step,
                'department' => $department,
                'count' => $tasks->count(),
                'avgHours' => round($durations->avg(), 1),
                'medianHours' => round($this->median($durations), 1),
                'onTimeRate' => $this->rate($tasks->filter(fn ($t) => $t->decided_at->lte($t->due_at))->count(), $tasks->count()),
                'rejectionRate' => $this->rate($tasks->where('status', TaskStatus::Rejected)->count(), $tasks->count()),
            ];
        })->sortByDesc('avgHours')->values();

        $pending = ReviewTask::with('department')->where('status', TaskStatus::Pending)->get();
        $departments = Department::orderBy('name')->get()->map(function (Department $d) use ($decided, $pending, $hours) {
            $done = $decided->where('department_id', $d->id);
            $waiting = $pending->where('department_id', $d->id);

            return [
                'department' => $d->name,
                'decided' => $done->count(),
                'avgHours' => $done->isEmpty() ? null : round($done->map($hours)->avg(), 1),
                'pending' => $waiting->count(),
                'overdue' => $waiting->filter(fn ($t) => $t->due_at->isPast())->count(),
                'oldestPendingHours' => $waiting->isEmpty() ? null : round($waiting->min('created_at')->diffInMinutes(now()) / 60, 1),
            ];
        })->filter(fn ($d) => $d['decided'] + $d['pending'] > 0)->values();

        // Goulot : le service le plus lent, chaque étape actuellement en retard comptant pour un jour de plus.
        $bottleneck = $departments->filter(fn ($d) => $d['avgHours'] !== null)
            ->sortByDesc(fn ($d) => $d['avgHours'] + 24 * $d['overdue'])->first();

        $approved = Document::with('tasks')->whereNotNull('approved_at')->where('approved_at', '>=', $since)->get();
        $cycle = $approved->map(function (Document $doc) {
            $first = $doc->tasks->sortBy('created_at')->first();

            return $first ? $first->created_at->diffInMinutes($doc->approved_at) / 60 : null;
        })->filter();

        $types = $decided->groupBy(fn ($t) => $t->document->type->name)->map(fn (Collection $tasks, $type) => [
            'type' => $type,
            'documents' => $tasks->pluck('document_id')->unique()->count(),
            'rejectionRate' => $this->rate($tasks->where('status', TaskStatus::Rejected)->pluck('document_id')->unique()->count(), $tasks->pluck('document_id')->unique()->count()),
        ])->sortByDesc('documents')->values();

        return [
            'period' => $days,
            'summary' => [
                'decisions' => $decided->count(),
                'avgStepHours' => $decided->isEmpty() ? null : round($decided->map($hours)->avg(), 1),
                'onTimeRate' => $this->rate($decided->filter(fn ($t) => $t->decided_at->lte($t->due_at))->count(), $decided->count()),
                'rejectionRate' => $this->rate($decided->where('status', TaskStatus::Rejected)->count(), $decided->count()),
                'avgCycleHours' => $cycle->isEmpty() ? null : round($cycle->avg(), 1),
                'delegatedRate' => $this->rate($decided->whereNotNull('decided_on_behalf_of')->count(), $decided->count()),
                'pending' => $pending->count(),
                'overdue' => $pending->filter(fn ($t) => $t->due_at->isPast())->count(),
            ],
            'steps' => $steps,
            'departments' => $departments,
            'bottleneck' => $bottleneck,
            'types' => $types,
            'monthly' => $this->monthly($decided),
        ];
    }

    /** Visas et rejets des 6 derniers mois. */
    private function monthly(Collection $decided): Collection
    {
        return collect(range(5, 0))->map(function (int $ago) use ($decided) {
            $month = now()->startOfMonth()->subMonths($ago);
            $inMonth = $decided->filter(fn ($t) => $t->decided_at->isSameMonth($month));

            return [
                'month' => $month->locale('fr')->isoFormat('MMM YY'),
                'approved' => $inMonth->where('status', TaskStatus::Approved)->count(),
                'rejected' => $inMonth->where('status', TaskStatus::Rejected)->count(),
            ];
        });
    }

    private function median(Collection $sorted): float
    {
        $n = $sorted->count();
        if ($n === 0) {
            return 0;
        }

        return $n % 2 ? $sorted[intdiv($n, 2)] : ($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2;
    }

    private function rate(int $part, int $total): ?float
    {
        return $total === 0 ? null : round($part * 100 / $total, 1);
    }
}

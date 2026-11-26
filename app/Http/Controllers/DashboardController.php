<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\TaskStatus;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Document;
use App\Models\ReviewTask;
use App\Support\Present;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $visible = fn () => Document::visibleTo($user);

        $byStatus = $visible()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $statusCounts = collect(DocumentStatus::cases())->map(fn (DocumentStatus $s) => [
            'status' => $s->value, 'label' => $s->label(), 'total' => (int) ($byStatus[$s->value] ?? 0),
        ]);

        $byDepartment = $visible()->toBase()->selectRaw('department_id, count(*) as total')->groupBy('department_id')->pluck('total', 'department_id');
        $departments = Department::orderBy('name')->get()->map(fn ($d) => [
            'name' => $d->name, 'total' => (int) ($byDepartment[$d->id] ?? 0),
        ])->filter(fn ($d) => $d['total'] > 0)->values();

        $myTasks = ReviewTask::actionableBy($user)->with(['document.type', 'department'])->orderBy('due_at')->limit(6)->get();

        $visibleIds = $visible()->select('id');
        $activity = ActivityLog::with(['user', 'document'])
            ->whereIn('document_id', $visibleIds)
            ->latest('created_at')->latest('id')->limit(8)->get();

        return Inertia::render('Dashboard', [
            'stats' => [
                'total' => $statusCounts->sum('total'),
                'inReview' => (int) ($byStatus[DocumentStatus::InReview->value] ?? 0),
                'myTasks' => ReviewTask::actionableBy($user)->count(),
                'overdue' => ReviewTask::where('status', TaskStatus::Pending)->where('due_at', '<', now())
                    ->whereIn('document_id', $visibleIds)->count(),
                'approvedThisMonth' => $visible()->where('status', DocumentStatus::Approved)
                    ->where('approved_at', '>=', now()->startOfMonth())->count(),
                'myDrafts' => Document::where('owner_id', $user->id)->whereIn('status', [DocumentStatus::Draft, DocumentStatus::Rejected])->count(),
            ],
            'statusCounts' => $statusCounts,
            'departments' => $departments,
            'tasks' => $myTasks->map(fn (ReviewTask $t) => [
                ...Present::task($t),
                'document' => ['id' => $t->document->id, 'reference' => $t->document->reference, 'title' => $t->document->title, 'type' => $t->document->type->name],
            ]),
            'activity' => $activity->map(fn ($a) => Present::activity($a)),
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\TaskStatus;
use App\Models\ReviewTask;
use App\Services\ValidationRights;
use App\Services\WorkflowService;
use App\Support\Present;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ReviewTaskController extends Controller
{
    /** Corbeille de validation : les étapes en attente du service de l'utilisateur, les plus urgentes d'abord. */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $tasks = ReviewTask::actionableBy($user)
            ->with(['document.type', 'document.owner', 'document.currentVersion', 'department'])
            ->orderBy('due_at')->get();

        $done = ReviewTask::where('decided_by', $user->id)
            ->whereIn('status', [TaskStatus::Approved, TaskStatus::Rejected])
            ->with(['document', 'department', 'onBehalfOf'])->latest('decided_at')->limit(10)->get();

        $mapTask = fn (ReviewTask $t) => [
            ...Present::task($t),
            // Étape d'un autre service, visée au titre d'une délégation.
            'delegatedFrom' => $t->status === TaskStatus::Pending ? ValidationRights::onBehalfOf($user, $t)?->name : null,
            'document' => [
                'id' => $t->document->id, 'reference' => $t->document->reference, 'title' => $t->document->title,
                'type' => $t->document->type?->name, 'owner' => $t->document->owner?->name,
            ],
        ];

        return Inertia::render('Tasks/Index', [
            'tasks' => $tasks->map($mapTask),
            'done' => $done->map($mapTask),
        ]);
    }

    public function decide(Request $request, ReviewTask $task, WorkflowService $workflow): RedirectResponse
    {
        Gate::authorize('decide', $task);
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        $approve = $data['decision'] === 'approve';
        $workflow->decide($task, $request->user(), $approve, $data['comment'] ?? null);

        return back()->with('success', $approve ? "Étape « {$task->step_name} » visée." : 'Document rejeté : son auteur a été prévenu.');
    }
}

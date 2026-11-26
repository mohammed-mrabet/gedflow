<?php

namespace App\Notifications;

use App\Models\ReviewTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Étape bloquée bien au-delà de son échéance : le responsable du service et l'administrateur sont alertés. */
class ReviewEscalated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ReviewTask $task) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $document = $this->task->document;

        return (new MailMessage)
            ->subject("[GedFlow] Escalade : {$document->reference} bloqué")
            ->greeting("Bonjour {$notifiable->name},")
            ->line("L'étape « {$this->task->step_name} » ({$this->task->department->name}) du document « {$document->title} » est bloquée depuis le "
                .$this->task->due_at->timezone(config('app.display_timezone'))->format('d/m/Y H:i').'.')
            ->line('Aucun valideur ne l\'a traitée malgré les relances. Vous pouvez la réattribuer ou organiser une délégation.')
            ->action('Voir le document', url("/documents/{$document->id}"))
            ->salutation('GedFlow');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'escalated',
            'document_id' => $this->task->document_id,
            'message' => "Escalade : {$this->task->document->reference} — {$this->task->step_name} bloqué",
        ];
    }
}

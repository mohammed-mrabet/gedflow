<?php

namespace App\Notifications;

use App\Models\ReviewTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReviewRequested extends Notification implements ShouldQueue
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
            ->subject("[GedFlow] À valider : {$document->reference}")
            ->greeting("Bonjour {$notifiable->name},")
            ->line("Le document « {$document->title} » ({$document->reference}) attend votre visa pour l'étape « {$this->task->step_name} ».")
            ->line('Échéance : '.$this->task->due_at->timezone(config('app.display_timezone'))->format('d/m/Y à H:i').'.')
            ->action('Ouvrir le document', url("/documents/{$document->id}"))
            ->salutation('GedFlow');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'review_requested',
            'document_id' => $this->task->document_id,
            'message' => "À valider : {$this->task->document->reference} — {$this->task->step_name}",
        ];
    }
}

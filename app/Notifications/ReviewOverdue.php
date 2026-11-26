<?php

namespace App\Notifications;

use App\Models\ReviewTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReviewOverdue extends Notification implements ShouldQueue
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
            ->subject("[GedFlow] En retard : {$document->reference}")
            ->greeting("Bonjour {$notifiable->name},")
            ->line("L'étape « {$this->task->step_name} » du document « {$document->title} » a dépassé son échéance ("
                .$this->task->due_at->timezone(config('app.display_timezone'))->format('d/m/Y H:i').').')
            ->action('Traiter maintenant', url("/documents/{$document->id}"))
            ->salutation('GedFlow');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'review_overdue',
            'document_id' => $this->task->document_id,
            'message' => "En retard : {$this->task->document->reference} — {$this->task->step_name}",
        ];
    }
}

<?php

namespace App\Notifications;

use App\Models\Document;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentDecided extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Document $document,
        public readonly bool $approved,
        public readonly ?string $comment,
        public readonly User $decidedBy,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("[GedFlow] {$this->document->reference} ".($this->approved ? 'approuvé' : 'rejeté'))
            ->greeting("Bonjour {$notifiable->name},");

        if ($this->approved) {
            $mail->line("Votre document « {$this->document->title} » a été approuvé à toutes les étapes du circuit.");
        } else {
            $mail->line("Votre document « {$this->document->title} » a été rejeté par {$this->decidedBy->name}.")
                ->line("Motif : {$this->comment}")
                ->line('Déposez une nouvelle version corrigée puis soumettez-le de nouveau.');
        }

        return $mail->action('Voir le document', url("/documents/{$this->document->id}"))->salutation('GedFlow');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->approved ? 'approved' : 'rejected',
            'document_id' => $this->document->id,
            'message' => $this->approved
                ? "Approuvé : {$this->document->reference}"
                : "Rejeté : {$this->document->reference} ({$this->decidedBy->name})",
        ];
    }
}

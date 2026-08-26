<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Previent qu'une destination de reversement a ete enregistree ou modifiee
 * (section 69, decision D-025).
 *
 * Cet email n'est pas une confirmation de confort : c'est un dispositif de
 * securite. Rediriger les fonds vers un autre numero est le vecteur de fraude
 * central une fois qu'une plateforme sait payer, et la seule personne capable
 * de reperer un changement qu'elle n'a pas fait est celle qui recoit ce
 * message.
 *
 * Il ne contient donc PAS le numero complet, seulement ses quatre derniers
 * chiffres : un email transite en clair et finit archive. Quatre chiffres
 * suffisent a reconnaitre son propre numero, et ne suffisent pas a en composer
 * un autre.
 */
class PayoutDestinationChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Organization $organization,
        public readonly bool $isChange,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $last = mb_substr((string) $this->organization->payout_phone, -4);
        $operator = $this->organization->payout_operator?->label() ?? '';

        $mail = (new MailMessage)
            ->subject($this->isChange
                ? 'Votre numero de reversement a ete modifie'
                : 'Votre numero de reversement a ete enregistre')
            ->greeting('Bonjour,')
            ->line($this->isChange
                ? sprintf(
                    'Le numero sur lequel %s recoit ses recettes vient d\'etre modifie.',
                    $this->organization->name,
                )
                : sprintf(
                    'Le numero sur lequel %s recevra ses recettes vient d\'etre enregistre.',
                    $this->organization->name,
                ))
            ->line(sprintf('Nouveau numero : %s se terminant par %s.', $operator, $last));

        if ($this->organization->payout_name) {
            $mail->line('Titulaire indique par l\'operateur : '.$this->organization->payout_name.'.');
        }

        return $mail
            // La phrase qui compte. Tout le reste n'est la que pour l'amener.
            ->line('Si vous n\'etes pas a l\'origine de ce changement, contactez-nous immediatement : vos prochains reversements partiraient vers ce numero.')
            ->salutation('L\'equipe Pass241');
    }
}

<?php

namespace App\Notifications;

use App\Models\Settlement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Previent l'organisateur que son reversement est parti (sections 21.2, 69).
 *
 * Sans cet email, l'organisateur decouvre l'argent sur son telephone sans
 * savoir a quoi il correspond, ou pire, telephone au support pour demander ou
 * en est son versement. C'est la question la plus posee au support d'une
 * billetterie, et un message suffit a l'eviter.
 *
 * Il detaille l'ECLATEMENT — brut, commission, net — parce que la difference
 * entre ce que l'organisateur a vendu et ce qu'il recoit doit s'expliquer
 * d'elle-meme. Un montant seul, inferieur au chiffre affiche sur son tableau
 * de bord, se lit comme une erreur.
 *
 * Le numero de destination n'apparait que par ses quatre derniers chiffres :
 * un email transite en clair et finit archive (section 52).
 */
class SettlementSent extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Settlement $settlement) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $settlement = $this->settlement;
        $eventName = $settlement->event?->name ?? 'votre evenement';

        $mail = (new MailMessage)
            ->subject('Votre reversement pour '.$eventName)
            ->greeting('Bonjour,')
            ->line(sprintf(
                'Le reversement de vos recettes pour « %s » vient d\'etre %s.',
                $eventName,
                $settlement->isSettled() ? 'effectue' : 'programme',
            ))
            ->line('**Montant verse : '.$settlement->formattedNet().'**')
            ->line(sprintf(
                'Billets vendus : %s. Commission Pass241 : %s.',
                $settlement->formattedGross(),
                $settlement->formattedCommission(),
            ));

        if (! $settlement->isSettled() && $settlement->scheduled_for) {
            $mail->line(sprintf(
                'Les fonds seront liberes le %s.',
                $settlement->scheduled_for
                    ->timezone(config('app.display_timezone'))
                    ->translatedFormat('j F Y'),
            ));
        }

        return $mail
            ->line(sprintf(
                'Destination : %s se terminant par %s.',
                $settlement->recipient_operator->label(),
                mb_substr((string) $settlement->recipient_phone, -4),
            ))
            // Les frais de paiement n'apparaissent nulle part dans cet
            // eclatement, et c'est normal : ils ont ete avances par l'acheteur
            // et n'ont jamais transite par l'organisateur (decision D-023).
            ->line('Reference : '.$settlement->reference)
            ->salutation('L\'equipe Pass241');
    }
}

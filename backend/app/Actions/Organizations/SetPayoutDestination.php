<?php

namespace App\Actions\Organizations;

use App\Enums\AuditAction;
use App\Enums\PaymentProvider;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\PayoutDestinationChanged;
use App\Services\Audit\AuditLogger;
use App\Services\Payments\PaymentManager;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Enregistre le numero sur lequel l'organisateur recoit ses recettes
 * (section 21.2, decision D-025).
 *
 * SEUL chemin d'ecriture de cette donnee. Les colonnes sont hors de
 * `$fillable` : aucun formulaire ne peut les atteindre par affectation en
 * masse. C'est voulu — rediriger les fonds vers un autre numero est le vecteur
 * de fraude central une fois qu'une plateforme sait payer.
 *
 * Trois garde-fous, aucun n'ajoutant de friction a l'organisateur :
 *
 * 1. le titulaire du numero est demande au prestataire et conserve, pour etre
 *    montre avant le premier versement. Un virement mobile money ne se
 *    rappelle pas : une faute de frappe envoie definitivement l'argent a un
 *    inconnu ;
 * 2. tout changement est journalise a part, jamais confondu avec une simple
 *    mise a jour de profil ;
 * 3. tout changement est notifie a l'organisation. C'est ce qui permet de
 *    detecter une prise de controle de compte — la victime recoit un message
 *    qu'elle n'attendait pas.
 *
 * Aucune verification par code n'est demandee : le compte mobile money de
 * destination est deja verifie par Airtel ou Moov, qui exigent une piece
 * d'identite a l'ouverture.
 */
class SetPayoutDestination
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PaymentManager $payments,
    ) {}

    public function handle(
        Organization $organization,
        string $phone,
        PaymentProvider $operator,
        ?User $actor = null,
    ): Organization {
        $phone = $this->normalize($phone);

        $previousPhone = $organization->payout_phone;
        $isChange = $previousPhone !== null;

        // Rien a faire si la destination est identique : ne pas ecrire evite
        // une entree de journal et une notification qui n'apprendraient rien,
        // et qui banaliseraient les vraies alertes.
        if ($previousPhone === $phone && $organization->payout_operator === $operator) {
            return $organization;
        }

        $holderName = $this->lookupHolder($phone, $operator);

        DB::transaction(function () use ($organization, $phone, $operator, $holderName) {
            $organization->forceFill([
                'payout_phone' => $phone,
                'payout_operator' => $operator->value,
                'payout_name' => $holderName,
                'payout_updated_at' => now(),
            ])->save();
        });

        $this->audit->log(
            $isChange ? AuditAction::PayoutDestinationChanged : AuditAction::PayoutDestinationSet,
            $organization,
            [
                // Le numero precedent est conserve : sans lui, une trace ne
                // permet pas de dire vers ou les fonds ont ete detournes.
                'previous_phone' => $this->mask($previousPhone),
                'phone' => $this->mask($phone),
                'operator' => $operator->value,
                'holder_name' => $holderName,
            ],
            actor: $actor,
            organizationId: $organization->getKey(),
        );

        $this->notify($organization, $isChange);

        return $organization->refresh();
    }

    /**
     * Demande le titulaire au prestataire.
     *
     * Ne bloque jamais l'enregistrement : tous les prestataires ne savent pas
     * repondre, et un service indisponible ne doit pas empecher un
     * organisateur de renseigner son numero. Le nom est un confort de
     * verification, pas une condition.
     */
    private function lookupHolder(string $phone, PaymentProvider $operator): ?string
    {
        if (! $this->payments->capabilities()->recipientNameLookup) {
            return null;
        }

        try {
            return $this->payments->settlement()->lookupRecipientName($phone, $operator);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Previent l'organisation du changement.
     *
     * Hors transaction et sans jamais interrompre : l'enregistrement a eu
     * lieu, un serveur de messagerie indisponible ne doit pas le remettre en
     * cause.
     */
    private function notify(Organization $organization, bool $isChange): void
    {
        if (! $organization->contact_email) {
            return;
        }

        try {
            $organization->notify(new PayoutDestinationChanged($organization, $isChange));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Chiffres seuls : la saisie contient souvent des espaces ou un indicatif. */
    private function normalize(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? $phone;
    }

    /**
     * Numero masque pour le journal.
     *
     * Le journal d'audit est consulte par le Super Admin et exporte : il doit
     * permettre de reconnaitre un changement, pas de recopier un numero
     * (section 52).
     */
    private function mask(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        return str_repeat('*', max(0, mb_strlen($phone) - 4)).mb_substr($phone, -4);
    }
}

<?php

namespace App\Actions\Donations;

use App\Enums\AuditAction;
use App\Models\Commission;
use App\Models\Donation;
use App\Models\DonationRefund;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Commission\CommissionCalculator;
use App\Services\Fundraising\CampaignStatistics;
use App\Services\Payments\PaymentManager;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Rembourse tout ou partie d'un don (sections 46, 47).
 *
 * C'est ici que l'argent repart vers le donateur. Quatre garanties :
 *
 * 1. LE DON N'EST PAS TOUCHE. Une ligne nouvelle est ecrite, liee au don
 *    d'origine ; le don reste CONFIRME, parce qu'il a bien eu lieu. La section
 *    46 l'exige, et le declencheur en base l'imposerait de toute facon.
 *
 * 2. ON NE REND JAMAIS PLUS QU'ON N'A RECU. Le cumul des remboursements est
 *    relu SOUS VERROU avant d'ecrire : deux remboursements partiels lances au
 *    meme instant ne peuvent pas depasser ensemble le montant du don.
 *
 * 3. LA COMMISSION EST AJUSTEE PAR UNE LIGNE NOUVELLE, au taux fige de la
 *    ligne d'origine — jamais au taux du jour. Rembourser au taux d'aujourd'hui
 *    une transaction facturee au taux d'hier produirait un ecart que personne
 *    ne saurait expliquer.
 *
 * 4. LE MONTANT COLLECTE SE RECALCULE TOUT SEUL. Il n'existe nulle part en
 *    colonne (regle 80.1) : la page publique affiche le nouveau total sans
 *    qu'aucune valeur n'ait ete corrigee.
 *
 * L'appel au prestataire se fait APRES la transaction : un service indisponible
 * ne doit pas annuler une decision deja prise et tracee. Le virement sera
 * repris.
 */
class RefundDonation
{
    public function __construct(
        private readonly CommissionCalculator $commissions,
        private readonly PaymentManager $payments,
        private readonly AuditLogger $audit,
        private readonly OrganizationContext $context,
        private readonly CampaignStatistics $statistics,
    ) {}

    /**
     * @param  int|null  $amount  part a rendre, ou null pour la totalite du restant
     *
     * @throws RuntimeException si le don ne peut pas etre rembourse de ce montant
     */
    public function handle(
        Donation $donation,
        ?int $amount,
        string $reason,
        User $actor,
    ): DonationRefund {
        if (! $donation->isConfirmed()) {
            throw new RuntimeException('Seul un don confirme peut etre rembourse.');
        }

        if (trim($reason) === '') {
            // Section 47 : l'operation doit etre tracable. Un remboursement sans
            // motif ne se justifie pas six mois plus tard.
            throw new RuntimeException('Un remboursement doit etre motive.');
        }

        $organization = $donation->organization
            ?? throw new RuntimeException('Ce don n\'a plus d\'organisation.');

        $refund = $this->context->runFor($organization, function () use ($donation, $amount, $reason, $actor) {
            return DB::transaction(function () use ($donation, $amount, $reason, $actor) {
                /*
                 * Relecture verrouillee du don : c'est ce verrou qui serialise
                 * deux remboursements partiels simultanes. Sans lui, chacun
                 * pourrait constater qu'il reste assez, et les deux passer.
                 */
                $fresh = Donation::query()
                    ->acrossOrganizations()
                    ->lockForUpdate()
                    ->findOrFail($donation->getKey());

                $alreadyRefunded = (int) DonationRefund::query()
                    ->acrossOrganizations()
                    ->where('donation_id', $fresh->getKey())
                    ->sum('amount');

                $remaining = (int) $fresh->subtotal - $alreadyRefunded;

                if ($remaining <= 0) {
                    throw new RuntimeException('Ce don a deja ete rembourse en totalite.');
                }

                // `null` vaut « tout ce qui reste » : c'est le cas courant, et
                // le faire calculer par l'ecran exposerait a un ecart entre ce
                // qu'il affiche et ce qui reste vraiment.
                $refunded = $amount ?? $remaining;

                if ($refunded <= 0) {
                    throw new RuntimeException('Le montant rembourse doit etre superieur a zero.');
                }

                if ($refunded > $remaining) {
                    throw new RuntimeException(sprintf(
                        'Il ne reste que %s a rembourser sur ce don.',
                        number_format($remaining, 0, ',', ' ').' F'
                    ));
                }

                $refund = new DonationRefund([
                    'donation_id' => $fresh->getKey(),
                    'amount' => $refunded,
                    'reason' => trim($reason),
                    'created_by' => $actor->getKey(),
                ]);

                // La reference n'est pas `fillable` : elle ne vient jamais d'un
                // formulaire, et elle doit exister des la premiere ecriture —
                // la colonne est unique et obligatoire.
                $refund->forceFill(['reference' => $this->uniqueReference()])->save();

                $this->adjustCommission($fresh, $refunded);

                return $refund;
            });
        });

        $this->audit->log(
            AuditAction::DonationRefunded,
            $donation,
            [
                'reference' => $refund->reference,
                'donation' => $donation->reference,
                'amount' => $refund->amount,
                // Ce que le don pese apres l'operation : c'est ce chiffre que
                // le public verra, il doit figurer au journal.
                'net' => $donation->fresh()->netAmount(),
                'reason' => $refund->reason,
            ],
            actor: $actor,
            organizationId: $organization->getKey(),
        );

        // Le total collecte change : le cache d'une minute mentirait le temps
        // de son expiration, juste apres l'operation la plus sensible du
        // module.
        if ($donation->campaign) {
            $this->statistics->forget($donation->campaign);
        }

        $this->sendToProvider($donation, $refund);

        return $refund->refresh();
    }

    /**
     * Ajoute la ligne d'ajustement de commission (sections 24.1, 47).
     *
     * La part rendue est prise sur le DON, jamais sur le montant debite : les
     * frais du prestataire n'ont jamais transite par l'organisateur
     * (decision D-023), il n'y a donc aucune commission a leur rendre.
     */
    private function adjustCommission(Donation $donation, int $refundedGross): void
    {
        $original = Commission::query()
            ->originals()
            ->where('commissionable_type', $donation->getMorphClass())
            ->where('commissionable_id', $donation->getKey())
            ->first();

        if ($original === null) {
            // Aucune commission enregistree : rien a ajuster. Ne pas echouer,
            // le remboursement reste legitime.
            return;
        }

        $this->commissions->recordRefund($original, $refundedGross);
    }

    /**
     * Transmet le remboursement au prestataire.
     *
     * Hors transaction, et sans jamais la remettre en cause : la decision est
     * prise et tracee, le montant collecte a deja baisse. Un service
     * indisponible ne doit pas faire croire que le remboursement n'a pas ete
     * accorde.
     */
    private function sendToProvider(Donation $donation, DonationRefund $refund): void
    {
        try {
            $result = $this->payments->driver()->refund(
                $donation,
                $refund->amount,
                $refund->reason,
            );

            $refund->forceFill([
                'provider_reference' => $result->providerReference,
                'refunded_at' => $result->succeeded() ? now() : null,
                'failure_reason' => $result->failureReason,
            ])->save();
        } catch (Throwable $e) {
            report($e);

            $refund->forceFill(['failure_reason' => $e->getMessage()])->save();
        }
    }

    /** Reference lisible, du type RB-4KX9-27B. */
    private function uniqueReference(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        do {
            $block = fn (int $length) => collect(range(1, $length))
                ->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])
                ->implode('');

            $reference = 'RB-'.$block(4).'-'.$block(3);
        } while (DonationRefund::query()->acrossOrganizations()->where('reference', $reference)->exists());

        return $reference;
    }
}

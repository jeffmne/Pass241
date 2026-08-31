<?php

namespace App\Actions\Donations;

use App\Enums\AuditAction;
use App\Enums\DonationStatus;
use App\Enums\PaymentProvider;
use App\Jobs\ExpireDonationIfUnpaid;
use App\Models\Campaign;
use App\Models\Donation;
use App\Services\Audit\AuditLogger;
use App\Services\Payments\PaymentManager;
use App\Services\Payments\PspFeeCalculator;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creation d'un don (sections 39, 40, 50).
 *
 * Le donateur n'a pas de compte : cette action s'execute hors de tout contexte
 * d'organisation, dans une requete publique. L'organisation est deduite de la
 * CAMPAGNE — jamais d'un parametre envoye par le client (regle 80.4).
 *
 * RIEN N'EST ENCAISSE ICI. Le don nait en attente avec une echeance ; il ne
 * comptera dans le montant collecte qu'une fois confirme par le prestataire
 * (section 39). C'est `ConfirmPayment` qui tranche, jamais cet ecran.
 *
 * LE MONTANT VIENT DU NAVIGATEUR, LES FRAIS ET LE TOTAL N'EN VIENNENT PAS.
 * Le donateur choisit ce qu'il donne — c'est le principe meme d'un don — mais
 * les frais du prestataire et le total debite sont calcules ici, cote serveur
 * (regle 80.1, sections 49 et 50). Une requete qui les enverrait n'a nulle part
 * ou atterrir.
 */
class CreateDonation
{
    /**
     * Don minimum.
     *
     * En dessous, les frais du prestataire depassent la contribution : le
     * donateur paierait plus pour le transport que pour la cause.
     */
    public const MINIMUM = 500;

    public const MAXIMUM = 10_000_000;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OrganizationContext $context,
        private readonly PspFeeCalculator $fees,
        private readonly PaymentManager $payments,
    ) {}

    public function handle(
        Campaign $campaign,
        int $amount,
        PaymentProvider $provider,
        string $payerPhone,
        ?string $donorName = null,
        ?string $donorEmail = null,
        bool $isAnonymous = false,
        ?string $ipAddress = null,
    ): Donation {
        if (! $campaign->acceptsDonations()) {
            throw new RuntimeException('Cette collecte n\'accepte plus de dons.');
        }

        if ($amount < self::MINIMUM) {
            throw new RuntimeException(sprintf('Le don minimum est de %d F.', self::MINIMUM));
        }

        if ($amount > self::MAXIMUM) {
            throw new RuntimeException('Ce montant depasse le maximum autorise par don.');
        }

        $organization = $campaign->organization
            ?? throw new RuntimeException('Cette collecte n\'a plus d\'organisation.');

        /*
         * Le contexte est pose sur l'organisation de la campagne, le temps de
         * la transaction. Sans lui, le trait BelongsToOrganization refuserait
         * d'ecrire — ce qui est le comportement voulu partout ailleurs, et
         * qu'il faut ici lever explicitement plutot que contourner en forcant
         * `organization_id`.
         */
        return $this->context->runFor($organization, function () use (
            $campaign, $amount, $provider, $payerPhone, $donorName, $donorEmail, $isAnonymous, $ipAddress
        ) {
            $donation = DB::transaction(function () use (
                $campaign, $amount, $provider, $payerPhone, $donorName, $donorEmail, $isAnonymous, $ipAddress
            ) {
                /*
                 * Frais du prestataire, a la charge du donateur
                 * (decision D-023) : la cause doit recevoir le montant annonce.
                 * Le bareme est fige sur le don — sans cet instantane, une
                 * transaction deviendrait inexplicable apres un changement de
                 * prestataire.
                 */
                $psp = $this->payments->driver()->name();
                $fee = $this->fees->feeFor($amount, $psp, $provider);

                $donation = new Donation([
                    'campaign_id' => $campaign->getKey(),
                    // Un nom vide n'est pas conserve : un don sans nom est
                    // affiche comme anonyme, sans avoir a stocker une chaine
                    // vide qui ressemblerait a une identite.
                    'donor_name' => $this->nullable($donorName),
                    'donor_email' => $this->nullable($donorEmail),
                    'is_anonymous' => $isAnonymous,
                    'payment_provider' => $provider,
                    'payer_phone' => $payerPhone,
                    'psp' => $psp,
                    'subtotal' => $amount,
                    'psp_fee_amount' => $fee->amount,
                    'psp_fee_basis_points' => $fee->basisPoints,
                    'psp_fee_fixed' => $fee->fixed,
                    'amount' => $amount + $fee->amount,
                    'expires_at' => now()->addSeconds((int) config('payments.timeout_seconds')),
                    'ip_address' => $ipAddress,
                ]);

                $donation->reference = $this->uniqueReference();
                $donation->status = DonationStatus::Pending;
                $donation->save();

                return $donation;
            });

            // Filet de securite : le don doit basculer en echec meme si aucun
            // callback n'arrive (section 21.1).
            ExpireDonationIfUnpaid::dispatch($donation->getKey())
                ->delay($donation->expires_at->addSeconds(30));

            $this->audit->log(
                AuditAction::DonationCreated,
                $donation,
                [
                    'reference' => $donation->reference,
                    'campaign' => $campaign->slug,
                    'amount' => $donation->amount,
                    'subtotal' => $donation->subtotal,
                    'psp_fee' => $donation->psp_fee_amount,
                    'psp' => $donation->psp,
                    'provider' => $provider->value,
                    'anonymous' => $donation->is_anonymous,
                ],
                actor: null,
                organizationId: $campaign->organization_id,
            );

            return $donation;
        });
    }

    private function nullable(?string $value): ?string
    {
        return trim((string) $value) === '' ? null : trim((string) $value);
    }

    /**
     * Reference lisible, du type DON-4KX9-27B.
     *
     * Elle est dictee au telephone au support : l'alphabet exclut les
     * caracteres qu'on confond a l'oral et a l'ecrit (0/O, 1/I/L). Le prefixe
     * la distingue d'une commande au premier coup d'oeil.
     */
    private function uniqueReference(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        do {
            $block = fn (int $length) => collect(range(1, $length))
                ->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])
                ->implode('');

            $reference = 'DON-'.$block(4).'-'.$block(3);
        } while (Donation::query()->acrossOrganizations()->where('reference', $reference)->exists());

        return $reference;
    }
}

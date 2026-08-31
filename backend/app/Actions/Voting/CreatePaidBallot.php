<?php

namespace App\Actions\Voting;

use App\Enums\AuditAction;
use App\Enums\BallotStatus;
use App\Enums\PaymentProvider;
use App\Jobs\ExpireBallotIfUnpaid;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Services\Audit\AuditLogger;
use App\Services\Payments\PaymentManager;
use App\Services\Payments\PspFeeCalculator;
use App\Services\Voting\VoteQuotaLedger;
use App\Services\Voting\VoterIdentity;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Cree un bulletin PAYANT (sections 86.4, 86.5, jalon 9.4).
 *
 * IL NAIT EN ATTENTE, ET NE COMPTE PAS ENCORE. C'est toute la difference avec
 * le vote gratuit, qui nait confirme : « le vote n'est VALIDE qu'apres
 * confirmation reelle du paiement par le PSP » (section 86.4). Tant que
 * l'operateur n'a pas repondu, la voix n'existe pour personne — ni dans le
 * classement, ni dans les totaux, ni dans le revenu.
 *
 * C'est ce qui empeche de gonfler un candidat en lancant des paiements qu'on
 * n'honore jamais.
 *
 * LA VOIX EST POURTANT RESERVEE DES MAINTENANT, sur le quota. Sans cela, deux
 * personnes pourraient payer pour la meme derniere voix d'une categorie
 * plafonnee, et l'une des deux serait remboursee apres coup — c'est-a-dire
 * apres avoir cru avoir vote. L'echeance rend la voix si le payeur ne valide
 * jamais.
 *
 * AUCUN MONTANT NE VIENT DU NAVIGATEUR. Le prix unitaire est relu sur la
 * categorie, la quantite est bornee par le plafond, et le total est calcule
 * ici (section 86.5, point 4).
 */
class CreatePaidBallot
{
    public function __construct(
        private readonly VoterIdentity $identity,
        private readonly VoteQuotaLedger $quotas,
        private readonly OrganizationContext $context,
        private readonly PaymentManager $payments,
        private readonly PspFeeCalculator $fees,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(
        Candidate $candidate,
        int $quantity,
        PaymentProvider $provider,
        string $payerPhone,
        Request $request,
    ): Ballot {
        $category = $candidate->resolvedCategory()
            ?? throw new RuntimeException('Cette categorie n\'existe plus.');

        $poll = $category->resolvedPoll()
            ?? throw new RuntimeException('Ce vote n\'existe plus.');

        if (! $category->is_paid) {
            throw new RuntimeException(
                'Cette categorie est gratuite : le vote ne passe pas par un paiement.'
            );
        }

        /*
         * La fenetre est verifiee A LA CREATION, et c'est le seul endroit ou
         * elle le sera (section 86.5, point 8). La confirmation, elle, ne
         * refusera plus : si l'argent est parti du telephone, la voix doit etre
         * comptee meme si le callback arrive apres la cloture.
         */
        if (! $candidate->acceptsBallots()) {
            throw new RuntimeException(
                $category->closureReason() ?? 'Ce candidat n\'est plus ouvert au vote.'
            );
        }

        $quantity = max(1, $quantity);

        $limit = $category->voteLimit();

        if ($limit !== null && $quantity > $limit) {
            throw new RuntimeException(sprintf(
                'Cette categorie autorise %d vote%s par personne.',
                $limit,
                $limit > 1 ? 's' : '',
            ));
        }

        $voterHash = $this->identity->hash($category->getKey(), $payerPhone, $request);

        // Point de serialisation, avant toute ecriture — meme raisonnement que
        // pour le vote gratuit.
        if (! $this->quotas->reserve($category, $voterHash, $quantity)) {
            throw new RuntimeException(
                'Vous avez deja utilise tous vos votes dans cette categorie.'
            );
        }

        try {
            return $this->context->runFor($poll->organization, function () use (
                $candidate, $category, $poll, $quantity, $provider, $payerPhone, $voterHash, $request
            ) {
                $ballot = DB::transaction(function () use (
                    $candidate, $category, $poll, $quantity, $provider, $payerPhone, $voterHash, $request
                ) {
                    // Prix RELU EN BASE, jamais recu du navigateur.
                    $unitPrice = (int) $category->unit_price;
                    $subtotal = $unitPrice * $quantity;

                    /*
                     * Frais du prestataire, a la charge du votant
                     * (decision D-023) : l'organisateur doit recevoir le prix
                     * annonce du vote. Le bareme est fige sur le bulletin.
                     */
                    $psp = $this->payments->driver()->name();
                    $fee = $this->fees->feeFor($subtotal, $psp);

                    $ballot = new Ballot;

                    $ballot->forceFill([
                        'organization_id' => $poll->organization_id,
                        'poll_id' => $poll->getKey(),
                        'poll_category_id' => $category->getKey(),
                        'candidate_id' => $candidate->getKey(),
                        'reference' => $this->uniqueReference(),
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'subtotal' => $subtotal,
                        'psp' => $psp,
                        'psp_fee_amount' => $fee->amount,
                        'psp_fee_basis_points' => $fee->basisPoints,
                        'psp_fee_fixed' => $fee->fixed,
                        'amount' => $subtotal + $fee->amount,
                        'status' => BallotStatus::Pending,
                        'provider' => $provider,
                        'payer_phone' => $payerPhone,
                        'voter_hash' => $voterHash,
                        'ip_address' => $request->ip(),
                        'user_agent_hash' => $this->identity->userAgentHash($request),
                        'expires_at' => now()->addSeconds((int) config('payments.timeout_seconds')),
                    ])->save();

                    return $ballot->refresh();
                });

                // Filet de securite : le bulletin doit basculer en echec — et
                // rendre sa voix — meme si aucun callback n'arrive jamais.
                ExpireBallotIfUnpaid::dispatch($ballot->getKey())
                    ->delay($ballot->expires_at->addSeconds(30));

                /*
                 * L'engagement est journalise des maintenant, avant meme que
                 * l'argent ne bouge : c'est ce qui permet de constater apres
                 * coup qu'une rafale de paiements a ete lancee sur un candidat
                 * depuis une seule connexion, y compris si aucun n'a abouti.
                 * Un bulletin confirme, lui, ne dirait rien des tentatives.
                 */
                $this->audit->log(
                    AuditAction::BallotCreated,
                    $ballot,
                    [
                        'reference' => $ballot->reference,
                        'poll' => $poll->slug,
                        'candidate_id' => $ballot->candidate_id,
                        'quantity' => $ballot->quantity,
                        'amount' => $ballot->amount,
                        'subtotal' => $ballot->subtotal,
                        'psp_fee' => $ballot->psp_fee_amount,
                        'psp' => $ballot->psp,
                        'provider' => $provider->value,
                    ],
                    actor: null,
                    organizationId: $poll->organization_id,
                );

                return $ballot;
            });
        } catch (Throwable $e) {
            /*
             * L'ecriture a echoue apres la reservation : la voix doit etre
             * rendue, sinon le votant reste bloque sur son plafond sans avoir
             * vote ni paye.
             */
            $this->quotas->release($category, $voterHash, $quantity);

            throw $e;
        }
    }

    /**
     * Reference lisible, du type VT-4KX9-27B.
     *
     * Meme forme que le vote gratuit, et pour une raison qui compte davantage
     * ici : elle est portee par les webhooks de l'operateur, et c'est par elle
     * que `PayableResolver` retrouvera ce bulletin quand l'argent arrivera. Les
     * caracteres qu'on confond a l'oral sont exclus — un votant qui la dicte au
     * support ne doit pas avoir a distinguer un zero d'un O.
     */
    private function uniqueReference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $block = fn (int $length) => collect(range(1, $length))
                ->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])
                ->implode('');

            $reference = 'VT-'.$block(4).'-'.$block(3);
        } while (Ballot::query()->acrossOrganizations()->where('reference', $reference)->exists());

        return $reference;
    }
}

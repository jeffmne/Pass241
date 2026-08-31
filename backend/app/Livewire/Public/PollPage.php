<?php

namespace App\Livewire\Public;

use App\Actions\Voting\CastFreeBallot;
use App\Actions\Voting\CreatePaidBallot;
use App\Enums\PaymentProvider;
use App\Models\Candidate;
use App\Models\Poll;
use App\Models\PollCategory;
use App\Rules\MobileMoneyNumber;
use App\Services\Payments\PaymentManager;
use App\Services\Voting\VoteQuotaLedger;
use App\Services\Voting\VoterIdentity;
use App\Services\Voting\VotingStatistics;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * Page publique d'un scrutin (section 86.4).
 *
 * Rendue cote serveur, sans authentification : le votant n'a pas de compte,
 * comme l'acheteur d'un billet (section 15). C'est la page que l'organisateur
 * partage lui-meme — elle doit s'ouvrir vite sur un telephone et produire un bel
 * apercu quand le lien est transfere dans un message.
 *
 * UN SEUL COMPOSANT POUR LE SCRUTIN ET SES CATEGORIES. Elles tiennent sur la
 * meme page : un concours en a rarement plus de cinq, et faire naviguer le
 * votant entre deux ecrans pour choisir sa categorie puis son candidat ajoute un
 * clic sur le chemin qui compte — celui du vote.
 */
#[Layout('components.layouts.public')]
class PollPage extends Component
{
    public Poll $poll;

    /** Categorie ouverte dans le formulaire de vote, ou null. */
    public ?int $votingCategory = null;

    public ?int $selectedCandidate = null;

    public int $quantity = 1;

    public string $phone = '';

    /*
     * Vote payant : l'operateur et le numero vers lequel pousser la demande.
     * Distincts de `$phone`, qui n'identifie que le votant d'un vote gratuit —
     * les confondre ferait servir un meme champ a deux fins, et rendrait
     * incomprehensible la regle qui dit lequel est exige quand.
     */
    public string $provider = '';

    public string $payerPhone = '';

    public string $confirmation = '';

    public function mount(string $slug): void
    {
        // `publicBySlug` leve la portee par organisation — un visiteur n'est
        // membre d'aucune — et exclut les brouillons, qui doivent renvoyer 404
        // et non une page vide.
        $this->poll = Poll::publicBySlug($slug) ?? abort(404);
    }

    /*
     * -----------------------------------------------------------------------
     * Vote
     * -----------------------------------------------------------------------
     */

    public function choose(int $categoryId, int $candidateId): void
    {
        $candidate = $this->findCandidate($categoryId, $candidateId);

        $this->votingCategory = $categoryId;
        $this->selectedCandidate = $candidate->getKey();
        $this->quantity = 1;
        $this->confirmation = '';

        $this->resetValidation();
    }

    public function cancelVote(): void
    {
        $this->reset('votingCategory', 'selectedCandidate', 'quantity', 'phone');
        $this->resetValidation();
    }

    public function vote(CastFreeBallot $castBallot, VotingStatistics $statistics): void
    {
        if ($this->selectedCandidate === null || $this->votingCategory === null) {
            return;
        }

        $candidate = $this->findCandidate($this->votingCategory, $this->selectedCandidate);

        // `resolvedCategory` : le candidat vient d'une requete sur la relation,
        // qui ne pose pas la relation inverse. Meme raison qu'ailleurs — un
        // acces direct dependrait du chargement differe, interdit hors
        // production et payant en production.
        $category = $candidate->resolvedCategory();

        if (! $category) {
            $this->addError('vote', 'Cette categorie n\'existe plus.');

            return;
        }

        /*
         * Limitation de debit (section 86.5, point 6).
         *
         * Elle porte sur l'IP ET la categorie : quelqu'un qui vote legitimement
         * dans trois categories du meme concours ne doit pas etre bloque parce
         * qu'il a vote trois fois en une minute. Dix tentatives par minute
         * laissent largement passer un usage normal — et rendent fastidieux
         * l'enchainement de numeros.
         */
        $key = 'vote:'.request()->ip().':'.$category->getKey();

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 10)) {
            $this->addError('vote', sprintf(
                'Trop de tentatives. Reessayez dans %d secondes.',
                RateLimiter::availableIn($key),
            ));

            return;
        }

        RateLimiter::hit($key, decaySeconds: 60);

        $rules = ['quantity' => ['required', 'integer', 'min:1']];
        $messages = [];

        if ($category->is_paid) {
            /*
             * Le vote payant exige l'operateur ET le numero : c'est sur ce
             * numero que la demande de paiement sera poussee (section 16.1).
             * L'operateur est un choix explicite du votant, jamais une
             * deduction faite depuis son numero.
             */
            $rules['provider'] = ['required', 'in:'.implode(',', PaymentProvider::values())];
            $rules['payerPhone'] = [
                'required', 'string', 'max:20',
                new MobileMoneyNumber(PaymentProvider::tryFrom($this->provider)),
            ];
            $messages['provider.required'] = 'Choisissez Airtel Money ou Moov Money.';
            $messages['provider.in'] = 'Choisissez Airtel Money ou Moov Money.';
        }

        if ($this->requiresPhone($category)) {
            /*
             * Le numero est exige des qu'un plafond existe : sans lui, la seule
             * empreinte disponible serait celle de l'appareil, et deux personnes
             * derriere le meme partage de connexion — frequent au Gabon —
             * partageraient le meme plafond.
             */
            $rules['phone'] = ['required', 'string', 'min:8', 'max:20'];
            $messages['phone.required'] = 'Indiquez votre numero pour que votre vote soit compte une seule fois.';
        }

        $this->validate($rules, $messages, [
            'quantity' => 'nombre de votes',
            'phone' => 'numero',
            'payerPhone' => 'numero de telephone',
            'provider' => 'moyen de paiement',
        ]);

        /*
         * LE VOTE PAYANT PART VERS LE PAIEMENT, il ne compte pas ici.
         *
         * Le bulletin nait en attente, la demande est poussee sur le telephone,
         * et le votant suit son etat sur une page dediee. Sa voix n'entrera au
         * classement qu'a la confirmation du prestataire (section 86.4).
         */
        if ($category->is_paid) {
            $this->payAndRedirect($candidate, $category);

            return;
        }

        try {
            $ballot = $castBallot->handle(
                $candidate,
                (int) $this->quantity,
                $this->requiresPhone($category) ? $this->phone : null,
                request(),
            );
        } catch (RuntimeException $e) {
            $this->addError('vote', $e->getMessage());

            return;
        }

        /*
         * Le cache des totaux est vide immediatement : sans cela, le votant
         * verrait son propre bulletin absent du classement pendant dix
         * secondes, et croirait qu'il n'a pas ete pris en compte.
         */
        $statistics->forget($category);

        $this->confirmation = sprintf(
            'Votre vote pour %s est enregistre. Reference %s.',
            $candidate->name,
            $ballot->reference,
        );

        $this->reset('votingCategory', 'selectedCandidate', 'quantity', 'phone');
    }

    /**
     * Cree le bulletin payant, pousse la demande de paiement, et renvoie le
     * votant vers le suivi (sections 21.1, 86.4).
     *
     * L'ECHEC DU PUSH NE REMET PAS LE BULLETIN EN CAUSE : il existe, sa voix
     * est reservee, et le votant peut relancer depuis l'ecran de suivi. Le
     * faire echouer ici lui ferait tout resaisir — meme parti pris que le
     * parcours d'achat de billets.
     */
    private function payAndRedirect(Candidate $candidate, PollCategory $category): void
    {
        try {
            $ballot = app(CreatePaidBallot::class)->handle(
                $candidate,
                (int) $this->quantity,
                PaymentProvider::from($this->provider),
                trim($this->payerPhone),
                request(),
            );
        } catch (RuntimeException $e) {
            $this->addError('vote', $e->getMessage());

            return;
        }

        try {
            $initialization = app(PaymentManager::class)->driver()->initializePayment($ballot);

            if ($initialization->providerReference) {
                $ballot->forceFill([
                    'provider_reference' => $initialization->providerReference,
                ])->save();
            }
        } catch (Throwable $e) {
            report($e);
        }

        $this->redirect($ballot->statusUrl(), navigate: true);
    }

    /*
     * -----------------------------------------------------------------------
     * Rendu
     * -----------------------------------------------------------------------
     */

    public function render(VotingStatistics $statistics, VoteQuotaLedger $quotas, VoterIdentity $identity)
    {
        $categories = $this->poll->categories()
            ->where('is_active', true)
            ->with('candidates')
            ->get();

        /*
         * Les relations REMONTANTES sont posees a la main, plutot que laissees
         * au chargement differe.
         *
         * `with('candidates')` ne descend que d'un cran : chaque candidat
         * ignore ensuite de quelle categorie il vient, et chaque categorie de
         * quel scrutin. Or la vue appelle `acceptsBallots()` sur chaque
         * candidat, qui remonte a sa categorie, qui remonte au scrutin.
         *
         * Laisser faire coutait deux requetes PAR CANDIDAT sur la page la plus
         * consultee du module — et levait carrement une erreur la ou le
         * chargement differe est interdit. Nous tenons deja les deux objets :
         * ce sont les memes, il n'y a rien a relire.
         */
        $categories->each(function (PollCategory $category) {
            $category->setRelation('poll', $this->poll);

            $category->candidates->each(
                fn (Candidate $candidate) => $candidate->setRelation('category', $category)
            );
        });

        return view('livewire.public.poll-page', [
            'categories' => $categories,

            /*
             * Le classement n'est prepare QUE pour les categories qui l'ouvrent
             * au public. Le calculer pour les autres et le masquer a l'affichage
             * ferait transiter dans le HTML un resultat annonce comme scelle —
             * il suffirait d'ouvrir l'inspecteur pour le lire.
             */
            'standings' => $categories
                ->filter(fn (PollCategory $category) => $category->showsPublicResults())
                ->mapWithKeys(fn (PollCategory $category) => [
                    $category->getKey() => $statistics->standings($category),
                ]),

            'totals' => $categories->mapWithKeys(fn (PollCategory $category) => [
                $category->getKey() => $statistics->forCategory($category),
            ]),

            'requiresPhone' => $categories->mapWithKeys(fn (PollCategory $category) => [
                $category->getKey() => $this->requiresPhone($category),
            ]),
        ]);
    }

    /*
     * -----------------------------------------------------------------------
     * Acces aux lignes — toujours par le scrutin
     * -----------------------------------------------------------------------
     */

    /**
     * Retrouve un candidat DE CE SCRUTIN.
     *
     * La recherche part de `$this->poll` : un identifiant force dans une requete
     * Livewire ne peut donc pas atteindre le candidat d'un autre concours, et
     * encore moins y deposer une voix. Chercher par `Candidate::find()`
     * ouvrirait exactement ce trou.
     */
    private function findCandidate(int $categoryId, int $candidateId): Candidate
    {
        $category = $this->poll->categories()
            ->where('is_active', true)
            ->findOrFail($categoryId);

        return $category->candidates()->findOrFail($candidateId);
    }

    /**
     * Le champ « votre numero » est-il exige dans cette categorie ?
     *
     * SEULEMENT POUR UN VOTE GRATUIT, et seulement s'il plafonne
     * (decision D-055). Sans plafond, il n'y a rien a compter par personne, et
     * demander un numero ne serait que collecter une donnee dont on n'a pas
     * l'usage.
     *
     * UN VOTE PAYANT NE PASSE PAS PAR CE CHAMP : il demande deja le numero vers
     * lequel pousser la demande de paiement, et c'est CE numero qui identifie
     * le votant. En exiger un second reviendrait a poser deux fois la meme
     * question sur le meme ecran, dont l'une pour rien.
     *
     * LE NUMERO PLUTOT QUE LE NOM, et plutot que l'appareil seul.
     *
     * Un nom n'est pas un identifiant, c'est une etiquette : on le contourne en
     * tapant « Marie 2 », et il bloque des votants honnetes puisque deux Jean
     * Obame existent reellement. L'appareil seul, lui, confond une famille
     * derriere un meme partage de connexion.
     *
     * Le numero est le seul des trois qui se normalise en forme canonique et
     * qui coute quelque chose a multiplier — il faut acheter une puce. Ce n'est
     * pas une garantie d'unicite, et la decision D-047 ne pretend pas le
     * contraire ; c'est un cout de fraude, et c'est tout ce qu'on peut tenir
     * sans OTP.
     *
     * Le numero plutot que le nom, et plutot que l'appareil seul : un nom se
     * contourne en tapant « Marie 2 » et bloque deux homonymes reels ;
     * l'appareil confond une famille derriere un meme partage de connexion. Le
     * numero est le seul des trois a se normaliser et a couter quelque chose a
     * multiplier — il faut acheter une puce.
     */
    private function requiresPhone(PollCategory $category): bool
    {
        return ! $category->is_paid && $category->voteLimit() !== null;
    }
}

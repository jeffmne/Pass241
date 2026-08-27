<?php

namespace App\Livewire\Public;

use App\Actions\Donations\CreateDonation;
use App\Enums\PaymentProvider;
use App\Models\Campaign;
use App\Rules\MobileMoneyNumber;
use App\Services\Payments\PaymentManager;
use App\Services\Payments\PspFeeCalculator;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * Formulaire de don public (sections 39, 40, 50).
 *
 * « Le donateur doit pouvoir contribuer rapidement » (section 84). Pas de
 * compte, pas d'inscription : un montant, un numero, c'est tout. Le nom et
 * l'adresse restent facultatifs — on peut donner sans se presenter.
 *
 * AUCUN CHAMP DE MESSAGE (section 42), et ce n'est pas un oubli d'interface :
 * il n'existe aucune colonne pour en accueillir un.
 *
 * Ce composant est ouvert au public : il ne fait confiance a rien de ce qu'il
 * recoit. Le montant saisi est revalide cote serveur, les frais et le total
 * sont recalcules par `CreateDonation`, et la campagne est celle de la page —
 * jamais un identifiant venu du navigateur (regle 80.4).
 */
class DonationForm extends Component
{
    #[Locked]
    public Campaign $campaign;

    /**
     * Montant du don, en francs.
     *
     * Le seul chiffre que le donateur choisit. Les frais et le total, eux,
     * sont calcules par le serveur (sections 49, 50).
     */
    public ?int $amount = null;

    public string $donorName = '';

    public string $donorEmail = '';

    public bool $isAnonymous = false;

    public string $provider = '';

    public string $payerPhone = '';

    public bool $showForm = false;

    /**
     * Montants proposes.
     *
     * Un donateur qui hesite donne moins, ou renonce. Quatre propositions
     * lisibles valent mieux qu'un champ vide — le montant libre reste
     * disponible juste a cote.
     *
     * @var list<int>
     */
    public const PRESETS = [1_000, 5_000, 10_000, 25_000];

    public function mount(Campaign $campaign): void
    {
        $this->campaign = $campaign;
    }

    protected function rules(): array
    {
        return [
            'amount' => [
                'required',
                'integer',
                'min:'.CreateDonation::MINIMUM,
                'max:'.CreateDonation::MAXIMUM,
            ],
            // Section 40 : donner sans laisser son nom est un droit, pas un
            // oubli. Le montant, lui, sera public dans tous les cas.
            'donorName' => ['nullable', 'string', 'max:80'],
            'donorEmail' => ['nullable', 'email', 'max:255'],
            'isAnonymous' => ['boolean'],
            'provider' => ['required', 'in:'.implode(',', PaymentProvider::values())],
            'payerPhone' => ['required', 'string', 'max:20', new MobileMoneyNumber($this->selectedProvider())],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'amount' => 'montant du don',
            'donorName' => 'nom',
            'donorEmail' => 'adresse email',
            'provider' => 'moyen de paiement',
            'payerPhone' => 'numero de telephone',
        ];
    }

    protected function messages(): array
    {
        return [
            'amount.required' => 'Indiquez le montant de votre don.',
            'amount.min' => 'Le don minimum est de '.CreateDonation::MINIMUM.' F.',
            'provider.required' => 'Choisissez Airtel Money ou Moov Money.',
            'provider.in' => 'Choisissez Airtel Money ou Moov Money.',
        ];
    }

    private function selectedProvider(): ?PaymentProvider
    {
        return PaymentProvider::tryFrom($this->provider);
    }

    public function choose(int $amount): void
    {
        $this->amount = $amount;
        $this->showForm = true;
    }

    /**
     * Frais du prestataire, a la charge du donateur (decision D-023).
     *
     * Affiches, jamais decides ici : le montant reellement debite est recalcule
     * par `CreateDonation` a partir du meme service. Cet affichage ne peut donc
     * pas faire foi (regle 80.5).
     */
    public function pspFee(): int
    {
        $amount = max(0, (int) $this->amount);

        return $amount > 0 ? app(PspFeeCalculator::class)->feeFor($amount)->amount : 0;
    }

    public function total(): int
    {
        return max(0, (int) $this->amount) + $this->pspFee();
    }

    public function donate(CreateDonation $createDonation, PaymentManager $payments): void
    {
        $this->validate();

        try {
            $donation = $createDonation->handle(
                campaign: $this->campaign,
                amount: (int) $this->amount,
                provider: PaymentProvider::from($this->provider),
                payerPhone: trim($this->payerPhone),
                donorName: trim($this->donorName) ?: null,
                donorEmail: trim($this->donorEmail) ?: null,
                isAnonymous: $this->isAnonymous,
                ipAddress: request()->ip(),
            );
        } catch (RuntimeException $e) {
            // La collecte a pu se fermer pendant la saisie : le message dit
            // precisement quoi.
            $this->addError('amount', $e->getMessage());

            return;
        }

        /*
         * Declenche le push USSD (section 21.1).
         *
         * Un echec ici ne remet pas le don en cause : il existe, son echeance
         * court, et le donateur peut relancer depuis l'ecran de suivi.
         */
        try {
            $initialization = $payments->driver()->initializePayment($donation);

            if ($initialization->providerReference) {
                $donation->forceFill(['provider_reference' => $initialization->providerReference])->save();
            }
        } catch (Throwable $e) {
            report($e);
        }

        // Lien signe : le donateur n'a pas de compte, ce lien est ce qui le
        // ramenera a son don (decision D-004).
        $this->redirect($donation->statusUrl(), navigate: true);
    }

    public function render()
    {
        return view('livewire.public.donation-form', [
            'providers' => PaymentProvider::cases(),
            'presets' => self::PRESETS,
            'pspFee' => $this->pspFee(),
            'total' => $this->total(),
        ]);
    }
}

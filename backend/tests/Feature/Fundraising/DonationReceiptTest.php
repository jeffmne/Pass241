<?php

use App\Actions\Donations\CreateDonation;
use App\Actions\Donations\RefundDonation;
use App\Actions\Payments\ConfirmPayment;
use App\Enums\PaymentProvider;
use App\Mail\DonationReceived;
use App\Models\Campaign;
use App\Models\Commission;
use App\Models\Donation;
use App\Services\Fundraising\DonationReceiptPdf;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Mail;

/*
 * Recu de don (sections 53, 69).
 *
 * « Apres confirmation, generer un recu : reference unique, campagne, montant,
 * date, methode de paiement, identite si applicable. Aucun message personnel ne
 * doit etre inclus. »
 */

beforeEach(function () {
    Mail::fake();

    [$this->owner, $this->organization] = organizerWithOrganization('Association test');

    $this->organization->forceFill(['contact_email' => 'contact@association.test'])->save();

    $this->campaign = forOrganization($this->organization, fn () => Campaign::factory()
        ->published()
        ->create([
            'name' => 'Operation cartables 2026',
            'beneficiary_label' => 'Ecole publique de Nzeng-Ayong',
            'goal_amount' => 1_000_000,
        ]));

    app(OrganizationContext::class)->forget();

    $this->give = fn (array $overrides = []) => app(CreateDonation::class)->handle(
        campaign: $this->campaign,
        amount: $overrides['amount'] ?? 25_000,
        provider: PaymentProvider::Airtel,
        payerPhone: '074000001',
        donorName: $overrides['name'] ?? 'Marie Nzeng',
        donorEmail: array_key_exists('email', $overrides) ? $overrides['email'] : 'marie@exemple.ga',
        isAnonymous: $overrides['anonymous'] ?? false,
    );

    $this->confirm = fn (Donation $d) => app(ConfirmPayment::class)->handle($d, 'FAKE-1');
});

/*
 * ---------------------------------------------------------------------------
 * Le document
 * ---------------------------------------------------------------------------
 */

it('produit un recu en PDF apres confirmation', function () {
    $donation = ($this->confirm)(($this->give)());

    $pdf = app(DonationReceiptPdf::class)->render($donation);

    expect($pdf)->toStartWith('%PDF-')
        ->and(strlen($pdf))->toBeGreaterThan(1_000);
});

it('donne au recu une reference stable, derivee du don', function () {
    $donation = ($this->confirm)(($this->give)());

    $receipts = app(DonationReceiptPdf::class);

    // Deterministe : le meme recu regenere six mois plus tard porte le meme
    // numero, sans qu'aucune sequence n'ait a etre stockee.
    expect($receipts->reference($donation))->toBe($receipts->reference($donation->fresh()))
        ->and($receipts->reference($donation))->toStartWith('RD-')
        ->and($receipts->filename($donation))->toEndWith('.pdf');
});

it('se telecharge par lien signe, jamais autrement', function () {
    $donation = ($this->confirm)(($this->give)());

    // Sans signature, la porte reste fermee : l'identite du donateur figure sur
    // son recu.
    $this->get(route('donations.receipt', $donation->reference))->assertForbidden();

    $this->get($donation->receiptUrl())
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('n emet aucun recu pour un don non confirme', function () {
    $donation = ($this->give)();

    // Attester un versement qui n'a pas eu lieu serait un faux.
    $this->get($donation->receiptUrl())->assertNotFound();
});

/*
 * ---------------------------------------------------------------------------
 * Ce que le recu dit, et ce qu'il tait
 * ---------------------------------------------------------------------------
 */

it('nomme le donateur sur son propre recu, meme anonyme', function () {
    $donation = ($this->confirm)(($this->give)(['anonymous' => true, 'name' => 'Marie Nzeng']));

    $html = renderDonationReceiptHtml($donation);

    /*
     * L'anonymat porte sur l'affichage PUBLIC (sections 40, 41). Ce document
     * est celui du donateur, remis a lui seul par un lien signe : y ecrire
     * « Donateur anonyme » lui rendrait son propre recu inutilisable.
     */
    expect($html)->toContain('Marie Nzeng')
        ->and($html)->toContain('Don anonyme publiquement');
});

it('presente l organisateur comme beneficiaire, jamais Pass241', function () {
    $donation = ($this->confirm)(($this->give)());

    $html = renderDonationReceiptHtml($donation);

    // Section 21.2 : la plateforme encaisse pour le compte de l'organisateur et
    // n'est jamais depositaire des fonds.
    expect($html)->toContain('Association test')
        ->and($html)->toContain('Ecole publique de Nzeng-Ayong')
        ->and($html)->toContain('depositaire definitive des fonds');
});

it('ne fait jamais apparaitre la commission', function () {
    $donation = ($this->confirm)(($this->give)());

    // La commission a bien ete prelevee — c'est ConfirmPayment qui l'ecrit —
    // mais elle n'a rien a faire sur ce document.
    $commission = Commission::query()->acrossOrganizations()->firstOrFail();

    expect($commission->commission_amount)->toBe(625);

    $html = renderDonationReceiptHtml($donation->fresh());

    // Elle se joue entre la plateforme et l'organisateur : le donateur ne l'a
    // pas payee, l'imprimer lui ferait croire l'inverse.
    expect($html)->not->toContain('Commission')
        ->and($html)->not->toContain('625');
});

it('detaille les frais de paiement sur leur propre ligne', function () {
    $donation = ($this->confirm)(($this->give)());

    $html = renderDonationReceiptHtml($donation);

    /*
     * Decision D-023 : le donateur a ete debite de plus que son don. Sans cette
     * ligne, il ne peut pas reconcilier avec son relevé mobile money, et un
     * ecart inexplique se lit comme une tromperie.
     */
    expect($donation->psp_fee_amount)->toBeGreaterThan(0)
        ->and($html)->toContain('Frais de paiement')
        ->and($html)->toContain('Total paye');
});

it('reflete un remboursement ulterieur', function () {
    $donation = ($this->confirm)(($this->give)());

    actAsOrganization($this->organization);

    app(RefundDonation::class)->handle($donation, 10_000, 'Don en double.', $this->owner);

    $html = renderDonationReceiptHtml($donation->fresh());

    // Un recu qui continuerait a attester un don integral apres remboursement
    // serait faux (section 47).
    expect($html)->toContain('Remboursements')
        ->and($html)->toContain('Reste acquis');
});

/*
 * ---------------------------------------------------------------------------
 * L'email (sections 53, 69)
 * ---------------------------------------------------------------------------
 */

it('envoie le recu par email au donateur qui a laisse une adresse', function () {
    ($this->confirm)(($this->give)());

    Mail::assertSent(DonationReceived::class, function ($mail) {
        $attachments = collect($mail->attachments());

        return $mail->hasTo('marie@exemple.ga')
            && $attachments->count() === 1
            && str_starts_with($attachments->first()->as, 'recu-RD-');
    });
});

it('n envoie aucun email quand aucune adresse n a ete laissee', function () {
    ($this->confirm)(($this->give)(['email' => null]));

    // Donner sans laisser son adresse est un droit : ce n'est pas une erreur a
    // signaler.
    Mail::assertNothingSent();
});

it('adresse la reponse a l organisateur, pas a la plateforme', function () {
    ($this->confirm)(($this->give)());

    Mail::assertSent(DonationReceived::class, function ($mail) {
        $mail->assertHasReplyTo('contact@association.test');

        return true;
    });
});

it('compte le don meme si le recu ne part pas', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP indisponible'));

    $donation = ($this->confirm)(($this->give)());

    // L'email est une commodite : un serveur de messagerie indisponible ne doit
    // pas defaire un encaissement.
    expect($donation->fresh()->isConfirmed())->toBeTrue();
});

/**
 * Rend le gabarit du recu en HTML.
 *
 * Le PDF est un binaire : chercher une chaine dedans ne prouve rien. C'est le
 * gabarit qu'on verifie, avec les memes donnees que celles passees au moteur.
 */
function renderDonationReceiptHtml(Donation $donation): string
{
    $donation->loadMissing([
        'campaign' => fn ($query) => $query->acrossOrganizations()->with('organization'),
        'refunds' => fn ($query) => $query->acrossOrganizations()->latest(),
    ]);

    return view('pdf.donation-receipt', [
        'donation' => $donation,
        'campaign' => $donation->campaign,
        'organization' => $donation->campaign?->organization,
        'reference' => app(DonationReceiptPdf::class)->reference($donation),
        'refunds' => $donation->refunds,
        'tz' => config('app.display_timezone'),
    ])->render();
}

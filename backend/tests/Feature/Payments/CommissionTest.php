<?php

use App\Enums\CommissionType;
use App\Models\Commission;
use App\Models\Event;
use App\Models\Order;
use App\Services\Commission\CommissionCalculator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Commission de la plateforme (sections 21.2, 80.1, 80.2).
 *
 * Deux regles absolues : le taux est toujours calcule, jamais saisi ; et une
 * commission enregistree ne se modifie plus.
 */

beforeEach(function () {
    [$this->owner, $this->organization] = organizerWithOrganization('Association test');
    actAsOrganization($this->organization);

    $this->calculator = app(CommissionCalculator::class);
});

it('preleve 5 % sur la billetterie', function () {
    expect($this->calculator->amountFor(10_000, CommissionType::Ticketing))->toBe(500)
        ->and($this->calculator->netFor(10_000, CommissionType::Ticketing))->toBe(9_500);
});

it('preleve 2,5 % sur les collectes', function () {
    expect($this->calculator->amountFor(100_000, CommissionType::Donation))->toBe(2_500)
        ->and($this->calculator->netFor(100_000, CommissionType::Donation))->toBe(97_500);
});

it('calcule en entiers, sans jamais passer par un flottant', function () {
    // 2,5 % ne se represente pas exactement en binaire. Sur 333 FCFA, un
    // calcul flottant produit 8,325 puis, selon l'arrondi retenu, 8 ou 9.
    // L'arithmetique entiere donne toujours le meme resultat.
    $commission = $this->calculator->amountFor(333, CommissionType::Donation);

    expect($commission)->toBeXaf()
        ->and($commission)->toBe(8);
});

it('arrondit au franc le plus proche', function () {
    // 5 % de 1 010 = 50,5 → 51 au plus proche.
    expect($this->calculator->amountFor(1_010, CommissionType::Ticketing))->toBe(51);

    // 5 % de 1 009 = 50,45 → 50.
    expect($this->calculator->amountFor(1_009, CommissionType::Ticketing))->toBe(50);
});

it('ne preleve rien sur un montant nul', function () {
    // Un billet gratuit ne genere aucune commission.
    expect($this->calculator->amountFor(0, CommissionType::Ticketing))->toBe(0)
        ->and($this->calculator->netFor(0, CommissionType::Ticketing))->toBe(0);
});

it('reconcilie toujours brut, commission et net', function () {
    foreach ([1, 7, 99, 333, 1_010, 12_345, 999_999] as $gross) {
        $commission = $this->calculator->amountFor($gross, CommissionType::Ticketing);
        $net = $this->calculator->netFor($gross, CommissionType::Ticketing);

        // La somme doit tomber juste, sinon un rapport de revenus devient faux.
        expect($commission + $net)->toBe($gross);
    }
});

it('fige le taux applique au moment de la transaction', function () {
    $order = Order::factory()->for(Event::factory()->published())->paid()->create(['amount' => 20_000]);

    $commission = $this->calculator->record($order, $this->organization, 20_000, CommissionType::Ticketing);

    // Le taux change pour l'avenir...
    config()->set('commission.basis_points.TICKETING', 900);

    // ...mais la transaction passee garde le sien. Sans cela, un rapport
    // recalcule six mois plus tard donnerait des chiffres differents de ceux
    // qui ont ete reverses.
    expect($commission->fresh()->rate_basis_points)->toBe(500)
        ->and($commission->fresh()->commission_amount)->toBe(1_000)
        ->and($commission->fresh()->formattedRate())->toBe('5 %');
});

it('n enregistre qu une seule commission par transaction', function () {
    $order = Order::factory()->for(Event::factory()->published())->paid()->create(['amount' => 20_000]);

    $first = $this->calculator->record($order, $this->organization, 20_000, CommissionType::Ticketing);
    $second = $this->calculator->record($order, $this->organization, 20_000, CommissionType::Ticketing);

    // Un webhook rejoue ne doit pas produire une seconde commission
    // (section 23).
    expect($second->id)->toBe($first->id)
        ->and(Commission::count())->toBe(1);
});

it('refuse une commission incoherente au niveau de la base', function () {
    $order = Order::factory()->for(Event::factory()->published())->paid()->create();

    // brut ≠ commission + net : une erreur comptable qui passerait inapercue
    // dans un rapport.
    expect(fn () => Commission::create([
        'organization_id' => $this->organization->id,
        'commissionable_type' => $order->getMorphClass(),
        'commissionable_id' => $order->id,
        'type' => 'TICKETING',
        'gross_amount' => 10_000,
        'rate_basis_points' => 500,
        'commission_amount' => 500,
        'net_amount' => 8_000,
    ]))->toThrow(QueryException::class);
});

it('refuse la modification d une commission', function () {
    $order = Order::factory()->for(Event::factory()->published())->paid()->create(['amount' => 10_000]);
    $commission = $this->calculator->record($order, $this->organization, 10_000, CommissionType::Ticketing);

    $commission->commission_amount = 0;

    expect(fn () => $commission->save())->toThrow(RuntimeException::class);
});

it('refuse la modification directement en base', function () {
    $order = Order::factory()->for(Event::factory()->published())->paid()->create(['amount' => 10_000]);
    $commission = $this->calculator->record($order, $this->organization, 10_000, CommissionType::Ticketing);

    // Contournement d'Eloquent : la base doit refuser d'elle-meme.
    expect(fn () => DB::transaction(
        fn () => DB::table('commissions')->where('id', $commission->id)->update(['commission_amount' => 0])
    ))->toThrow(QueryException::class);

    expect($commission->fresh()->commission_amount)->toBe(500);
});

it('refuse la suppression d une commission', function () {
    $order = Order::factory()->for(Event::factory()->published())->paid()->create(['amount' => 10_000]);
    $commission = $this->calculator->record($order, $this->organization, 10_000, CommissionType::Ticketing);

    expect(fn () => $commission->delete())->toThrow(RuntimeException::class);
});

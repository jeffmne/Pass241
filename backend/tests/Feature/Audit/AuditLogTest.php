<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Journal d'audit en ajout seul (sections 46, 67, 68).
 *
 * Un journal que l'on peut reecrire ne prouve rien. La protection est verifiee
 * a deux niveaux : le modele, pour une erreur lisible cote applicatif, et la
 * base de donnees, parce qu'un futur script, une commande artisan ou une
 * console SQL ne passera pas par Eloquent.
 */

beforeEach(function () {
    [$this->user, $this->organization] = organizerWithOrganization();
    actAsOrganization($this->organization);

    $this->log = AuditLog::action(AuditAction::OrganizationCreated)->firstOrFail();
});

it('refuse la modification d une entree via le modele', function () {
    $this->log->action = AuditAction::PaymentConfirmed->value;

    expect(fn () => $this->log->save())->toThrow(RuntimeException::class);
});

it('refuse la suppression d une entree via le modele', function () {
    expect(fn () => $this->log->delete())->toThrow(RuntimeException::class);
});

/*
 * Les trois tests suivants passent par DB::transaction().
 *
 * Sous PostgreSQL, une instruction en echec avorte toute la transaction en
 * cours : les requetes suivantes echoueraient a leur tour et l'assertion finale
 * ne prouverait rien. La transaction imbriquee pose un SAVEPOINT, de sorte que
 * seule l'instruction refusee est annulee et que la ligne reste verifiable.
 */

it('refuse la modification directement en base', function () {
    // Contournement d'Eloquent : la base doit refuser d'elle-meme.
    expect(fn () => DB::transaction(
        fn () => DB::table('audit_logs')->where('id', $this->log->id)->update(['action' => 'FALSIFIE'])
    ))->toThrow(QueryException::class);

    expect(AuditLog::find($this->log->id)->action)->toBe(AuditAction::OrganizationCreated);
});

it('refuse la suppression directement en base', function () {
    expect(fn () => DB::transaction(
        fn () => DB::table('audit_logs')->where('id', $this->log->id)->delete()
    ))->toThrow(QueryException::class);

    expect(AuditLog::whereKey($this->log->id)->exists())->toBeTrue();
});

it('refuse de vider la table', function () {
    expect(fn () => DB::transaction(
        fn () => DB::table('audit_logs')->delete()
    ))->toThrow(QueryException::class);

    expect(AuditLog::count())->toBeGreaterThan(0);
});

it('enregistre l acteur, l organisation et le contexte de la requete', function () {
    expect($this->log->user_id)->toBe($this->user->id)
        ->and($this->log->organization_id)->toBe($this->organization->id)
        ->and($this->log->auditable_type)->toBe(Organization::class)
        ->and($this->log->created_at)->not->toBeNull();
});

it('ne porte pas de date de modification', function () {
    // La colonne n'existe pas : une entree d'audit n'est jamais mise a jour.
    expect(AuditLog::UPDATED_AT)->toBeNull()
        ->and(DB::getSchemaBuilder()->hasColumn('audit_logs', 'updated_at'))->toBeFalse();
});

it('reste lisible au-dela du contexte d organisation', function () {
    // Section 58 : le Super Admin doit pouvoir consulter le journal. Le modele
    // ne porte donc pas de portee globale, le filtrage est explicite.
    app(OrganizationContext::class)->forget();

    expect(AuditLog::count())->toBeGreaterThan(0)
        ->and(AuditLog::forOrganization($this->organization)->count())->toBeGreaterThan(0);
});

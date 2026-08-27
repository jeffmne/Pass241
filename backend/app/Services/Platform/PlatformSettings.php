<?php

namespace App\Services\Platform;

use App\Enums\AuditAction;
use App\Enums\CommissionType;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Reglages de la plateforme (sections 21.2, 58).
 *
 * POINT UNIQUE DE LECTURE ET D'ECRITURE. Le reste du produit demande « le taux
 * de commission » sans savoir s'il vient de la base ou de la configuration —
 * c'est ce qui permet de le rendre modifiable sans toucher au calcul.
 *
 * TROIS GARANTIES :
 *
 * 1. LE PASSE NE BOUGE JAMAIS. Chaque commission porte un instantane de son
 *    taux (decision D-003). Changer le taux aujourd'hui ne change rien aux
 *    transactions d'hier, ni aux reversements calcules a partir d'elles.
 *
 * 2. LA CONFIGURATION RESTE LE SOCLE. Une cle absente de la base retombe sur
 *    `config/commission.php` : la plateforme fonctionne meme si la table est
 *    vide, et une installation neuve n'a rien a initialiser.
 *
 * 3. TOUT CHANGEMENT EST NOMINATIF ET JOURNALISE. Un taux qui bouge sans trace
 *    serait indefendable le jour ou un organisateur conteste son reversement :
 *    l'ancienne et la nouvelle valeur figurent au journal.
 *
 * Aucun secret ne passe par ici (voir la migration) : cles du prestataire,
 * identifiants S3 et mot de passe SMTP restent en variables d'environnement.
 */
class PlatformSettings
{
    /**
     * Bornes du taux de commission, en points de base.
     *
     * Le plancher est zero — une plateforme peut vouloir renoncer a sa
     * commission sur une operation. Le plafond est 30 % : au-dela, ce n'est
     * plus une commission, c'est une erreur de saisie, et elle se paierait sur
     * l'argent d'un organisateur.
     */
    public const MIN_BASIS_POINTS = 0;

    public const MAX_BASIS_POINTS = 3_000;

    private const CACHE_KEY = 'platform-settings';

    public function __construct(private readonly AuditLogger $audit) {}

    /** Taux applique aujourd'hui a un type de transaction, en points de base. */
    public function commissionBasisPoints(CommissionType $type): int
    {
        $key = 'commission.basis_points.'.$type->value;

        $stored = $this->all()[$key] ?? null;

        // Retombee sur la configuration : la plateforme fonctionne meme si la
        // table est vide.
        return $stored !== null
            ? (int) $stored
            : (int) config('commission.basis_points.'.$type->value);
    }

    /**
     * Change le taux d'un type de transaction.
     *
     * @throws InvalidArgumentException si la valeur sort des bornes
     */
    public function setCommissionBasisPoints(CommissionType $type, int $basisPoints, User $actor): void
    {
        if ($basisPoints < self::MIN_BASIS_POINTS || $basisPoints > self::MAX_BASIS_POINTS) {
            throw new InvalidArgumentException(sprintf(
                'Un taux de commission se situe entre %s et %s %%.',
                self::MIN_BASIS_POINTS / 100,
                self::MAX_BASIS_POINTS / 100,
            ));
        }

        $key = 'commission.basis_points.'.$type->value;
        $previous = $this->commissionBasisPoints($type);

        if ($previous === $basisPoints) {
            return;
        }

        PlatformSetting::updateOrCreate(
            ['key' => $key],
            ['value' => $basisPoints, 'updated_by' => $actor->getKey()],
        );

        $this->forget();

        $this->audit->log(
            AuditAction::PlatformSettingChanged,
            null,
            [
                'setting' => $key,
                // Les deux valeurs : le journal doit dire ce qui a change, pas
                // seulement qu'il y a eu un changement.
                'from' => $previous,
                'to' => $basisPoints,
                'type' => $type->label(),
            ],
            actor: $actor,
        );
    }

    /**
     * Toutes les valeurs stockees, en une requete.
     *
     * Mise en cache : ces valeurs sont lues a chaque calcul de commission,
     * c'est-a-dire a chaque paiement confirme. Le cache est vide des qu'une
     * valeur change — un taux qui mettrait cinq minutes a prendre effet serait
     * une source de confusion pendant ces cinq minutes.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
            // Les modeles sont charges plutot que `pluck` : c'est ce qui
            // applique la conversion JSON de la colonne. Un `pluck` rendrait la
            // chaine brute, et « 500 » n'est pas 500.
            fn () => PlatformSetting::query()
                ->get()
                ->mapWithKeys(fn (PlatformSetting $setting) => [$setting->key => $setting->value])
                ->all(),
        );
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}

<?php

namespace App\Services\Payments;

use InvalidArgumentException;
use RuntimeException;

/**
 * Resout le prestataire de paiement a utiliser.
 *
 * Point d'entree unique : le reste du code demande « le prestataire », jamais
 * une classe precise. C'est ce qui permet de brancher PVit, PAYNALA, Digitech
 * Africa ou un autre sans toucher a la logique metier (section 21).
 *
 * Depuis le jalon B, deux faces distinctes (decision D-024) :
 *
 * - `driver()` rend l'ENCAISSEMENT, toujours disponible ;
 * - `settlement()` rend le REVERSEMENT, qui peut ne pas exister.
 *
 * Cette asymetrie est le fait reel : un agregateur peut savoir prendre un
 * paiement mobile money sans exposer la moindre API de virement sortant.
 */
class PaymentManager
{
    /** @var array<string, class-string<CollectionProvider>> */
    private array $providers = [
        'fake' => FakeProvider::class,
    ];

    /** Prestataire d'encaissement. */
    public function driver(?string $name = null): CollectionProvider
    {
        $name ??= (string) config('payments.default');

        $class = $this->providers[$name] ?? throw new InvalidArgumentException(
            sprintf(
                'Prestataire de paiement inconnu : « %s ». Prestataires disponibles : %s.',
                $name,
                implode(', ', array_keys($this->providers)),
            )
        );

        return app($class);
    }

    /**
     * Prestataire de reversement.
     *
     * Echoue bruyamment plutot que de rendre null : un appelant qui demande a
     * reverser doit s'arreter net si le prestataire ne sait pas le faire.
     * Rendre null laisserait passer un `?->` silencieux, et un reversement qui
     * n'a jamais eu lieu ne se remarque que le jour ou l'organisateur
     * telephone.
     */
    public function settlement(?string $name = null): SettlementProvider
    {
        $driver = $this->driver($name);

        if (! $driver instanceof SettlementProvider) {
            throw new RuntimeException(sprintf(
                'Le prestataire « %s » ne sait pas reverser. Aucun versement '
                .'automatique n\'est possible avec lui (section 21.2).',
                $driver->name(),
            ));
        }

        return $driver;
    }

    public function supportsSettlement(?string $name = null): bool
    {
        return $this->driver($name) instanceof SettlementProvider
            && $this->capabilities($name)->canSettle();
    }

    public function capabilities(?string $name = null): PaymentCapabilities
    {
        return $this->driver($name)->capabilities();
    }

    /** @return list<string> */
    public function available(): array
    {
        return array_keys($this->providers);
    }

    public function isFake(): bool
    {
        return config('payments.default') === 'fake';
    }
}

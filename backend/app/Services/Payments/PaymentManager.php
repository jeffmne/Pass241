<?php

namespace App\Services\Payments;

use InvalidArgumentException;

/**
 * Resout le provider de paiement a utiliser.
 *
 * Point d'entree unique : le reste du code demande « le provider », jamais une
 * classe precise. C'est ce qui permettra de brancher Airtel et Moov, ou de
 * passer par un agregateur, sans toucher a la logique metier (section 21).
 */
class PaymentManager
{
    /** @var array<string, class-string<PaymentProviderInterface>> */
    private array $providers = [
        'fake' => FakeProvider::class,
    ];

    public function driver(?string $name = null): PaymentProviderInterface
    {
        $name ??= (string) config('payments.default');

        $class = $this->providers[$name] ?? throw new InvalidArgumentException(
            sprintf(
                'Provider de paiement inconnu : « %s ». Providers disponibles : %s.',
                $name,
                implode(', ', array_keys($this->providers)),
            )
        );

        return app($class);
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

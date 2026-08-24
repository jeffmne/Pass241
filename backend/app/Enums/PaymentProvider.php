<?php

namespace App\Enums;

/**
 * Moyens de paiement (sections 16.1, 21).
 *
 * Le provider est toujours le choix explicite de l'acheteur, jamais une
 * deduction faite a partir de son numero (section 21.1). Le numero sert
 * ensuite a verifier la coherence de ce choix, pas a le remplacer.
 */
enum PaymentProvider: string
{
    case Airtel = 'AIRTEL';
    case Moov = 'MOOV';

    public function label(): string
    {
        return match ($this) {
            self::Airtel => 'Airtel Money',
            self::Moov => 'Moov Money',
        };
    }

    /**
     * Prefixes nationaux acceptes pour cet operateur.
     *
     * Lus depuis la configuration : ces valeurs relevent des operateurs, pas du
     * code, et changent sans prevenir. Une liste vide desactive la verification
     * de prefixe pour cet operateur — voir config/payments.php.
     *
     * @return list<string>
     */
    public function prefixes(): array
    {
        return config('payments.prefixes.'.$this->value, []);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}

<?php

namespace App\Rules;

use App\Enums\PaymentProvider;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Numero de telephone compatible avec l'operateur choisi (section 16.1).
 *
 * Deux verifications distinctes :
 *
 * 1. le format. Toujours appliquee. Un numero mal forme ne donnera jamais lieu
 *    a un push USSD, autant le dire tout de suite ;
 * 2. l'appartenance a l'operateur. Appliquee seulement si des prefixes sont
 *    configures pour cet operateur (config/payments.php).
 *
 * Le second point est volontairement conditionnel. Les plans de numerotation
 * appartiennent aux operateurs et changent sans preavis : un prefixe manquant
 * dans notre configuration refuserait un client parfaitement legitime au
 * moment de payer. Tant que les listes n'ont pas ete confirmees aupres
 * d'Airtel et de Moov, la verification reste desactivee — et le mecanisme,
 * lui, est en place et teste.
 */
class MobileMoneyNumber implements ValidationRule
{
    public function __construct(private readonly ?PaymentProvider $provider) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = self::digits((string) $value);

        // Gabon : 8 chiffres en national, jusqu'a 11 avec l'indicatif 241.
        if (! preg_match('/^(241)?\d{8,9}$/', $digits)) {
            $fail('Entrez un numero de telephone valide, par exemple 074 00 00 00.');

            return;
        }

        if ($this->provider === null) {
            return;
        }

        $prefixes = $this->provider->prefixes();

        if ($prefixes === []) {
            return;
        }

        $national = self::national($digits);

        foreach ($prefixes as $prefix) {
            if (str_starts_with($national, $prefix)) {
                return;
            }
        }

        // Section 16.1 : le message doit inviter a corriger le numero OU a
        // changer d'operateur. Dire seulement « numero invalide » laisserait
        // l'acheteur bloque sans comprendre ce qu'on attend de lui.
        $fail(sprintf(
            'Ce numero ne correspond pas a %s. Corrigez-le ou choisissez l\'autre operateur.',
            $this->provider->label()
        ));
    }

    /** Ne garde que les chiffres : les espaces et les tirets sont du confort. */
    public static function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /** Numero national, indicatif pays retire. */
    public static function national(string $digits): string
    {
        return str_starts_with($digits, '241') ? substr($digits, 3) : $digits;
    }
}

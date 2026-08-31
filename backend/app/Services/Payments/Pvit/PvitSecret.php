<?php

namespace App\Services\Payments\Pvit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cle secrete PVit : obtention, mise en cache et renouvellement.
 *
 * TOUTES LES REQUETES PVIT SONT AUTHENTIFIEES PAR UN EN-TETE `X-Secret`, et
 * cette cle est TEMPORAIRE — 3 600 secondes annoncees par l'API elle-meme. Elle
 * ne peut donc pas vivre dans la configuration : ce qui vit dans
 * l'environnement, c'est le mot de passe qui autorise sa rotation.
 *
 * LA CLE EST MISE EN CACHE, ET C'EST NECESSAIRE. La renouveler a chaque appel
 * ajouterait un aller-retour reseau devant chaque paiement — sur les connexions
 * du marche vise, cela se voit. Le cache est volontairement plus court que la
 * duree de vie annoncee : une cle qui expire pendant qu'on s'en sert produit un
 * echec de paiement, une cle renouvelee un peu tot ne coute qu'une requete.
 *
 * LE RENOUVELLEMENT FORCE EXISTE POUR UNE RAISON PRECISE : entre le moment ou
 * l'on lit le cache et celui ou PVit traite la requete, la cle peut avoir ete
 * invalidee — rotation declenchee ailleurs, redemarrage de leur cote. Le driver
 * rejoue alors UNE fois avec une cle fraiche. Sans ce rattrapage, un incident
 * chez le prestataire se traduirait par des paiements refuses cote client.
 */
class PvitSecret
{
    /**
     * Marge avant l'expiration annoncee.
     *
     * PVit annonce 3 600 secondes ; on garde la cle 3 300. Les 300 secondes
     * d'ecart couvrent une requete lente et l'ecart d'horloge entre nos
     * serveurs et les leurs.
     */
    private const CACHE_SECONDS = 3_300;

    public function get(bool $fresh = false): string
    {
        $key = $this->cacheKey();

        if ($fresh) {
            Cache::forget($key);
        }

        $secret = Cache::remember($key, self::CACHE_SECONDS, fn () => $this->renew());

        if (! is_string($secret) || $secret === '') {
            Cache::forget($key);

            throw new RuntimeException('PVit n\'a pas renvoye de cle secrete exploitable.');
        }

        return $secret;
    }

    /** Oublie la cle en cache, sans en demander une nouvelle. */
    public function forget(): void
    {
        Cache::forget($this->cacheKey());
    }

    /**
     * Demande une nouvelle cle a PVit.
     *
     * Les parametres partent en `application/x-www-form-urlencoded`, comme
     * l'exige la documentation. Ce n'est pas indifferent : le mot de passe de
     * rotation contient couramment des caracteres — parentheses, arobase — qui
     * demanderaient un encodage soigneux dans une chaine de requete, et qu'une
     * erreur d'encodage transformerait en « mot de passe invalide » sans autre
     * explication.
     */
    private function renew(): string
    {
        $config = $this->config();

        $response = Http::asForm()
            ->timeout(20)
            ->post($config['base_url'].$config['endpoints']['renew_secret'], [
                'operationAccountCode' => $config['operation_account_code'],
                'password' => $config['secret_password'],
            ]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'PVit a refuse la rotation de la cle secrete (HTTP %d) : %s',
                $response->status(),
                // Le corps est tronque : il peut contenir un echo du mot de
                // passe selon l'erreur, et il finirait dans les journaux.
                mb_substr((string) $response->json('message', $response->body()), 0, 200),
            ));
        }

        return (string) $response->json('secret', '');
    }

    /**
     * Cle de cache, PORTEE PAR LE COMPTE D'OPERATION.
     *
     * Une cle secrete est propre a un compte d'operation. Les melanger ferait
     * authentifier un paiement avec la cle d'un autre compte le jour ou
     * plusieurs comptes coexisteront — test et production, ou un compte par
     * activite.
     */
    private function cacheKey(): string
    {
        return 'pvit:secret:'.(string) config('payments.pvit.operation_account_code');
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $config = (array) config('payments.pvit');

        foreach (['operation_account_code', 'secret_password'] as $required) {
            if (blank($config[$required] ?? null)) {
                throw new RuntimeException(sprintf(
                    'Configuration PVit incomplete : %s est vide. '
                    .'Renseignez PVIT_%s dans l\'environnement.',
                    $required,
                    mb_strtoupper($required),
                ));
            }
        }

        return $config;
    }
}

<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Etat des services dont la plateforme depend (section 72).
 *
 * `/up`, la sonde livree par Laravel, repond 200 des que le framework demarre.
 * Elle ne dit donc rien de ce qui compte ici : une application qui demarre
 * parfaitement mais ne joint plus PostgreSQL est en panne, et une supervision
 * qui la declare vivante retarde l'alerte jusqu'au premier acheteur qui
 * echoue. C'est precisement le moment ou l'on ne veut pas l'apprendre.
 *
 * CHAQUE VERIFICATION FAIT UN ALLER-RETOUR REEL. Lire une variable de
 * configuration ne prouve rien : elle est renseignee que le service reponde ou
 * non.
 *
 * AUCUNE VERIFICATION NE LEVE. Une sonde qui repond 500 au lieu de 503 ne
 * distingue plus « le service surveille est tombe » de « la sonde est cassee »,
 * et c'est la sonde qu'on soupconnera en premier.
 *
 * LA FILE D'ATTENTE EST SURVEILLEE COMME LES AUTRES. C'est elle qui porte
 * l'envoi des billets et le traitement des callbacks de paiement : un worker
 * arrete ne casse aucune page, et laisse pourtant des acheteurs sans billet.
 * Une panne silencieuse est celle qui dure le plus longtemps.
 */
class HealthCheck
{
    /**
     * Etat de chaque service.
     *
     * @return array<string, array{ok: bool, label: string, detail: string}>
     */
    public function services(): array
    {
        return [
            'database' => $this->check('Base de donnees', fn () => $this->database()),
            'cache' => $this->check('Cache', fn () => $this->cache()),
            'queue' => $this->check('File d\'attente', fn () => $this->queue()),
        ];
    }

    /** Tout va bien ? */
    public function isHealthy(): bool
    {
        foreach ($this->services() as $service) {
            if (! $service['ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Execute une verification sans jamais laisser passer d'exception.
     *
     * @param  callable(): string  $probe  rend un detail lisible, ou leve
     * @return array{ok: bool, label: string, detail: string}
     */
    private function check(string $label, callable $probe): array
    {
        try {
            return ['ok' => true, 'label' => $label, 'detail' => $probe()];
        } catch (Throwable $e) {
            /*
             * Le message de l'exception est repris, mais TRONQUE : une trace
             * PostgreSQL complete peut contenir un fragment de requete, donc
             * des donnees. Cette sortie est lue par une sonde externe et
             * affichee a l'ecran ; elle doit renseigner sans divulguer.
             */
            return [
                'ok' => false,
                'label' => $label,
                'detail' => str($e->getMessage())->limit(120)->toString(),
            ];
        }
    }

    private function database(): string
    {
        // Une vraie requete, et sur une table du produit : `SELECT 1` prouverait
        // que la connexion s'ouvre, pas que le schema est en place.
        $organizations = (int) DB::table('organizations')->count();

        return $organizations.' organisation'.($organizations > 1 ? 's' : '');
    }

    private function cache(): string
    {
        // Ecriture PUIS relecture : un cache qui accepte tout et ne rend rien
        // est le plus difficile a diagnostiquer depuis les ecrans, ou il se
        // manifeste par des chiffres qui ne se figent jamais.
        $key = 'health:'.uniqid();

        Cache::put($key, 'ok', 10);
        $value = Cache::get($key);
        Cache::forget($key);

        if ($value !== 'ok') {
            throw new \RuntimeException('Le cache accepte les ecritures mais ne les rend pas.');
        }

        return 'lecture et ecriture';
    }

    /**
     * La file repond-elle ?
     *
     * ET RIEN D'AUTRE. Les jobs en echec ne sont VOLONTAIREMENT pas comptes
     * ici : ils relevent de la supervision, pas de la sante du service.
     *
     * La distinction n'est pas cosmetique, elle decide de qui est reveille la
     * nuit. Cette sonde est interrogee par un moniteur externe dont le seul
     * verdict est « le service repond ou non » ; y faire entrer un email
     * d'invitation en echec ferait sonner une alerte de panne pour quelque
     * chose qui n'empeche personne d'acheter un billet. Une alerte qui se
     * declenche pour rien finit par ne plus etre lue, et c'est alors la vraie
     * panne qu'on rate.
     *
     * Ce qui demande une intervention humaine sans etre une panne s'affiche sur
     * l'ecran de supervision.
     */
    private function queue(): string
    {
        Redis::connection()->ping();

        return 'connexion etablie';
    }
}

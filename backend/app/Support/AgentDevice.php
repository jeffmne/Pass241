<?php

namespace App\Support;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * Lie un jeton Sanctum a l'appareil qui l'a obtenu.
 *
 * L'identifiant d'appareil n'est pas un secret, mais il fait partie de la
 * preuve d'idempotence et de la piste d'audit des controles. Une application
 * ne doit donc jamais pouvoir presenter le jeton d'un appareil et attribuer
 * ses scans a un autre identifiant fourni dans le corps de la requete.
 */
final class AgentDevice
{
    private const TOKEN_PREFIX = 'agent-device:';

    public static function tokenName(string $deviceId): string
    {
        return self::TOKEN_PREFIX.$deviceId;
    }

    public static function matches(PersonalAccessToken $token, string $deviceId): bool
    {
        return hash_equals(self::tokenName($deviceId), $token->name);
    }
}

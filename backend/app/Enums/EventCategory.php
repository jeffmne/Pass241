<?php

namespace App\Enums;

/**
 * Categories d'un evenement (sections 11, 15.2).
 *
 * Liste fermee plutot que mots-cles libres : les categories apparaissent sur
 * la page publique et sur le billet (« Musique • Culture • Lifestyle »), et
 * une saisie libre y produirait rapidement des doublons de casse et
 * d'orthographe impossibles a exploiter ensuite.
 *
 * La liste couvre les usages decrits en section 3 : associations et BDE,
 * festivals et concerts, entreprises et agences, particuliers.
 */
enum EventCategory: string
{
    case Music = 'MUSIC';
    case Culture = 'CULTURE';
    case Party = 'PARTY';
    case Sport = 'SPORT';
    case Conference = 'CONFERENCE';
    case Training = 'TRAINING';
    case Gala = 'GALA';
    case Solidarity = 'SOLIDARITY';
    case Community = 'COMMUNITY';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Music => 'Musique',
            self::Culture => 'Culture',
            self::Party => 'Soiree',
            self::Sport => 'Sport',
            self::Conference => 'Conference',
            self::Training => 'Formation',
            self::Gala => 'Gala',
            self::Solidarity => 'Solidarite',
            self::Community => 'Vie associative',
            self::Other => 'Autre',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}

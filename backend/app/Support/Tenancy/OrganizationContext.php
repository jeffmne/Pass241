<?php

namespace App\Support\Tenancy;

use App\Models\Organization;
use RuntimeException;

/**
 * Contexte d'organisation de la requete courante (section 9).
 *
 * Regle absolue : ce contexte n'est JAMAIS alimente par une valeur envoyee par
 * le client. Il est etabli par EnsureOrganizationContext a partir de
 * l'utilisateur authentifie et de son appartenance verifiee en base. Un
 * `organizationId` present dans une URL, un formulaire, un en-tete ou un
 * payload JSON est ignore (section 80.4).
 *
 * Instance unique par requete (singleton du conteneur).
 */
class OrganizationContext
{
    private ?Organization $organization = null;

    /**
     * Profondeur d'imbrication des blocs sans portee. Un compteur plutot qu'un
     * booleen : deux appels imbriques a withoutScope() ne doivent pas
     * reactiver la portee en sortant du bloc interieur.
     */
    private int $unscopedDepth = 0;

    public function set(Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function forget(): void
    {
        $this->organization = null;
    }

    public function current(): ?Organization
    {
        return $this->organization;
    }

    public function currentId(): ?int
    {
        return $this->organization?->getKey();
    }

    public function has(): bool
    {
        return $this->organization !== null;
    }

    public function is(Organization|int $organization): bool
    {
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        return $this->currentId() === $id;
    }

    /**
     * Identifiant de l'organisation courante, ou echec.
     *
     * Utilise a la creation d'une ressource : mieux vaut une erreur bruyante
     * qu'une ligne enregistree sans proprietaire, qui deviendrait invisible ou,
     * pire, visible par tous.
     */
    public function requireId(): int
    {
        return $this->currentId() ?? throw new RuntimeException(
            'Aucune organisation dans le contexte. Une ressource multi-tenant ne peut pas etre '
            .'creee ou lue hors contexte : verifier que la route passe par le middleware '
            .'EnsureOrganizationContext.'
        );
    }

    public function isScopeSuspended(): bool
    {
        return $this->unscopedDepth > 0;
    }

    /**
     * Execute un traitement sans filtrage par organisation.
     *
     * Reserve a trois cas, et a aucun autre : les pages publiques (un visiteur
     * consulte un evenement sans etre membre de l'organisation), la
     * supervision Super Admin (section 58), et les traitements en file
     * d'attente qui portent deja leur propre organisation.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withoutScope(callable $callback): mixed
    {
        $this->unscopedDepth++;

        try {
            return $callback();
        } finally {
            $this->unscopedDepth--;
        }
    }

    /**
     * Execute un traitement dans le contexte d'une autre organisation, puis
     * restaure le contexte precedent. Utilise par les jobs et les commandes.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runFor(Organization $organization, callable $callback): mixed
    {
        $previous = $this->organization;
        $this->organization = $organization;

        try {
            return $callback();
        } finally {
            $this->organization = $previous;
        }
    }
}

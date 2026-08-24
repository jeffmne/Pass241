<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * A appliquer sur tout modele appartenant a une organisation : evenements,
 * billets, commandes, participants, agents, campagnes, dons (section 9).
 *
 * Le trait fait trois choses, et les trois comptent :
 *
 * 1. il filtre les lectures sur l'organisation courante ;
 * 2. il renseigne `organization_id` a la creation, sans jamais le lire depuis
 *    l'exterieur ;
 * 3. il refuse toute ecriture qui viserait une autre organisation que celle du
 *    contexte, meme si la valeur a ete forcee dans le modele.
 *
 * Le point 3 est la protection contre l'affectation en masse : un formulaire
 * qui enverrait `organization_id` ne peut pas deplacer une ressource vers une
 * autre organisation, il provoque une erreur.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function ($model) {
            $context = app(OrganizationContext::class);
            $key = $model->organizationForeignKey();

            if ($model->getAttribute($key) === null) {
                $model->setAttribute($key, $context->requireId());

                return;
            }

            // Une valeur explicite n'est acceptee que si elle correspond au
            // contexte, ou si la portee est volontairement suspendue (jobs,
            // seeders, supervision Super Admin).
            if (! $context->isScopeSuspended() && ! $context->is($model->getAttribute($key))) {
                throw new RuntimeException(sprintf(
                    'Tentative de creation d\'un %s dans l\'organisation %s alors que le contexte '
                    .'courant est %s. Une ressource ne peut pas etre creee pour une autre organisation.',
                    class_basename($model),
                    $model->getAttribute($key),
                    $context->currentId() ?? 'absent'
                ));
            }
        });

        static::updating(function ($model) {
            $key = $model->organizationForeignKey();

            if ($model->isDirty($key)) {
                throw new RuntimeException(sprintf(
                    'L\'organisation d\'un %s ne peut pas etre modifiee apres creation.',
                    class_basename($model)
                ));
            }
        });
    }

    public function organizationForeignKey(): string
    {
        return 'organization_id';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, $this->organizationForeignKey());
    }

    /**
     * Retire le filtrage par organisation sur une requete precise.
     *
     * A n'employer que sur les pages publiques, la supervision Super Admin et
     * les traitements en file d'attente. Tout autre usage est un contournement
     * de la section 80.4 et doit etre refuse en revue.
     */
    public function scopeAcrossOrganizations(Builder $query): Builder
    {
        return $query->withoutGlobalScope(OrganizationScope::class);
    }
}

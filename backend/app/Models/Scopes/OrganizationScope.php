<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtre automatiquement toute requete sur l'organisation courante (section 9).
 *
 * Le comportement en l'absence de contexte est deliberement ferme : la requete
 * ne renvoie aucune ligne, au lieu de renvoyer tout. Un developpeur qui oublie
 * le middleware EnsureOrganizationContext obtient ainsi une liste vide — un
 * bug visible et sans danger — plutot que les donnees de toutes les
 * organisations, qui est le pire scenario possible pour ce produit (section
 * 80.4).
 */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(OrganizationContext::class);

        if ($context->isScopeSuspended()) {
            return;
        }

        $column = $model->qualifyColumn($model->organizationForeignKey());

        if (! $context->has()) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($column, $context->currentId());
    }
}

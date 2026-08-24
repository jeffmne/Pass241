<?php

namespace App\Actions\Organizations;

use App\Enums\OrganizationType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Inscription d'un organisateur (section 80.2).
 *
 * Le compte et l'organisation naissent ensemble, dans une seule transaction :
 * sur ce produit un compte sans organisation n'a aucun usage, et un echec a
 * mi-parcours laisserait un compte inutilisable difficile a rattraper.
 *
 * Section 10.1 : aucune verification d'identite n'est exigee ici. L'inscription
 * est libre, l'organisateur peut publier et vendre immediatement — seul le
 * reversement attendra le KYC.
 */
class RegisterOrganizer
{
    public function __construct(private readonly CreateOrganization $createOrganization) {}

    public function handle(
        string $name,
        string $email,
        string $password,
        string $organizationName,
        OrganizationType $organizationType,
        ?string $phone = null,
    ): User {
        return DB::transaction(function () use ($name, $email, $password, $organizationName, $organizationType, $phone) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'password' => $password,
            ]);

            $this->createOrganization->handle(
                owner: $user,
                name: $organizationName,
                type: $organizationType,
            );

            return $user->refresh();
        });
    }
}

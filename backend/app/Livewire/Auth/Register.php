<?php

namespace App\Livewire\Auth;

use App\Actions\Organizations\RegisterOrganizer;
use App\Enums\OrganizationType;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Inscription d'un organisateur (section 80.2).
 *
 * Le compte et l'organisation sont crees ensemble : sur ce produit, un compte
 * sans organisation n'a aucun usage, et demander deux etapes separees ajoute
 * une friction inutile a l'entree du parcours.
 */
#[Layout('components.layouts.guest')]
class Register extends Component
{
    #[Validate('required|string|min:2|max:120')]
    public string $name = '';

    #[Validate('required|email|max:255|unique:users,email')]
    public string $email = '';

    /*
     * Le telephone est obligatoire pour un organisateur.
     *
     * C'est le canal de contact reel sur ce marche : c'est par la qu'on joint
     * quelqu'un le soir d'un evenement, et c'est aussi ce qui rattachera plus
     * tard le compte au reversement de ses recettes (section 21.2).
     *
     * La regle de format reste volontairement permissive : elle accepte les
     * espaces, un prefixe +241 ou 241, et 8 a 9 chiffres. La validation stricte
     * par prefixe d'operateur appartient au parcours de paiement (section 16.1),
     * ou elle a une consequence — refuser ici un numero valide empecherait
     * simplement quelqu'un de creer son compte.
     */
    public string $phone = '';

    #[Validate('required|string|min:2|max:120')]
    public string $organizationName = '';

    /*
     * Pas d'attribut #[Validate] ici : lorsqu'une propriete porte a la fois un
     * attribut et une entree dans rules(), l'attribut l'emporte et la regle du
     * tableau est ignoree en silence. Une valeur inventee passerait alors la
     * validation et casserait plus loin sur OrganizationType::from().
     */
    public string $organizationType = 'ASSOCIATION';

    public string $password = '';

    public string $passwordConfirmation = '';

    protected function rules(): array
    {
        return [
            'password' => ['required', 'string', 'confirmed:passwordConfirmation', Password::min(8)],
            'organizationType' => ['required', 'in:'.implode(',', array_column(OrganizationType::cases(), 'value'))],
            'phone' => ['required', 'string', 'max:20', 'regex:/^(\+?241)?[\s.-]*\d(?:[\s.-]*\d){7,8}$/'],
        ];
    }

    protected function messages(): array
    {
        return [
            'phone.required' => 'Le numero de telephone est obligatoire.',
            'phone.regex' => 'Entrez un numero de telephone valide, par exemple 074 00 00 00.',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'name' => 'nom',
            'email' => 'adresse email',
            'phone' => 'numero de telephone',
            'organizationName' => 'nom de l\'organisation',
            'organizationType' => 'type d\'organisation',
            'password' => 'mot de passe',
        ];
    }

    public function register(RegisterOrganizer $registerOrganizer): void
    {
        // Normalisation avant validation : un numero recopie depuis un contact
        // ou colle depuis WhatsApp arrive souvent avec des espaces autour. Les
        // refuser pour cette raison serait incomprehensible pour l'utilisateur.
        $this->phone = trim($this->phone);
        $this->email = trim($this->email);
        $this->name = trim($this->name);
        $this->organizationName = trim($this->organizationName);

        $this->validate();

        $user = $registerOrganizer->handle(
            name: $this->name,
            email: $this->email,
            password: $this->password,
            organizationName: $this->organizationName,
            organizationType: OrganizationType::from($this->organizationType),
            phone: $this->phone,
        );

        event(new Registered($user));

        Auth::login($user, remember: true);

        // `session()` plutot que `request()->session()` : sur une requete
        // Livewire, la session n'est pas toujours attachee a l'objet Request.
        session()->regenerate();

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.register', [
            'organizationTypes' => OrganizationType::cases(),
        ]);
    }
}

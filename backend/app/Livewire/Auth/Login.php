<?php

namespace App\Livewire\Auth;

use App\Enums\AuditAction;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.layouts.guest')]
class Login extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = true;

    public function login(AuditLogger $audit): void
    {
        $this->validate();

        // Section 66 : limitation du nombre d'essais. La cle combine l'email et
        // l'adresse IP pour ne pas permettre de bloquer le compte d'un tiers
        // depuis l'exterieur en enchainant les echecs sur son adresse.
        $key = Str::transliterate(Str::lower($this->email).'|'.request()->ip());

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 5)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => "Trop de tentatives. Reessayez dans {$seconds} secondes.",
            ]);
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($key, decaySeconds: 60);

            $audit->log(AuditAction::LoginFailed, metadata: ['email' => $this->email]);

            // Message volontairement identique que l'email existe ou non :
            // sinon le formulaire devient un moyen de savoir qui a un compte.
            throw ValidationException::withMessages([
                'email' => 'Ces identifiants ne correspondent a aucun compte.',
            ]);
        }

        RateLimiter::clear($key);

        // `session()` plutot que `request()->session()` : sur une requete
        // Livewire, la session n'est pas toujours attachee a l'objet Request.
        session()->regenerate();

        $user = Auth::user();
        $user->recordLogin();

        $audit->log(
            AuditAction::Login,
            actor: $user,
            organizationId: $user->current_organization_id,
        );

        /*
         * Le Super Admin va a SA console, pas au tableau de bord organisateur.
         *
         * Il n'appartient a aucune organisation : envoye sur `/dashboard`, il
         * etait renvoye vers « creez votre organisation » et se retrouvait dans
         * le monde de l'organisateur, sans aucun moyen de deviner l'adresse de
         * la console — laquelle repond 404 a tout le monde, donc aucun lien ne
         * la trahit.
         */
        $this->redirectIntended(
            $user->isSuperAdmin() ? route('admin.overview') : route('dashboard'),
            navigate: true,
        );
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutController extends Controller
{
    public function __invoke(Request $request, AuditLogger $audit)
    {
        $user = $request->user();

        // Journalise avant la deconnexion : apres, l'acteur n'est plus
        // identifiable et l'entree perdrait son interet (section 68).
        if ($user) {
            $audit->log(
                AuditAction::Logout,
                actor: $user,
                organizationId: $user->current_organization_id,
            );
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}

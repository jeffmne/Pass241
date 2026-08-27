<?php

namespace App\Http\Controllers;

use App\Models\Donation;
use App\Services\Fundraising\DonationReceiptPdf;
use Illuminate\Http\Response;

/**
 * Telechargement du recu de don (section 53).
 *
 * Le recu est une piece comptable qu'on classe, qu'on transmet a un tresorier
 * ou qu'on joint a une note de frais : le PDF est son format naturel.
 *
 * Acces par lien signe, sans authentification : le donateur n'a pas de compte
 * (decision D-004). La signature empeche de parcourir les recus en devinant des
 * references — un don anonyme y perdrait son anonymat, et l'identite d'un
 * donateur figure sur son recu.
 *
 * Un recu n'est emis que sur un don REELLEMENT confirme : attester un versement
 * qui n'a pas eu lieu serait un faux.
 */
class DonationReceiptController extends Controller
{
    public function __invoke(string $reference, DonationReceiptPdf $receipts): Response
    {
        $donation = Donation::publicByReference($reference) ?? abort(404);

        abort_unless($donation->isConfirmed(), 404);

        return response($receipts->render($donation), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$receipts->filename($donation).'"',
        ]);
    }
}

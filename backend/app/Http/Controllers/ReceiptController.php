<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Billing\ReceiptPdf;
use Illuminate\Http\Response;

/**
 * Telechargement du recu de paiement (section 53 adaptee, decision D-029).
 *
 * Contrairement au billet, le recu SE TELECHARGE depuis la plateforme. Les
 * deux documents ne servent pas la meme chose : le billet doit etre le meme
 * objet a l'ecran et dans la galerie du telephone, d'ou l'image ; le recu est
 * une piece comptable qu'on classe, qu'on transmet a un tresorier ou qu'on
 * joint a une note de frais. Le PDF est son format naturel.
 *
 * Acces par lien signe, sans authentification : l'acheteur n'a pas de compte
 * (decision D-004). La signature empeche de parcourir les recus en devinant
 * des references.
 *
 * Un recu n'est emis que sur une commande REELLEMENT payee : attester un
 * paiement qui n'a pas eu lieu serait un faux.
 */
class ReceiptController extends Controller
{
    public function __invoke(string $reference, ReceiptPdf $receipts): Response
    {
        $order = Order::publicByReference($reference) ?? abort(404);

        abort_unless($order->hasBeenPaid(), 404);

        return response($receipts->render($order), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$receipts->filename($order).'"',
        ]);
    }
}

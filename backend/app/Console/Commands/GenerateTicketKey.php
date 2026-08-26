<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Genere la cle de signature des billets (section 18).
 *
 * Commande separee de `key:generate` a dessein : la cle des billets ne doit
 * jamais etre regeneree par reflexe en meme temps que la cle applicative, sous
 * peine d'invalider tous les billets en circulation.
 */
class GenerateTicketKey extends Command
{
    protected $signature = 'pass241:ticket-key';

    protected $description = 'Genere une cle de signature pour les billets (a coller dans TICKET_TOKEN_SIGNING_KEY)';

    public function handle(): int
    {
        $key = 'base64:'.base64_encode(random_bytes(32));

        $this->newLine();
        $this->line('  TICKET_TOKEN_SIGNING_KEY='.$key);
        $this->newLine();

        $this->comment('  A coller dans le fichier .env. Ne jamais la modifier une fois');
        $this->comment('  des billets emis : ils deviendraient tous invalides a l\'entree.');
        $this->newLine();

        return self::SUCCESS;
    }
}

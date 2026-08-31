@php
    use App\Support\Money;
@endphp

{{--
    Parcours d'achat public (sections 15, 16, 16.1).

    Les types de billets sont rendus comme des BILLETS, souche a gauche et
    perforation : c'est le meme objet que celui que l'acheteur recevra apres
    paiement (`x-glass-ticket`). Une liste rendue en lignes de tableau se lit
    comme un tarif ; rendue en billets, elle se lit comme ce qu'on va recevoir.

    Mobile-first : la souche portant le prix, le stepper et la barre collante
    sont dimensionnes pour le pouce d'abord, et s'aerent ensuite sur grand
    ecran. C'est l'ordre reel des visites — le lien arrive par WhatsApp.

    Aucun emoji (regle 80.6). Aucun prix n'est calcule ici : tous les montants
    affiches viennent du serveur et sont recalcules a la creation de la
    commande (regle 80.5).
--}}
<div>
    @if ($ticketTypes->isEmpty())
        <div class="glass-inset p-6 sm:p-8">
            <h2 class="font-display text-xl font-extrabold text-ink sm:text-2xl">Billets</h2>
            <p class="mt-3 leading-relaxed text-grey-500">
                La billetterie de cet evenement ouvre prochainement.
            </p>
        </div>

    @else
        {{-- `checkout-bar-gap` reserve la hauteur de la barre collante : sans
             elle, la barre masque le bouton de paiement qu'elle duplique. --}}
        {{-- Pas de `data-reveal` sur ce panneau : Livewire le re-rend a chaque
             appui sur « + », et le HTML du serveur ne porte pas la classe
             `is-revealed` posee par le navigateur. Le panneau se serait efface
             puis re-anime a chaque clic. La revelation est portee par un
             conteneur exterieur, que Livewire ne touche pas. --}}
        <div @class(['glass-inset p-5 sm:p-8', 'checkout-bar-gap' => $ticketCount > 0])>
            <h2 class="font-display text-xl font-extrabold text-ink sm:text-2xl">Billets</h2>
            <p class="mt-1.5 text-sm leading-relaxed text-grey-500">
                Choisissez votre categorie et payez par mobile money. Votre billet
                s'affiche aussitot, avec le QR Code a presenter a l'entree.
            </p>

            {{-- ------------------------------------------------------------
                 Choix des billets
            ------------------------------------------------------------- --}}
            <div class="mt-5 space-y-3.5">
                @foreach ($ticketTypes as $ticketType)
                    @php
                        $reason = $ticketType->unavailabilityReason();
                        $quantity = (int) ($quantities[$ticketType->id] ?? 0);
                        $max = $ticketType->maxPurchasableQuantity();
                        $stock = $ticketType->availableStock();

                        // Le stock n'est annonce que lorsqu'il devient une
                        // information pour l'acheteur, c'est-a-dire quand il
                        // se raréfie. Afficher « il en reste 4 000 » ne dit
                        // rien a personne et expose l'inventaire de
                        // l'organisateur sans contrepartie.
                        $lowStock = ! $reason && $stock > 0 && $stock <= 20;
                    @endphp

                    <div @class([
                        'ticket-option',
                        'ticket-option-picked' => $quantity > 0,
                        'ticket-option-off' => (bool) $reason,
                    ])>
                        {{-- Souche : le prix, et rien d'autre. C'est la donnee
                             que l'acheteur vient chercher. --}}
                        <div class="ticket-option-stub flex flex-col items-center justify-center gap-1.5 px-1.5 py-3.5">
                            <span class="flex size-7 items-center justify-center rounded-full bg-white/15">
                                <x-dynamic-component :component="$ticketType->isFree() ? 'lucide-gift' : 'lucide-ticket'"
                                                     class="size-4 shrink-0" stroke-width="1.75" />
                            </span>
                            {{-- `text-balance` : « 10 000 F CFA » doit casser
                                 entre le nombre et la devise, pas n'importe ou.
                                 Sur une souche etroite, une coupure au milieu
                                 du nombre rend le prix illisible. --}}
                            <p class="text-center font-display text-xs leading-tight font-extrabold text-balance tabular sm:text-sm">
                                {{ $ticketType->formattedPrice() }}
                            </p>
                        </div>

                        <div class="ticket-perforation-y absolute inset-y-2.5 left-[var(--stub-w)] -translate-x-1/2"
                             aria-hidden="true"></div>

                        {{-- Corps : nom, stock, et le stepper — sur UNE SEULE
                             rangee, telephone compris. Empiles, les trois
                             faisaient une carte deux fois trop haute : trois
                             billets ne tenaient plus dans un ecran, alors que
                             les comparer est tout l'interet de la liste.

                             La description du type de billet n'y figure plus.
                             Elle allongeait chaque carte d'autant de lignes que
                             l'organisateur en avait ecrit, sans jamais aider a
                             choisir — ce qui distingue les categories, c'est le
                             nom et le prix. Le detail vit dans la description
                             de l'evenement, ou il a la place d'etre lu. --}}
                        {{-- Hauteur constante, choisie ou non.

                             La rangee est reglee par le stepper — 44px — et le
                             bloc de texte tient en dessous dans les deux cas :
                             le nom seul, ou le nom suivi de la quantite. C'est
                             ce qui empeche la carte de GRANDIR SOUS LE POUCE au
                             premier appui sur « + », qui decalait toutes les
                             cartes suivantes au moment precis ou l'on visait la
                             suivante. --}}
                        <div class="flex items-center justify-between gap-3 py-3.5 pr-3 pl-3.5 sm:gap-4 sm:pr-4 sm:pl-4">
                            {{-- `flex-1 min-w-0` : la colonne de texte ABSORBE
                                 l'espace restant au lieu de se dimensionner sur
                                 son contenu.

                                 Sans `flex-1`, elle prenait la largeur de sa
                                 ligne la plus longue — et « 1 × 10 000 FCFA »,
                                 qui n'apparait qu'une fois le billet choisi,
                                 est plus large que « Standard ». Le stepper
                                 etait donc pousse vers la droite au premier
                                 appui sur « + », puis un peu plus a chaque
                                 montant plus long, jusqu'a sortir de la carte
                                 ou le masque des encoches le rognait : le
                                 bouton se decalait sous le doigt, puis
                                 disparaissait.

                                 Avec `flex-1 min-w-0`, c'est le texte qui se
                                 tronque, et le stepper ne bouge plus jamais. --}}
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-display text-[15px] leading-tight font-bold sm:text-base">
                                    {{ $ticketType->name }}
                                </p>

                                @if ($reason)
                                    <p class="mt-1 text-xs font-semibold">{{ $reason }}</p>
                                @elseif ($quantity > 0)
                                    {{-- La quantite est repetee en toutes
                                         lettres : l'anneau blanc de la carte se
                                         lit comme un etat, pas comme un nombre.
                                         Elle prend la place du stock — les deux
                                         a la fois rallongeaient la carte, et
                                         c'est ce qu'on a choisi qui compte une
                                         fois qu'on a choisi. --}}
                                    <p class="mt-0.5 truncate text-sm font-semibold tabular">
                                        {{ $quantity }} × {{ Money::format($ticketType->price * $quantity) }}
                                    </p>
                                @elseif ($lowStock)
                                    {{-- Icone ET libelle : la rarete ne peut pas
                                         reposer sur une couleur seule — et sur
                                         une carte deja coloree, elle ne le
                                         pourrait de toute facon pas. --}}
                                    <p class="mt-0.5 flex items-center gap-1.5 text-sm font-semibold tabular">
                                        <x-lucide-flame class="size-3.5 shrink-0" stroke-width="2" />
                                        Il en reste {{ Money::number($stock) }}
                                    </p>
                                @endif
                            </div>

                            @unless ($reason)
                                {{-- Blanc plein sur la carte coloree, comme le
                                     bouton d'achat : c'est le seul traitement
                                     qui tienne le contraste sur les deux bornes
                                     du degrade.

                                     44px de cote, telephone compris. Ce n'est
                                     pas seulement la cible tactile : c'est
                                     cette hauteur qui REGLE CELLE DE LA CARTE,
                                     et donc qui la garde identique avant et
                                     apres selection. Le compteur reste etroit
                                     pour que le nom du billet garde une largeur
                                     lisible sur un ecran de 360px. --}}
                                <div class="flex shrink-0 items-center gap-1.5 sm:gap-2">
                                    <button type="button"
                                            wire:click="decrement({{ $ticketType->id }})"
                                            @disabled($quantity === 0)
                                            class="flex size-11 items-center justify-center rounded-full bg-white text-azure-700 shadow-sm transition-opacity hover:opacity-90 disabled:opacity-35"
                                            aria-label="Retirer un billet {{ $ticketType->name }}">
                                        <x-lucide-minus class="size-4" stroke-width="2.5" />
                                    </button>

                                    {{-- `min-w` et non `w` : a deux chiffres,
                                         une largeur figee coupait le compteur.
                                         Il s'elargit, et comme il vit dans le
                                         bloc `shrink-0`, c'est le nom du billet
                                         qui cede la place — jamais les boutons. --}}
                                    <span class="min-w-6 text-center font-display text-lg font-extrabold tabular sm:min-w-8"
                                          aria-live="polite"
                                          aria-label="{{ $quantity }} billet(s) {{ $ticketType->name }}">
                                        {{ $quantity }}
                                    </span>

                                    <button type="button"
                                            wire:click="increment({{ $ticketType->id }})"
                                            @disabled($quantity >= $max)
                                            class="flex size-11 items-center justify-center rounded-full bg-white text-azure-700 shadow-sm transition-opacity hover:opacity-90 disabled:opacity-35"
                                            aria-label="Ajouter un billet {{ $ticketType->name }}">
                                        <x-lucide-plus class="size-4" stroke-width="2.5" />
                                    </button>
                                </div>
                            @endunless
                        </div>
                    </div>
                @endforeach
            </div>

            @error('quantities')
                <p class="mt-4 flex items-start gap-2 rounded-xl border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-ink">
                    <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0 text-danger" stroke-width="1.75" />
                    <span>{{ $message }}</span>
                </p>
            @enderror

            {{-- ------------------------------------------------------------
                 Coordonnees et paiement — n'apparaissent qu'une fois un
                 billet choisi. Afficher un formulaire de paiement a quelqu'un
                 qui n'a rien selectionne allonge la page pour rien.
            ------------------------------------------------------------- --}}
            @if ($ticketCount > 0)
                <form id="paiement" wire:submit="placeOrder"
                      class="mt-6 scroll-mt-20 border-t border-grey-200/70 pt-6">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="firstName" class="mb-1.5 block text-sm font-medium text-ink">Prenom</label>
                            <input id="firstName" type="text" autocomplete="given-name" wire:model="firstName"
                                   class="field" aria-invalid="{{ $errors->has('firstName') ? 'true' : 'false' }}" required>
                            @error('firstName') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="lastName" class="mb-1.5 block text-sm font-medium text-ink">Nom</label>
                            <input id="lastName" type="text" autocomplete="family-name" wire:model="lastName"
                                   class="field" aria-invalid="{{ $errors->has('lastName') ? 'true' : 'false' }}" required>
                            @error('lastName') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>

                        <div class="sm:col-span-2">
                            <label for="email" class="mb-1.5 block text-sm font-medium text-ink">
                                Adresse email <span class="font-normal text-grey-400">(facultatif)</span>
                            </label>
                            <input id="email" type="email" inputmode="email" autocapitalize="none"
                                   autocomplete="email" wire:model="email" class="field"
                                   aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}">
                            @error('email') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                            <p class="mt-1.5 text-xs text-grey-400">
                                Votre billet s'affiche immediatement apres le paiement. L'email n'est
                                qu'une copie de secours.
                            </p>
                        </div>
                    </div>

                    {{-- Section 16.1 : l'operateur est un choix explicite de
                         l'acheteur, jamais une deduction faite depuis son
                         numero. --}}
                    <fieldset class="mt-5" x-data="{ chosen: $wire.provider }">
                        <legend class="mb-1.5 block text-sm font-medium text-ink">Moyen de paiement</legend>

                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($providers as $paymentProvider)
                                <label
                                    class="flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border px-4 py-3 text-sm transition-colors"
                                    :class="chosen === '{{ $paymentProvider->value }}'
                                        ? 'border-azure-500 bg-azure-50 font-semibold text-azure-700'
                                        : 'border-grey-200 bg-surface text-grey-500 hover:border-azure-300'">
                                    <input type="radio" name="provider" value="{{ $paymentProvider->value }}"
                                           wire:model="provider"
                                           @change="chosen = '{{ $paymentProvider->value }}'"
                                           class="size-4">
                                    {{ $paymentProvider->label() }}
                                </label>
                            @endforeach
                        </div>

                        @error('provider') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                    </fieldset>

                    <div class="mt-4">
                        <label for="payerPhone" class="mb-1.5 block text-sm font-medium text-ink">
                            Numero de telephone
                        </label>
                        <input id="payerPhone" type="tel" inputmode="tel" autocomplete="tel"
                               wire:model="payerPhone" class="field" placeholder="074 00 00 00"
                               aria-invalid="{{ $errors->has('payerPhone') ? 'true' : 'false' }}" required>
                        @error('payerPhone')
                            <p class="mt-1.5 flex items-start gap-1.5 text-sm text-danger">
                                <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" stroke-width="1.75" />
                                <span>{{ $message }}</span>
                            </p>
                        @enderror
                        <p class="mt-1.5 text-xs text-grey-400">
                            C'est sur ce numero que vous validerez le paiement.
                        </p>
                    </div>

                    {{-- Recapitulatif : surface pleine, chiffres tabulaires. Un
                         montant est la donnee la plus relue de l'ecran.

                         Les frais du prestataire sont annonces AVANT le
                         paiement et sur une ligne distincte (decision D-023) :
                         un acheteur qui les decouvre sur son releve se sent
                         trompe, meme quand la somme est juste. --}}
                    <div class="surface mt-6 px-4 py-3.5">
                        <div class="flex items-baseline justify-between gap-4">
                            <span class="text-sm text-grey-500">
                                {{ $ticketCount }} billet{{ $ticketCount > 1 ? 's' : '' }}
                            </span>
                            <span class="text-sm text-ink tabular">{{ Money::format($subtotal) }}</span>
                        </div>

                        @if ($pspFee > 0)
                            <div class="mt-2 flex items-baseline justify-between gap-4">
                                <span class="text-sm text-grey-500">Frais de paiement</span>
                                <span class="text-sm text-ink tabular">{{ Money::format($pspFee) }}</span>
                            </div>
                        @endif

                        <div class="mt-3 flex items-baseline justify-between gap-4 border-t border-grey-200/70 pt-3">
                            <span class="text-sm font-medium text-grey-500">Total a payer</span>
                            <span class="font-display text-2xl font-extrabold text-ink tabular">
                                {{ Money::format($total) }}
                            </span>
                        </div>
                    </div>

                    @if ($pspFee > 0)
                        <p class="mt-2.5 text-xs leading-relaxed text-grey-400">
                            Les frais de paiement sont ceux de l'operateur mobile money.
                            Ils ne sont pas rembourses si vous annulez votre commande.
                        </p>
                    @endif

                    <button type="submit" class="btn btn-primary btn-pill btn-lg mt-4 w-full"
                            wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="placeOrder">Continuer vers le paiement</span>
                        <span wire:loading wire:target="placeOrder" class="flex items-center gap-2">
                            <x-lucide-loader-circle class="size-4 animate-spin" stroke-width="2" />
                            Enregistrement
                        </span>
                    </button>

                    <p class="mt-3 flex items-center justify-center gap-2 text-xs text-grey-400">
                        <x-lucide-shield-check class="size-3.5 shrink-0" stroke-width="1.75" />
                        Aucune inscription necessaire.
                    </p>
                </form>
            @endif
        </div>

        {{-- ---------------------------------------------------------------
             Barre d'achat collante.

             Sur telephone, le total et le bouton vivent tout en bas du
             formulaire, donc hors de l'ecran pendant que l'acheteur compose sa
             commande : il choisit sans jamais voir ce que ca coute. La barre
             remonte les deux la ou se trouve deja son pouce.

             Elle ne double pas le bouton de paiement : elle AMENE au
             formulaire. Un bouton « payer » flottant qui soumet un formulaire
             hors champ ferait valider une saisie que personne n'a relue.
        ---------------------------------------------------------------- --}}
        @if ($ticketCount > 0)
            {{-- TELEPORTEE AU `body`, et c'est structurel.

                 `position: fixed` ne se mesure par rapport a la fenetre que si
                 aucun ancetre ne porte de `transform`. Or la section des
                 billets est revelee au defilement, et la revelation laisse un
                 `translate3d` en place meme une fois terminee : la barre se
                 serait ancree a la section, et aurait defile avec elle.

                 La teleportation la sort de cet arbre sans la sortir du
                 composant : elle reste rendue par Livewire, donc son total
                 continue de suivre les quantites a chaque clic. --}}
            @teleport('body')
                <div class="glass-rail glass-bar-bottom checkout-bar fixed inset-x-0 bottom-0 z-30"
                     role="region" aria-label="Recapitulatif de votre commande">
                    <div class="mx-auto flex w-full max-w-5xl items-center justify-between gap-4 px-5 py-3 sm:px-6">
                        <div class="min-w-0">
                            <p class="text-xs text-grey-500">
                                {{ $ticketCount }} billet{{ $ticketCount > 1 ? 's' : '' }}
                            </p>
                            <p class="font-display text-lg leading-tight font-extrabold text-ink tabular">
                                {{ Money::format($total) }}
                            </p>
                        </div>

                        <a href="#paiement" class="btn btn-primary btn-pill shrink-0">
                            Continuer
                            <x-lucide-arrow-right class="size-4" stroke-width="2" />
                        </a>
                    </div>
                </div>
            @endteleport
        @endif
    @endif
</div>

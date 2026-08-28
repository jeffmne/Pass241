@props([
    'label',
    // Identifiant du controle. Obligatoire en pratique : c'est lui qui relie
    // le <label> au champ, et donc ce qui fait qu'un clic sur le libelle place
    // le curseur dans le champ.
    'for',
    'hint' => null,
    // Message d'erreur deja resolu. Cote Livewire : :error="$errors->first('x')"
    'error' => null,
    'required' => false,
    // Compteur de caracteres restants, quand une limite existe.
    'counter' => null,
])

{{--
    Enveloppe de champ. UNE seule ecriture pour tous les formulaires du produit
    (regle 34) : creation d'evenement, categorie de billet, collecte,
    parametres, inscription.

    Trois partis pris, tous dictes par l'usage reel :

    1. LE LIBELLE EST VISIBLE, toujours. Un libelle place dans le `placeholder`
       disparait des qu'on commence a taper — et c'est precisement au moment de
       se relire qu'on a besoin de savoir ce qu'on remplit. Sur un formulaire de
       montant, cette disparition coute cher.

    2. L'ERREUR EST SOUS SON CHAMP, jamais seulement en haut de page. Un
       recapitulatif en tete oblige a chercher lequel des douze champs est en
       cause. Elle est reliee par `aria-describedby`, donc lue au moment ou le
       curseur entre dans le champ, pas apres coup.

    3. L'AIDE ET L'ERREUR PARTAGENT LA MEME LIGNE. Empilees, elles font sauter
       tout le formulaire d'une hauteur de ligne des la premiere erreur. Ici
       l'erreur remplace l'aide : la hauteur ne bouge pas.

    Le champ lui-meme est passe en slot plutot que genere : un `<select>`, un
    `<textarea>` et un `<input type="date">` n'ont pas les memes attributs, et
    un composant qui tenterait de tous les couvrir finirait par en gener chacun.
    A charge de l'appelant de poser `id` et `class="field"` sur le controle,
    ainsi que le lien vers le message. La convention d'identifiants est fixe —
    `{for}-error` et `{for}-hint` — de sorte que l'appel reste court :

        <x-ui.field label="Nom" for="name" :error="$errors->first('name')">
            <input id="name" class="field" wire:model="name"
                   @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
        </x-ui.field>
--}}
@php
    $hintId = $hint ? $for . '-hint' : null;
    $errorId = $error ? $for . '-error' : null;
@endphp

<div {{ $attributes->class('space-y-1.5') }}>
    <label for="{{ $for }}" class="flex items-baseline justify-between gap-2 text-sm font-semibold text-ink">
        <span>
            {{ $label }}
            @if ($required)
                {{-- L'asterisque seule ne dit rien a un lecteur d'ecran : le
                     mot est donne, puis masque visuellement. --}}
                <span class="text-danger" aria-hidden="true">*</span>
                <span class="sr-only">(obligatoire)</span>
            @endif
        </span>

        @if ($counter !== null)
            <span class="text-xs font-normal text-grey-400 tabular">{{ $counter }}</span>
        @endif
    </label>

    {{ $slot }}

    {{-- Hauteur reservee : sans elle, l'apparition d'une erreur decale tout le
         formulaire d'une ligne, et le champ suivant se derobe sous le doigt. --}}
    <div class="min-h-5">
        @if ($error)
            <p id="{{ $errorId }}" class="flex items-start gap-1.5 text-xs font-medium text-danger">
                <x-lucide-circle-alert class="mt-px size-3.5 shrink-0" stroke-width="2" aria-hidden="true" />
                {{ $error }}
            </p>
        @elseif ($hint)
            <p id="{{ $hintId }}" class="text-xs text-grey-500">{{ $hint }}</p>
        @endif
    </div>
</div>

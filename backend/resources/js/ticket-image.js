/*
 * Telechargement du billet en image.
 *
 * Le billet telecharge n'est pas une reproduction du billet affiche : c'est le
 * billet affiche. L'image est capturee sur le noeud reel de la page, avec son
 * verre, ses encoches et sa banniere. C'est la seule facon d'avoir la garantie
 * que les deux ne divergeront jamais — une troisieme mise en page ecrite a la
 * main finirait par prendre du retard sur les deux autres, comme le PDF l'a
 * fait.
 *
 * SEULE DEPENDANCE DU PROJET, et elle ne pese sur personne : le module est
 * charge par `import()` dynamique au moment du clic. Vite en fait un fragment
 * separe, jamais demande par un visiteur qui ne telecharge pas son billet
 * (section 72).
 */

/**
 * Prepare une copie du billet destinee a la capture.
 *
 * On capture un clone, pas l'original, pour deux raisons :
 *
 * 1. l'image doit avoir un fond. Le billet est en verre, donc transparent :
 *    capture tel quel, il donnerait un PNG a moitie vide. Le clone est pose
 *    sur un cadre teinte qui joue le role du fond de page ;
 * 2. la capture demande des ajustements — largeur figee, animations
 *    neutralisees — qu'on ne veut pas voir clignoter sur la page pendant que
 *    l'utilisateur regarde.
 *
 * Le clone est place hors de l'ecran mais bien dans le document : sans cela le
 * navigateur ne lui appliquerait aucun style et ne lui donnerait aucune
 * dimension.
 */
function stageForCapture(ticket) {
    /*
     * Deux elements, et c'est la raison d'etre de cette fonction.
     *
     * `holder` sort la scene de l'ecran. `stage` porte le decor et c'est LUI
     * qui est capture. Les deux roles ne peuvent pas tenir sur le meme noeud :
     * la capture recopie les styles du noeud capture dans l'image produite. Un
     * `left: -10000px` pose sur la scene deplace donc son contenu de 10000
     * pixels A L'INTERIEUR de l'image, qui ressort vide aux bonnes dimensions.
     */
    const holder = document.createElement('div');
    holder.setAttribute('aria-hidden', 'true');
    holder.style.cssText = 'position:fixed;top:0;left:-10000px;pointer-events:none;z-index:-1';

    const stage = document.createElement('div');
    stage.dataset.ticketCapture = 'on';
    stage.style.cssText = 'width:420px;padding:28px';

    const clone = ticket.cloneNode(true);
    clone.style.width = '100%';
    clone.style.maxWidth = 'none';

    stage.append(clone);
    holder.append(stage);
    document.body.append(holder);

    return { holder, stage };
}

/**
 * Attend que les images du clone soient pretes.
 *
 * La banniere de l'evenement est deja dans le cache du navigateur, mais le
 * clone repart d'un element neuf : le capturer sans attendre produirait un
 * billet au cadre vide. On n'attend jamais indefiniment — une banniere
 * introuvable ne doit pas empecher le telechargement.
 */
function waitForImages(node) {
    const images = [...node.querySelectorAll('img')];

    return Promise.all(
        images.map((image) => {
            if (image.complete && image.naturalWidth > 0) {
                return Promise.resolve();
            }

            return new Promise((resolve) => {
                image.addEventListener('load', resolve, { once: true });
                image.addEventListener('error', resolve, { once: true });
                setTimeout(resolve, 3000);
            });
        })
    );
}

function triggerDownload(dataUrl, filename) {
    const link = document.createElement('a');
    link.href = dataUrl;
    link.download = filename;
    link.click();
}

/**
 * Capture le billet et declenche le telechargement.
 *
 * @param {HTMLElement} ticket  le noeud du billet affiche
 * @param {string} filename     nom du fichier propose
 */
export async function downloadTicketImage(ticket, filename) {
    const { toPng } = await import('html-to-image');

    const { holder, stage } = stageForCapture(ticket);

    try {
        await waitForImages(stage);

        // Deux images successives laissees au navigateur pour appliquer les
        // styles du clone avant la capture.
        await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));

        const dataUrl = await toPng(stage, {
            // 3x : le billet finit souvent agrandi sur un ecran de telephone,
            // ou presente a bout de bras a un agent. Un QR mou ne se scanne
            // pas.
            pixelRatio: 3,
            cacheBust: true,
            backgroundColor: '#EEF1F7',
        });

        triggerDownload(dataUrl, filename);
    } finally {
        holder.remove();
    }
}

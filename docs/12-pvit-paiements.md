# Intégrer les paiements PVit — procédure

> Écrit le 2026-08-31, après branchement réel sur le compte de test `MR_XXXXXXXXXX`.
> **Tout ce qui figure ici a été mesuré sur l'API, pas lu dans la documentation.**
> Là où les deux divergent, l'écart est signalé — il y en a plusieurs.
>
> Ce document se veut autonome : il doit suffire à rebrancher PVit dans un autre
> projet sans relire le code de Pass241.

---

## 1. Ce qu'il faut obtenir avant d'écrire une ligne

Six valeurs, depuis l'espace marchand PVit. **Chaque API a son propre code d'URL** : ce ne sont pas six occurrences d'une même chaîne, et les confondre fait perdre une heure.

| Valeur | Où la trouver | Forme |
|---|---|---|
| Compte d'opération | espace marchand | `ACC_XXXXXXXXXXXXX` |
| Mot de passe de rotation | menu **APIs**, champ masqué | à définir soi-même |
| Code URL — renew secret | liste des endpoints | `/v2/XXXX/renew-secret` |
| Code URL — paiement REST | liste des endpoints | `/v2/XXXX/rest` |
| Code URL — statut | liste des endpoints | `/XXXX/status` |
| Code de callback | après enregistrement d'une URL | `<code_callback>` |

**Les versions ne sont pas uniformes.** `rest` et `renew-secret` sont en `/v2`, `status` et `balance` ne le sont pas. Stocker des **chemins complets** plutôt qu'une base commune à laquelle on ajouterait un code.

### Deux prérequis qui ne sont pas des identifiants

- **Une URL de callback publique en HTTPS.** PVit refuse `localhost` et `127.0.0.1`. En développement : tunnel (ngrok, Cloudflare Tunnel).
- **L'IP sortante du serveur doit être autorisée** chez PVit. Avec un tunnel, c'est l'IP du tunnel.

### Base URL

`https://api.mypvit.pro` — **la même en test et en production.** Ce qui distingue les deux est la clé rendue : `sk_test_…` ou `sk_live_…`.

---

## 2. L'authentification, en une phrase

Toutes les requêtes portent un en-tête `X-Secret`. **La clé expire au bout de 3 600 secondes** et s'obtient par rotation.

```
POST {base}/v2/{code_renew_secret}/renew-secret
Content-Type: application/x-www-form-urlencoded

operationAccountCode=ACC_…&password=…
```

```json
{ "operation_account_code": "ACC_…", "secret": "sk_test_…", "expires_in": 3600 }
```

**À faire :**

- mettre la clé en cache, avec une marge — 3 300 s pour 3 600 annoncées ;
- indexer le cache **par compte d'opération**, sinon deux comptes se marchent dessus ;
- rejouer **une fois** avec une clé fraîche sur un 401/403, la clé ayant pu être invalidée de leur côté ;
- envoyer les paramètres en **form-urlencoded**, pas en query string : le mot de passe contient couramment des parenthèses et des arobases, qu'une erreur d'encodage transforme en « mot de passe invalide » sans autre explication.

---

## 3. Encaisser

```
POST {base}/v2/{code_payment}/rest
X-Secret: sk_…
Content-Type: application/json

{
  "amount": 1000,
  "reference": "VT9DNKFPD",
  "service": "RESTFUL",
  "transaction_type": "PAYMENT",
  "operator_code": "AIRTEL_MONEY",
  "customer_account_number": "074000000",
  "merchant_operation_account_code": "ACC_…",
  "callback_url_code": "<code_callback>",
  "owner_charge": "CUSTOMER"
}
```

Réponse :

```json
{
  "status": "PENDING",
  "reference_id": "PAY3108261252963",
  "merchant_reference_id": "VT9DNKFPD",
  "operator": "AIRTEL_MONEY",
  "message": "…initiée avec succès et est en cours de traitement…"
}
```

### Trois pièges, tous rencontrés

**`amount` est le montant AVANT frais.** Avec `owner_charge: CUSTOMER`, PVit ajoute ses frais par-dessus et débite le total. Si votre entité stocke déjà un total frais compris, **envoyer ce total ferait compter les frais deux fois** — le client serait débité de 10 725 F là où il a lu 10 350 F.

**La référence doit être purement alphanumérique.** `Field 'reference' must be an alphanumeric value`. Les références lisibles portent souvent des tirets (`VT-9DNK-FPD`) — les compacter à l'envoi, sans toucher à celle qu'on stocke : c'est elle qu'on dicte au support.

**L'identifiant de transaction s'appelle `reference_id` ici**, alors que le webhook et l'API de statut l'appellent `transactionId`. Accepter les deux formes, sinon on ne capture rien et la transaction devient interrogeable par personne.

---

## 4. Vérifier une transaction

```
GET {base}/{code_status}/status
  ?transactionId=VT9DNKFPD
  &accountOperationCode=ACC_…
  &transactionOperation=PAYMENT
X-Secret: sk_…
```

> ### ⚠ Le paramètre `transactionId` attend VOTRE référence marchande
>
> La documentation montre `transactionId=PAY23102400001`, l'identifiant de PVit.
> **C'est faux.** Mesuré :
>
> ```
> transactionId=PAY3108261252964  →  404 TRANSACTION_NOT_FOUND
> transactionId=VTL8J6PH8         →  200 SUCCESS
> ```
>
> Envoyer la référence marchande compactée. L'identifiant `PAY…` reste utile au
> rapprochement et au support, mais il n'interroge rien.

Réponse :

```json
{
  "status": "SUCCESS",
  "amount": 500,
  "fees": 12.5,
  "operator": "AIRTEL_MONEY",
  "merchant_reference_id": "VTL8J6PH8",
  "customer_account_number": "074000000"
}
```

**Statuts possibles : `PENDING`, `SUCCESS`, `FAILED`, `AMBIGUOUS`.**

`AMBIGUOUS` mérite son sort particulier : PVit l'emploie quand il ne sait pas conclure lui-même. Le traiter comme un succès crédite des biens jamais payés ; comme un échec, il rend du stock alors que l'argent est peut-être parti. **Le laisser en attente et réinterroger** est le seul choix qui ne crée pas de tort.

---

## 5. Les frais — la partie qui coûte cher à mal comprendre

### Il y a deux composantes, et elles ne s'affichent pas au même endroit

PVit facture **deux composantes** : sa part (`fees`) et celle de l'opérateur (`operatorFees`). **Seul le callback porte les deux.** Ni l'API de statut ni `get-fees` ne donnent le total exact.

Relevé sur des callbacks réels :

| Opérateur | Sous-total | `fees` | `operatorFees` | Taux réel |
|---|---|---|---|---|
| Airtel | 500 | 12,5 | 5,125 | **3,525 %** |
| Airtel | 1 000 | 25 | 10,25 | **3,525 %** |
| Airtel | 2 000 | 50 | 20,5 | **3,525 %** |
| Airtel | 5 000 | 125 | 51,25 | **3,525 %** |
| Moov | 1 000 | 25 | 0 | **2,500 %** |
| Moov | 4 000 | 100 | 0 | **2,500 %** |

> ### ⚠ `get-fees` se trompe sur Airtel
>
> Il annonce **3,5 %** là où l'opérateur prélève **3,525 %**. L'écart est infime
> par transaction — 1,25 F sur 5 000 — mais il est **systématique** et toujours
> dans le mauvais sens : un découvert sur le compte marchand, à combler
> transaction après transaction.
>
> **Se fier au callback, pas à `get-fees`.** Et vérifier ce point pour chaque
> opérateur : Moov n'a aucun frais opérateur, `get-fees` y est donc juste.

Ne jamais reconstituer un total par `amount + fees` depuis un **statut** : il manque la part opérateur. Ne recomposer que si `totalAmount` est présent, ou si les deux composantes le sont — sinon **rendre `null`**, pour que l'appelant sache qu'il ne sait pas.

`amountCredited` du callback confirme ce qui revient au marchand : exactement le montant envoyé, frais exclus.

### Le barème dépend de l'opérateur, pas du prestataire

Un calculateur indexé par prestataire est une hypothèse à revoir. Sur un même compte PVit, Airtel prend 3,525 % et Moov 2,5 %.

Les barèmes s'expriment en points de base entiers : 3,525 % devient **353** et non 352, pour la même raison que l'arrondi ci-dessous.

### Les frais sont fractionnaires, le franc CFA ne l'est pas

`500 F Airtel → 17,625 F`. **Arrondir au franc SUPÉRIEUR.** L'asymétrie est réelle : un franc de frais en moins est un découvert sur le compte marchand, qu'il faut combler transaction après transaction.

### Il existe un montant minimum

En dessous de ~200 F : `FEES_SCHEDULE_NOT_IMPLEMENTED`. À borner côté produit, sinon un paiement bon marché échoue sans explication utile.

```
GET {base}/v2/{code_fees}/get-fees
  ?amount=1000&transactionType=PAYMENT&operatorCode=AIRTEL_MONEY
  &accountOperationCode=ACC_…&country-code=GA
```

---

## 6. Le callback — le point de sécurité

> ### ⚠ PVit ne signe pas ses notifications
>
> Aucun HMAC, aucun secret partagé, rien dans les en-têtes. Le corps arrive en
> clair avec `"status": "SUCCESS"`. **Quiconque connaît l'URL peut se faire
> créditer.**

**La seule intégration honnête : ne jamais croire le corps.**

Le callback sert uniquement à désigner *une* transaction. L'état est ensuite **relu chez PVit par un appel sortant authentifié**. Quiconque forge une fausse notification ne fait que déclencher une vérification qui le démentira. Cela protège aussi du rejeu : un callback rejoué retrouve le même état.

L'allowlist d'IP vient en défense **secondaire** — demander à PVit depuis quelles adresses leurs callbacks partent.

### L'URL à enregistrer, et le piège qui va avec

Le code de callback (`<code_callback>`) **désigne une URL déjà enregistrée** dans l'espace marchand. Enregistrer la racine du tunnel ne suffit pas : il faut **le chemin complet** de la route qui reçoit, par exemple

```
https://<tunnel>/api/webhooks/payments/pvit
```

Symptôme si le chemin est faux : les paiements aboutissent, l'API de statut répond correctement, et **aucun callback n'arrive jamais**. Rien ne le signale — c'est un échec silencieux, à vérifier explicitement en comptant les notifications reçues après une transaction.

### La référence revient compactée — le résolveur doit le savoir

PVit renvoie dans `merchantReferenceId` la référence **telle qu'on la lui a envoyée**, donc sans tirets. Si la recherche en base se fait sur une égalité stricte, elle échoue : `VTUBT7YY5` ne trouvera pas `VT-UBT7-YY5`.

Prévoir un rattrapage — comparer sur la référence débarrassée de ses séparateurs, **après** l'échec de la recherche exacte. Sans lui, le message est classé « transaction introuvable » et **le paiement n'est jamais confirmé alors que l'argent est parti**. C'est le pire cas possible sur le chemin de l'argent, et il est silencieux.

### L'accusé de réception est contractuel

Répondre **200** avec un écho **dynamique** des valeurs reçues :

```json
{ "transactionId": "<repris du payload>", "responseCode": <repris du payload> }
```

Ne jamais coder `200` en dur. La documentation est explicite : sans cet écho, la transaction est marquée « échec de notification », et **les tests d'intégration ne sont pas validés** — une initiation réussie ne suffit pas à valider la recette.

### Payload reçu

```json
{
  "transactionId": "PAY23102400001",
  "merchantReferenceId": "REF1234567890",
  "status": "SUCCESS",
  "amount": 150, "fees": 3.5, "totalAmount": 155,
  "operatorFees": 1.5, "amountCredited": 150,
  "chargeOwner": "CUSTOMER", "operator": "AIRTEL_MONEY",
  "code": 200, "accountOperationCode": "ACC_…"
}
```

---

### Ne jamais dépendre du callback seul

Mesuré : une transaction traitée par PVit — statut définitif disponible sur leur API — **sans qu'aucune notification ne soit envoyée**. Le repli par interrogation du statut n'est pas un confort, c'est ce qui fait que le paiement se dénoue quand même.

Le prévoir dès le départ : une page de suivi qui interroge tant que la transaction est en attente, et une tâche d'expiration qui tranche au bout du délai.

---

## 7. Codes opérateurs (Gabon)

Relevés sur `get-operators?country-code=GA`, pas supposés.

| Code | Nom | Fournisseur |
|---|---|---|
| `AIRTEL_MONEY` | Airtel Money | AIRTEL |
| `MOOV_MONEY` | Moov Money | MOOV |
| `VISA_MASTERCARD` | carte bancaire | — |

Les mêmes opérateurs existent aussi via GIMAC (`GAB_AIRTEL`, `GAB_MOOV`) : ce sont d'autres chemins d'accès, à ne pas confondre avec les fournisseurs directs.

---

## 8. Ce que le compte de test n'a pas

Vérifier la liste des endpoints provisionnés **avant** de promettre une fonctionnalité :

- **pas de remboursement** — échouer bruyamment plutôt que de laisser croire à un remboursement qui n'aura pas lieu ;
- **pas de reversement** — donc pas de paiement partagé, et les fonds transitent par le compte marchand ;
- déclarer les capacités du driver d'après **les endpoints réellement ouverts**, jamais d'après le catalogue commercial.

---

## 9. Ordre de branchement conseillé

1. `get-countries` — sans authentification, confirme base URL et codes ;
2. `renew-secret` — première requête authentifiée, valide compte et mot de passe ;
3. `get-operators` — relever les codes plutôt que les recopier ;
4. `get-fees` sur plusieurs montants **et les deux opérateurs** — c'est là qu'on découvre les barèmes distincts ;
5. `rest` — une vraie transaction ;
6. `status` — avec la **référence marchande** ;
7. le callback, en dernier : c'est lui qui demande le tunnel.

---

## 10. Erreurs rencontrées, et ce qu'elles veulent dire

| Message | Cause réelle |
|---|---|
| `MISSING_REQUEST_PARAM: 'accountOperationCode'` | presque toutes les API l'exigent, y compris `services/health` |
| `MISSING_REQUEST_PARAM: 'country-code'` | avec un tiret, pas `countryIsoCode` |
| `CONSTRAINT_VIOLATION: reference must be alphanumeric` | retirer les tirets de la référence |
| `TRANSACTION_NOT_FOUND` sur le statut | on a envoyé l'identifiant PVit au lieu de la référence marchande |
| `FEES_SCHEDULE_NOT_IMPLEMENTED` | montant sous le minimum (~200 F) |

---

## 11. Contact

`contact@mypvit.pro` · (+241) 74 72 13 98 · [docs.mypvit.pro](https://docs.mypvit.pro)

**Trois questions à leur poser avant la mise en production :**

1. Depuis quelles **adresses IP** vos callbacks partent-ils ? (les notifications n'étant pas signées)
2. Le **montant minimum** exact par opérateur ?
3. Le barème `get-fees` est-il **garanti** comme le total débité au client, part opérateur comprise ?

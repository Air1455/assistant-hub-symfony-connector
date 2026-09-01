# Protocole embarqué du connecteur

Le contrat complet est défini à la racine dans `docs/protocol.md`. Cette copie résume les invariants nécessaires à l’installation autonome du bundle.

## Découverte et appairage

- `/.well-known/assistant-hub` expose uniquement des chemins relatifs et la version `1.0`.
- `/connector/authorize` exige client, redirection autorisée, `state` et challenge PKCE.
- Le login est transmis à l’API officielle ; seul le coffre chiffré conserve les jetons.
- Le code d’autorisation est court, lié au client/redirection/challenge et consommé atomiquement.
- La paire retournée au Hub est distincte des jetons du site.

## Requêtes protégées

Méthode, chemin, horodatage, nonce et hash du corps sont signés par HMAC. Une paire expirée/révoquée, une signature invalide ou un nonce déjà vu est refusé.

Une paire inconnue, expirée ou déjà révoquée renvoie le code structuré
`PAIR_INVALID`. Une signature incorrecte, incomplète ou hors fenêtre conserve
le code `AUTHENTICATION_FAILED` afin que le Hub ne confonde pas ces situations.

## Capacités

Le registre fermé associe un identifiant à une opération bornée. Les entrées
sont normalisées avant autorisation et toutes les sorties, YAML comme PHP, sont
validées contre le schéma annoncé. Les adaptateurs ne peuvent modifier la destination déclarée.

## Écritures

Toute écriture exige une proposition exacte et une confirmation liée à son empreinte. La proposition est réservée atomiquement avant l’appel API. La réservation produit une clé `Idempotency-Key` stable.

États durables : `pending`, `executing`, `completed`, `failed`. Un résultat `completed` est retourné à l’identique sans nouvel appel. `executing` et `failed` bloquent le rejeu automatique.

L’API officielle doit honorer `Idempotency-Key`. Si un succès possible n’a pas pu être enregistré, une réconciliation manuelle est obligatoire.

## Erreurs d’exécution

- `EXECUTION_IN_PROGRESS` : une réservation concurrente existe ;
- `EXECUTION_FAILED` : l’exécution a échoué et est bloquée ;
- `EXECUTION_STATE_INVALID` : résultat durable incohérent ;
- `EXECUTION_STATE_UNCERTAIN` : succès possible, persistance du résultat impossible ;
- `CAPABILITY_OUTPUT_INVALID` : la sortie du service du site ne respecte pas le schéma annoncé.

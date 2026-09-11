# Préparations calculées par le site

Extension facultative du contrat `CapabilityInterface` : implémenter
`PreparedCapabilityInterface::prepare(array $input, LocalContext $context): PreparedAction`.

Le parcours reste identique : proposition, confirmation exacte, revalidation locale,
réservation durable, exécution et restitution du résultat mémorisé.

## Responsabilités

- Le schéma d’entrée décrit les arguments simples que le modèle peut fournir.
- Après normalisation et autorisation, `prepare()` appelle les adaptateurs/services
  dédiés du site, en lecture seule. Il retourne l’entrée d’exécution figée et un
  aperçu exhaustif. Il ne doit jamais effectuer de mutation métier.
- `execute()` reçoit cette entrée figée, qui peut différer du schéma destiné au
  modèle. L’autorisation locale reçoit également cette entrée à la confirmation :
  un adaptateur qui contrôle des champs doit donc comprendre les deux formes.
- Le site revalide les versions, conflits et règles métier juste avant l’écriture.
  Le squelette ne calcule pas les règles métier et ne garantit pas leur implémentation.
- Une préparation n’est pas une autorisation, ni une promesse de réussite ultérieure.

`PreparedAction` contient `input`, `summary`, `changes`, `notices`.
Chaque changement contient trois champs texte : `label`, `before`, `after`.
Un `before=null` représente un ajout ; `after=null` une suppression.
Aucun HTML à interpréter. L’aperçu est intégré à l’empreinte de la proposition.
Limites : 100 changements, 20 notices et 256 Kio par préparation ; aucune troncature
silencieuse d’un plan à confirmer.

## Compatibilité

Une capacité existante non préparée conserve exactement son parcours
`normalizeInput → preview → execute`. Son enveloppe ne reçoit ni `changes`
ni `notices` vides ; son empreinte ne change pas de format.

Tests : `ConnectorServiceTest` vérifie les arguments figés, l’absence d’écriture
avant confirmation, le rejeu et la compatibilité historique.
`ProposalTest` vérifie l’empreinte de l’aperçu et la restitution depuis le stockage.

## Installation et publication

Cette extension est actuellement locale et non publiée. Publier une version du
package incluant les nouveaux contrats avant de livrer une intégration qui les
implémente, puis mettre à jour sa dépendance Composer et son lockfile.
Ne pas déployer une modification faite uniquement sous `vendor/`.

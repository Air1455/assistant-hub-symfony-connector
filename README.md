# Assistant Hub Symfony Connector

Bundle Symfony générique à installer dans chaque site relié à Assistant Hub. Il s’exécute côté site et communique exclusivement avec l’API officielle configurée.

## Responsabilités fournies

- découverte publique minimale ;
- interface `/connector`, session et CSRF ;
- Authorization Code + PKCE ;
- transmission du login à l’API officielle ;
- coffre AES-256-GCM et stockage SQLite propre ;
- paires HMAC, horodatage et nonces anti-rejeu ;
- catalogue fermé et préfiltrage par rôles ;
- capacités CRUD configurées avec méthode et chemin borné ;
- point d'extension `CapabilityInterface` pour les workflows métier du site ;
- adaptateurs bornés ;
- propositions, confirmations et audit local ;
- réservation atomique et résultat idempotent des écritures confirmées ;
- révocation locale.

Le bundle ne fournit aucune règle métier et ne doit importer aucune entité, repository ou table du site.

## Installation

```bash
composer require assistant-hub/symfony-connector:^0.1
```

Activez `AssistantHubConnectorBundle`, importez `config/routes.yaml`, puis créez
`config/packages/assistant_hub_connector.yaml`. Les exemples complets se trouvent
dans `examples/config/` et la procédure détaillée dans
`docs/implementation-guide.md`.

Pour développer le paquet depuis le monorepo Assistant Hub, utilisez un
repository Composer de type `path`.

## Configuration minimale

```yaml
assistant_hub_connector:
  connector_id: 'example-site'
  connector_name: 'Example site'
  storage_path: '%kernel.project_dir%/var/assistant-hub/connector.sqlite'
  encryption_key: '%env(ASSISTANT_HUB_CONNECTOR_KEY)%'
  api_base_url: 'https://api.example.test'
  allowed_hub_redirect_uris:
    - 'https://hub.example.test/sites/callback'
  authentication:
    login_path: '/auth/login'
    refresh_path: '/auth/refresh'
  capabilities:
    contact_list:
      id: 'crm.contact.list'
      version: '1.0'
      kind: 'read'
      title: 'Lister les contacts'
      description: 'Retourne les contacts visibles par le compte connecté.'
      method: 'GET'
      path: '/contacts'
      accept: 'application/json'
      input_schema:
        type: object
        additionalProperties: false
```

Les identifiants, URLs, méthodes et chemins proviennent exclusivement de cette configuration locale. Le Hub ne peut pas les remplacer.

## Deux niveaux d'extension

Pour un appel API simple, utilisez le YAML. Un chemin peut contenir un segment
`{recordId}` si ce paramètre est déclaré et requis dans `input_schema`. Les
segments sont encodés et ne permettent pas de changer l'origine.

Pour un workflow composé, créez dans l'application hôte un service implémentant
`CapabilityInterface`. Avec l'autoconfiguration Symfony, il rejoint
automatiquement le catalogue fermé. Cette classe peut injecter les services
applicatifs explicites du site ; elle porte alors seule la logique métier, la
revalidation transactionnelle et le schéma de sortie. Le Hub et le package
générique restent inchangés.

## Adaptateurs

Un service `SiteCapabilityAdapterInterface` peut transformer les paramètres validés et normaliser la réponse. Il est automatiquement enregistré avec l'autoconfiguration Symfony. Il ne peut changer ni l’origine, ni la méthode, ni le chemin configuré. Le client générique construit lui-même les en-têtes d’autorisation et d’idempotence.

## Écritures confirmées

Pour une capacité `write`, `requiresConfirmation` est forcé à `true`. La proposition est persistée avant confirmation. Lors de l’exécution :

1. la confirmation exacte est vérifiée ;
2. la proposition passe atomiquement de `pending` à `executing` ;
3. les droits sont réévalués ;
4. l’API reçoit une clé `Idempotency-Key` stable ;
5. le résultat passe à `completed` et sera restitué lors d’un rejeu ;
6. un échec passe à `failed` et n’est jamais rejoué automatiquement.

Si l’API peut avoir réussi mais que le résultat ne peut être persisté, l’état reste `executing` pour imposer une réconciliation manuelle. Une capacité d’écriture réelle ne doit être activée que si l’API officielle honore l’idempotence.

## Mode démonstration

`demo_mode` est désactivé par défaut et interdit en production. Il remplace seulement l’authentification de paire et l’autorisation locale pour les exemples sans persistance métier. Le stockage par défaut des propositions reste SQLite.

## Validation

```bash
composer validate --strict
php vendor/bin/phpunit
```

La recette inter-produits se lance depuis le Hub et charge le starter comme package local sans effectuer de requête réseau :

```powershell
cd apps/hub
vendor\bin\simple-phpunit.bat --bootstrap vendor/autoload.php tests\EndToEnd\LocalProtocolJourneyTest.php
```

## Limites

- l'identité doit actuellement être incluse dans la réponse de login ;
- la révocation distante configurée n'est pas encore exécutée ;
- pas de limitation de débit intégrée ;
- rotation multi-clés et sauvegarde SQLite à définir pour la production ;
- réconciliation des états `executing` encore manuelle ;
- la sécurité finale dépend aussi de l’API officielle et de son implémentation d’`Idempotency-Key` ;
- la recette générique E2E utilise une API officielle simulée.

Commencer par `docs/implementation-guide.md`. Voir aussi `docs/adapting.md` et
`docs/protocol.md`.

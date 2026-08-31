# Méthodologie d'implémentation du connecteur Symfony

## Objet

Ce guide décrit comment installer le paquet générique
`assistant-hub/symfony-connector` dans une application Symfony, puis l'adapter
au contrat officiel de son API.

Il sépare toujours deux livrables :

1. le **squelette générique**, maintenu et publié depuis Assistant Hub ;
2. l'**intégration du site**, maintenue dans le dépôt du site et limitée à sa
   configuration, ses secrets et, si nécessaire, ses adaptateurs.

Le connecteur s'adapte au site. Une intégration Assistant Hub ne modifie jamais
silencieusement le contrat public du site, ses formats, ses routes ou ses règles
métier. Une évolution nécessaire de l'API suit son propre cycle produit, reste
rétrocompatible et ne constitue pas un contournement propre au connecteur.

## Frontière des responsabilités

| Élément | Squelette | Intégration du site | API du site |
| --- | --- | --- | --- |
| PKCE, appairage, signatures, anti-rejeu | Fournit | Configure les origines | Non |
| Coffre chiffré et SQLite technique | Fournit | Fournit chemin et secret | Non |
| Méthodes, routes et formats API | Ne décide pas | Déclare l'existant | Fait autorité |
| Mapping des paramètres et réponses | Moteur générique | Configure ou adapte | Garde son contrat |
| Préfiltrage par rôles | Mécanisme | Déclare | Autorise réellement |
| Règles métier et transactions | Non | Non | Oui |
| Entités, repositories, base métier | Aucun accès | Aucun accès | Oui |
| Confirmation des écritures | Fournit | Déclare | Honore l'idempotence |

Une intégration est incorrecte si le connecteur importe une entité du site,
interroge sa base métier, invente une route non publique ou demande de changer
un format existant uniquement pour lui.

## Phase 0 — Inventorier l'API officielle

Produire une fiche factuelle :

- URL de base par environnement ;
- endpoint de login et corps attendu ;
- champs des jetons et de l'identité ;
- endpoint et politique de rafraîchissement ;
- endpoint de révocation, s'il existe ;
- formats négociés, par exemple `application/json` ou
  `application/ld+json` ;
- endpoints métier candidats, méthodes et paramètres ;
- statuts 401, 403, 404 et 409 ;
- rôles, pagination, enveloppes de collection et limites ;
- garantie d'`Idempotency-Key` pour toute écriture.

Classer chaque information comme **confirmée**, **déduite à vérifier** ou
**manquante**. Ne jamais commencer par modifier l'API pour la faire correspondre
aux valeurs par défaut du connecteur.

## Phase 1 — Vérifier la compatibilité

La version actuelle requiert PHP 8.2+, Symfony 6.4 ou 7.x, JSON, OpenSSL, PDO,
PDO SQLite, un emplacement privé inscriptible, les sessions Symfony et HTTPS
hors développement local.

```bash
php -v
composer show symfony/framework-bundle
php -m | grep -E 'json|openssl|PDO|pdo_sqlite'
git status --short
```

Identifier la racine Git réelle et préserver les changements existants.

## Phase 2 — Installer avec Composer

Depuis le registre :

```bash
composer require assistant-hub/symfony-connector
```

Tant qu'aucune recette Symfony Flex officielle n'est publiée, l'activation du
bundle, les routes et la configuration restent manuelles.

En développement depuis un dépôt voisin, ajouter temporairement :

```json
{
  "repositories": [{
    "type": "path",
    "url": "../../assistant-hub/starters/symfony-connector",
    "options": { "symlink": true }
  }],
  "require": {
    "assistant-hub/symfony-connector": "dev-master"
  }
}
```

Puis :

```bash
composer update assistant-hub/symfony-connector --with-dependencies
```

Le repository `path` est réservé au développement.

## Phase 3 — Activer le bundle et les routes

Dans `config/bundles.php` :

```php
AssistantHub\SymfonyConnector\AssistantHubConnectorBundle::class => ['all' => true],
```

Dans `config/routes/assistant_hub_connector.yaml` :

```yaml
assistant_hub_connector:
  resource: '@AssistantHubConnectorBundle/config/routes.yaml'
```

```bash
php bin/console lint:container
php bin/console debug:router | grep -E 'assistant-hub|connector'
```

## Phase 4 — Isoler secrets et stockage

Déclarer uniquement les noms dans le fichier versionné :

```dotenv
ASSISTANT_HUB_CONNECTOR_KEY=
ASSISTANT_HUB_REDIRECT_URI=
SITE_API_BASE_URL=
```

Fournir les valeurs via le gestionnaire de secrets ou `.env.local`.

- clé dédiée, aléatoire, d'au moins 32 octets ;
- aucune valeur de démonstration en production ;
- SQLite sous `var/` ou dans un volume privé, jamais sous `public/` ;
- sauvegarde, permissions et rotation décidées avant production ;
- stockage du connecteur distinct de la base et des migrations métier.

## Phase 5 — Décrire l'authentification existante

```yaml
assistant_hub_connector:
  connector_id: 'my-site'
  connector_name: 'Mon site'
  storage_path: '%kernel.project_dir%/var/assistant-hub/connector.sqlite'
  encryption_key: '%env(ASSISTANT_HUB_CONNECTOR_KEY)%'
  api_base_url: '%env(SITE_API_BASE_URL)%'
  allowed_hub_redirect_uris:
    - '%env(ASSISTANT_HUB_REDIRECT_URI)%'
  pairing_modes: ['authorization_code_pkce']
  demo_mode: false
  demo_example_capabilities: false
  authentication:
    login_path: '/api/login_check'
    refresh_path: '/api/token/refresh'
    revoke_path: null
    username_field: 'email'
    password_field: 'password'
    access_token_field: 'token'
    refresh_token_field: 'refresh_token'
    identity_field: 'user'
```

Contrat actuel : login et rafraîchissement en `POST` JSON, champs de premier
niveau, identité incluse dans la réponse de login et chemins relatifs à
`api_base_url`. Si le site diffère, faire évoluer le squelette générique au
lieu de déformer son API.

## Phase 6 — Implémenter une capacité à la fois

Commencer par une lecture. Documenter le besoin, l'endpoint officiel, un exemple
de réponse, le rôle minimal, l'entrée bornée, la sortie minimale, les erreurs et
les données sensibles exclues.

```yaml
assistant_hub_connector:
  capabilities:
    customer_list:
      id: 'site.customer.list'
      version: '1.0'
      kind: 'read'
      title: 'Lister les clients'
      description: 'Retourne les clients visibles par le compte connecté.'
      method: 'GET'
      path: '/api/customers'
      accept: 'application/ld+json'
      required_roles: ['ROLE_ADMIN']
      input_schema:
        type: object
        additionalProperties: false
        properties:
          limit: { type: integer, minimum: 1, maximum: 50 }
      output_schema:
        type: object
        properties:
          items:
            type: array
            items:
              type: object
              properties:
                id: { type: integer, title: 'Identifiant du client' }
                companyName:
                  type: string
                  title: 'Nom du client'
                  description: 'Nom de la société cliente.'
                  x-assistant-hub-list-label: 'les noms des clients'
                  x-assistant-hub-primary: true
                email:
                  type: string
                  title: 'Adresse e-mail du client'
                  x-assistant-hub-list-label: 'les adresses e-mail des clients'
          count: { type: integer, title: 'Nombre de clients retournés' }
      input_mapping:
        limit: 'itemsPerPage'
      response:
        collection_paths: ['member']
        fields: ['id', 'companyName', 'email']
        limit_input: 'limit'
        max_items: 50
```

| Champ | Rôle |
| --- | --- |
| `id`, `version` | Contrat stable exposé au Hub |
| `kind` | `read` ou `write` |
| `method`, `path` | Cible fixe, jamais fournie par le Hub |
| `accept` | Type MIME attendu ; défaut `application/json` |
| `required_roles` | Préfiltrage ; l'API revérifie les droits |
| `input_schema` | Entrées admises et bornes simples |
| `output_schema` | Forme normalisée, champs et libellés annoncés au Hub |
| `input_mapping` | Entrée vers query ou champ JSON |
| `response.collection_paths` | Clés candidates de collection |
| `response.fields` | Liste blanche de sortie |
| `response.search_*`, `limit_*` | Filtrage et limite locaux |
| `timeout_seconds`, `max_duration_seconds` | Bornes réseau |

Le moteur envoie la saisie mappée en query pour `GET` et en JSON pour les
autres méthodes.
Une capacité `read` utilise obligatoirement `GET`. `POST`, `PUT`, `PATCH` et
`DELETE` sont nécessairement des écritures et empruntent le canal de proposition
et de confirmation.

Le connecteur valide récursivement les entrées et les sorties avec le sous-ensemble
JSON Schema pris en charge : types, objets, propriétés requises, propriétés
supplémentaires, tableaux, unicité, longueurs, bornes numériques et `enum`.
Pour une sortie, les objets sont fermés par défaut : tout champ absent de
`output_schema` provoque un refus sûr de la réponse.

Pour une collection, décrivez les champs sous
`properties.items.items.properties`. `title` et `description` aident l’IA à
choisir le champ sans voir sa valeur. `x-assistant-hub-primary: true` définit le
repli local lorsqu’aucun champ précis n’est demandé.
`x-assistant-hub-list-label` contient le groupe nominal utilisé par le Hub, par
exemple `les noms des clients`. Il ne contient aucune donnée métier. Les champs
déclarés doivent rester cohérents avec la liste blanche `response.fields`.

## Phase 7 — Décider si un adaptateur est nécessaire

La configuration suffit pour renommer des champs, extraire une collection de
premier niveau, filtrer des champs et borner une liste. Sinon, implémenter
`SiteCapabilityAdapterInterface`.

```php
final class CustomerListAdapter implements SiteCapabilityAdapterInterface
{
    public function supports(string $capabilityId): bool
    {
        return 'site.customer.list' === $capabilityId;
    }

    public function buildRequest(array $config, array $input): array
    {
        return ['query' => ['pageSize' => $input['limit'] ?? 20]];
    }

    public function normalizeResponse(array $config, mixed $payload): array
    {
        $rows = is_array($payload) ? ($payload['data']['rows'] ?? []) : [];
        return ['items' => is_array($rows) ? $rows : []];
    }
}
```

L'interface est autoconfigurée quand l'application utilise
`autoconfigure: true`. Sinon, ajouter explicitement le tag
`assistant_hub_connector.site_adapter`.

L'adaptateur retourne seulement `query` et `json`. Le client conserve
l'origine, la méthode, le chemin, l'autorisation, l'idempotence, les délais et
les redirections. Aucun service métier du site ne doit être importé.

## Phase 7 bis — Choisir YAML ou une capacité PHP

Utiliser le YAML lorsque l'opération correspond à un appel API unique :

```yaml
assistant_hub_connector:
  capabilities:
    customer_contact_update:
      id: 'site.customer.contact.update'
      version: '1.0'
      kind: 'write'
      title: 'Modifier les coordonnées d’un client'
      description: 'Modifie le téléphone ou l’adresse e-mail du client identifié.'
      method: 'PATCH'
      path: '/api/customers/{customerId}'
      accept: 'application/ld+json'
      content_type: 'application/merge-patch+json'
      preview: 'Modifier les coordonnées du client sélectionné.'
      input_schema:
        type: object
        required: [customerId]
        additionalProperties: false
        properties:
          customerId: { type: integer, minimum: 1 }
          phone: { type: string, minLength: 3, maxLength: 30 }
          email: { type: string, minLength: 3, maxLength: 254 }
      input_mapping:
        phone: 'phone'
        email: 'email'
      output_schema:
        type: object
        properties:
          id: { type: integer }
          phone: { type: string }
          email: { type: string }
```

Chaque placeholder du chemin doit être une propriété déclarée et requise. Il
est encodé comme un segment unique et n'est pas ajouté au corps JSON. Le type
`content_type` doit être un type JSON explicite ; l'origine, la méthode et les
en-têtes sensibles restent contrôlés par le client générique.

Utiliser une classe `CapabilityInterface` lorsque l'opération forme un workflow
métier indivisible, par exemple vérifier un créneau et affecter une équipe :

```php
final class AssignTeamIfAvailable implements CapabilityInterface
{
    public function __construct(private PlanningService $planning) {}

    public function definition(): CapabilityDefinition
    {
        return new CapabilityDefinition(
            'planning.team.assign_if_available',
            '1.0',
            'write',
            'Affecter une équipe si le créneau est disponible',
            'Vérifie puis affecte atomiquement une équipe à un projet.',
            $this->inputSchema(),
            $this->outputSchema(),
            true,
            ['ROLE_PLANNER'],
        );
    }

    public function normalizeInput(array $input): array { return $this->planning->normalizeAssignmentInput($input); }

    public function preview(array $input, LocalContext $context): string
    {
        return $this->planning->describeAssignment($input, $context->identity);
    }

    public function execute(array $input, LocalContext $context): array
    {
        return $this->planning->assignAtomically($input, $context->idempotencyKey);
    }
}
```

Avec `autoconfigure: true`, la classe est découverte automatiquement. Sinon,
ajouter le tag `assistant_hub_connector.capability`. `preview` ne constitue
jamais une réservation : `execute` réauthentifie, réautorise et revérifie les
préconditions dans la transaction du site. Le Hub ne doit pas être modifié.

## Phase 8 — Tester par couches

1. **Contrat API** : login, jetons, identité, rafraîchissement, MIME, 401/403,
   pagination et enveloppe.
2. **Intégration Symfony** : syntaxe, YAML, conteneur, routes, découverte et
   catalogue fermé.
3. **Capacité** : mapping ou adaptateur, succès, refus, champs exclus et limites.
4. **Parcours HTTP réel** :
   `Hub -> découverte -> PKCE -> login -> consentement -> catalogue -> API`.

```bash
php bin/console lint:yaml config
php bin/console lint:container
php bin/console debug:router
php bin/phpunit tests/Integration/AssistantHubConnectorIntegrationTest.php
```

Un serveur PHP local qui rappelle sa propre origine nécessite plusieurs workers.

## Phase 9 — Ajouter les écritures après les lectures

Une écriture exige :

- un endpoint officiel déjà disponible ;
- les autorisations et règles métier appliquées par l'API ;
- une véritable garantie `Idempotency-Key` ;
- un aperçu exact de l'effet à confirmer ;
- des tests de conflits et d'erreurs partielles ;
- une procédure de réconciliation des états incertains.

Le connecteur force la confirmation pour `kind: write`, mais ne remplace ni
l'idempotence ni les transactions de l'API.

Le test minimal d'une écriture doit prouver l'ordre suivant :

1. la phrase produit une proposition sans effet métier ;
2. l'annulation ne déclenche aucun appel ;
3. la confirmation exacte déclenche un seul effet ;
4. un rejeu retourne le résultat mémorisé sans second effet ;
5. une proposition expirée, modifiée ou d'une ancienne version est refusée.

## Phase 10 — Préparer la production

- URLs HTTPS et redirections exactes ;
- `demo_mode: false` ;
- secrets longs hors Git ;
- droits, sauvegarde et supervision du SQLite ;
- politique de rotation du secret de coffre ;
- limites réseau et taille des réponses ;
- logs sans jeton, mot de passe ni donnée sensible inutile ;
- tests de révocation locale et de rafraîchissement ;
- audit Composer, tests et procédure de retour arrière.

## Publier le squelette sur Composer

Le repository `path` et la contrainte `dev-master` servent uniquement au
pilote. Avant la première version consommable :

1. héberger le paquet dans un dépôt accessible par Packagist ou par le registre
   Composer privé retenu ;
2. conserver dans le paquet seulement le code, les exemples et la documentation
   génériques ;
3. exclure toute URL, capacité, classe ou secret propre à un site client ;
4. vérifier `composer validate --strict`, l'audit et toute la suite PHPUnit ;
5. définir la compatibilité PHP/Symfony et la tester sur chaque version annoncée ;
6. rédiger un changelog avec les changements de configuration et de protocole ;
7. publier un tag Semantic Versioning, par exemple `0.1.0` pour la première
   préversion ;
8. configurer Packagist ou le registre pour suivre les tags du dépôt ;
9. tester `composer require assistant-hub/symfony-connector:^0.1` depuis une
   application Symfony vierge ;
10. publier éventuellement une recette Flex séparée après avoir validé les
    fichiers qu'elle crée.

Un tag ne doit être publié que si l'archive obtenue depuis Composer fonctionne
sans accès au monorepo Assistant Hub. Les études propres aux sites clients restent dans leurs dépôts ;
elles ne doivent jamais être chargées à l'exécution.

Politique de version :

- patch : correctif compatible sans changement de configuration ;
- mineure : nouvelle option ou capacité générique rétrocompatible ;
- majeure : rupture de configuration, protocole ou contrat PHP.

## Mettre à jour le paquet

Traiter une montée de version comme une dépendance normale :

1. lire le changelog et comparer la configuration ;
2. mettre à jour dans un environnement isolé ;
3. compiler le conteneur ;
4. vérifier les routes et le catalogue ;
5. tester login, rafraîchissement et une capacité ;
6. exécuter les migrations techniques du site seulement si ses propres
   dépendances l'exigent ;
7. déployer selon la procédure du site.

Une dérive de schéma Messenger relève du site hôte. Elle ne devient jamais une
table métier ou une migration du SQLite du connecteur.

## Limites connues avant une version stable

- le validateur applique un sous-ensemble de JSON Schema et ne prend pas encore
  en charge les constructions avancées comme `$ref`, `oneOf` ou `anyOf` ;
- l'identité doit être incluse dans la réponse de login ;
- login et rafraîchissement utilisent du JSON de premier niveau ;
- `revoke_path` est configurable mais la révocation distante n'est pas encore
  exécutée ;
- rotation multi-clés, limitation de débit et réconciliation automatique des
  écritures incertaines restent à finaliser ;
- aucune recette Symfony Flex officielle n'automatise encore l'installation.

Ces limites sont des travaux du squelette si un site en a besoin. Elles ne
justifient jamais une modification incompatible de son API.
Le Hub limite actuellement le catalogue transmis au modèle à 128 capacités par
site ; un catalogue plus grand nécessite une présélection générique explicite.

## Définition de terminé

- le contrat API public du site n'est pas altéré pour le connecteur ;
- bundle, routes, secrets et stockage technique sont installés ;
- chaque capacité vise un endpoint fixe documenté ;
- configuration ou adaptateur borné et testé ;
- l'API reste l'autorité métier et d'autorisation ;
- découverte, appairage et une capacité passent en HTTP réel ;
- erreurs et révocation disponible sont testées ;
- exploitation et limites sont documentées ;
- aucun secret, commit, push ou déploiement n'est implicite.

# Adapter le connecteur à une application Symfony

L'adaptation décrit le contrat existant du site. Pour une API, elle configure
des appels bornés. Pour une application monolithique, une capacité PHP peut
injecter des services applicatifs publics du site. Elle ne contourne jamais ces
services par un accès direct aux tables ni ne duplique leurs règles métier.

La procédure complète et les critères de validation sont dans
`implementation-guide.md`. Toute étude de cas propre à un site reste dans le
dépôt de ce site afin de ne pas mélanger le squelette et une intégration.

## 1. Configurer l'authentification API

Déclarez l'URL de base, les chemins de connexion et de rafraîchissement, puis le
mapping exact des champs de la réponse existante. Le connecteur transmet les
identifiants au login API, abandonne le mot de passe, puis chiffre les jetons
dans son SQLite.

```yaml
assistant_hub_connector:
  connector_id: 'my-site'
  connector_name: 'Mon site'
  api_base_url: '%env(SITE_API_BASE_URL)%'
  encryption_key: '%env(ASSISTANT_HUB_CONNECTOR_KEY)%'
  allowed_hub_redirect_uris:
    - '%env(ASSISTANT_HUB_REDIRECT_URI)%'
  pairing_modes: ['authorization_code_pkce']
  demo_mode: false
  authentication:
    login_path: '/api/login_check'
    refresh_path: '/api/token/refresh'
    username_field: 'email'
    password_field: 'password'
    access_token_field: 'token'
    refresh_token_field: 'refresh_token'
    identity_field: 'user'
```

## 2. Déclarer les capacités

Chaque capacité vise une méthode et un chemin fixes. Les paramètres sont validés par schéma et injectés uniquement dans les emplacements déclarés.

```yaml
assistant_hub_connector:
  capabilities:
    customer_list:
      id: site.customer.list
      kind: read
      method: GET
      path: '/api/customers'
      accept: 'application/ld+json'
      required_roles: [ROLE_ADMIN]
      input_schema: { type: object }
      output_schema:
        type: object
        properties:
          items:
            type: array
            items:
              type: object
              properties:
                companyName:
                  type: string
                  title: 'Nom du client'
                  x-assistant-hub-list-label: 'les noms des clients'
                  x-assistant-hub-primary: true
          count: { type: integer }
```

`required_roles` sert au filtrage précoce du catalogue. L'API reste l'autorité finale et doit refuser l'appel si les droits ne sont plus valides.
Les annotations de présentation de `output_schema` décrivent clairement les
données normalisées que l’agent peut analyser et formuler. Elles ne constituent
ni une permission supplémentaire ni un moyen de contourner le schéma fermé.
Les objets de sortie sont fermés par défaut : un champ supplémentaire produit par
l'API ou un adaptateur est refusé s'il n'est pas déclaré dans `output_schema`.
Une lecture utilise obligatoirement `GET`; toute autre méthode est une écriture
confirmée.

## 2 bis. Application Symfony à session

Si le site utilise déjà `form_login` et n’expose pas d’API à jetons, ne créez
pas un mécanisme JWT uniquement pour Assistant Hub. Utilisez :

```yaml
assistant_hub_connector:
  pairing_identity_provider: symfony_session
```

Le site doit alors :

1. protéger `/connector/authorize` par son firewall ;
2. mapper le `UserInterface` vers un identifiant stable avec
   `SessionUserIdentityMapperInterface` ;
3. implémenter `LocalAuthorizationInterface` afin de recharger l’utilisateur,
   vérifier qu’il est toujours actif et recalculer ses rôles ;
4. exposer les opérations via des capacités PHP injectant les services
   applicatifs du site.

Le connecteur ne stocke ni cookie de session ni mot de passe. La session sert
uniquement à identifier l’utilisateur qui donne le consentement. La paire HMAC
créée ensuite est durable jusqu’à sa révocation et chaque appel reste soumis à
l’autorisation locale courante.

## 3. Déclarer une écriture API simple

Une opération CRUD peut rester en YAML :

```yaml
customer_update:
  id: site.customer.update
  kind: write
  method: PATCH
  path: '/api/customers/{customerId}'
  content_type: 'application/merge-patch+json'
  input_schema:
    type: object
    required: [customerId]
    additionalProperties: false
    properties:
      customerId: { type: integer, minimum: 1 }
      phone: { type: string, minLength: 3, maxLength: 30 }
```

Le placeholder est obligatoire dans le schéma et encodé par le connecteur. La
demande ne peut fournir ni origine, ni méthode, ni chemin libre. Toute écriture
passe automatiquement par proposition puis confirmation humaine.

## 4. Adapter un format complexe

Une configuration déclarative suffit pour les APIs simples. Si une API exige
une transformation, implémentez `SiteCapabilityAdapterInterface`. Avec
`autoconfigure: true`, le service est automatiquement enregistré. L'adaptateur
peut produire les options `query` et `json`, puis normaliser la réponse ; il ne
peut choisir ni origine, ni méthode, ni chemin et n'accède pas aux repositories,
entités ou tables du site.

Un import OpenAPI futur peut proposer une configuration, mais aucune route ne doit être activée automatiquement, surtout en écriture.

## 5. Implémenter un workflow métier complexe

Si une demande doit enchaîner plusieurs vérifications et une mutation, exposez
une seule classe du site implémentant `CapabilityInterface`. Elle définit son
identifiant, sa version, ses schémas, son aperçu et son exécution. Elle est
automatiquement ajoutée au catalogue fermé avec l'autoconfiguration Symfony.

Cette classe peut injecter une API cliente ou un service applicatif du site.
Elle doit conserver la logique et la transaction métier côté site, revérifier
les préconditions lors de l'exécution, appliquer les permissions réelles et
honorer l'idempotence. Le Hub ne compose jamais lui-même une séquence libre
d'endpoints et ne nécessite aucune modification pour accueillir cette capacité.

## 6. Garanties du package

Le package fournit sans code spécifique au site : découverte, routes
`/connector`, CSRF et session, PKCE, appairage, SQLite, chiffrement des jetons,
rafraîchissement, révocation locale, signatures Hub-connecteur, anti-rejeu,
propositions, idempotence et audit.

## 7. Tester la frontière

Testez au minimum : mauvais identifiants, absence de jeton de rafraîchissement, jeton expiré, refus API 401/403, paire invalide, capacité inconnue, chemin non déclaré, entrée supplémentaire, proposition expirée, empreinte modifiée, rejeu d'une confirmation et concurrence sur une même clé d'idempotence.

Le MIME et la forme de l'API restent ceux du site. Le champ `accept`, le mapping
et, si nécessaire, l'adaptateur servent à les respecter.

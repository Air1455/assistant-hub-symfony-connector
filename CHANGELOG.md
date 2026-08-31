# Changelog

Toutes les modifications notables du connecteur sont consignées ici. Le projet
suit Semantic Versioning et le format Keep a Changelog.

## [Unreleased]

_Aucun changement pour le moment._

## [0.1.0] - 2026-08-31

### Added

- appairage Authorization Code avec PKCE ;
- coffre chiffré et stockage technique SQLite indépendants du site ;
- signatures HMAC, horodatage, nonces anti-rejeu et révocation locale ;
- catalogue fermé de capacités de lecture et d'écriture ;
- validation récursive des entrées et sorties par schémas JSON ;
- adaptateurs et capacités métier extensibles côté application hôte ;
- propositions d'écriture persistantes, confirmation exacte et idempotence ;
- client générique borné à l'API officielle du site ;
- documentation d'installation, d'adaptation et de publication ;
- tests automatisés sur Symfony 6.4 et 7.4.

### Security

- aucune route, méthode, origine ou autorisation ne peut être fournie par le
  Hub ou par le modèle d'IA au moment de l'exécution.

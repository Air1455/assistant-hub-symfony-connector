# Annotations facultatives pour les formulaires du Hub

Le moteur du connecteur reste inchangé : CapabilityDefinition transmet inputSchema
tel que déclaré. Aucune nouvelle route n’est nécessaire pour proposer des listes de
choix ; il s’agit de capacités de lecture ordinaires soumises aux mêmes droits.

Dans une propriété de inputSchema :
- title / description : textes affichés par le Hub ;
- format date, time (HH:MM), email : champs adaptés ;
- x-hub-enum-labels : dictionnaire valeur -> libellé pour enum ;
- x-hub-choices: {capability: "example.customer.choices"} : source de sélection.

Cette dernière capacité doit être déclarée read et exposée à cet utilisateur. Ses
paramètres deviennent les filtres de recherche ; sa donnée retournée doit contenir
items: [{value: 7, label: "Client Alpha"}], truncated: false, notice: "...".
value est un entier ou une chaîne, label une chaîne, au plus 100 items par réponse.
En cas de dépassement, déclarer truncated=true et fournir des filtres plus précis.
Les résultats ne donnent pas de droits supplémentaires et la capacité finale doit
revalider les références et les permissions au moment de son exécution.

Les widgets sont une aide d’interface ; ce contrat ne change ni normalizeInput,
ni les schémas d’exécution, ni les confirmations. Une capacité préparée peut toujours
transformer des paramètres humains en plan technique figé. Ne pas remplacer ces
règles par une validation du formulaire seule.

Le Hub prend en charge le parcours fiche -> préparation -> plan exact -> confirmation.
Les annotations sont facultatives, sans rupture pour les anciens connecteurs.
La preuve de transport sans logique métier est dans CapabilityDefinitionTest.

# SI ABV : documents officiels de l'OSAV

Repris tels quels le 08.11.2026 de la page de l'OSAV « Saisie et transmission
des données au SI ABV » (https://www.blv.admin.ch/fr/saisie-et-transmission-des-donnees-au-siabv) :

- `xsd/` : structure de notification (XSD) de l'interface logiciel du cabinet
  et serveur SI ABV, version du 31.03.2026 (EH, EN, AV, FZL) ;
- `swagger/` : Swagger de l'interface (version 3.0.0) et des données de base ;
- `xsd-corrige/isabv_psw_av.xsd` : le XSD AV avec une seule correction. Le
  schéma publié met un `xs:choice` (2 à 3 fois) d'une `xs:sequence` des trois
  champs du dosage (nombre d'animaux, jours, dosage par animal et par jour) :
  il exige les trois, deux ou trois fois, alors que son commentaire et les
  directives (ch. 12.7) disent « deux des trois ». La copie les rend
  facultatifs ; la règle « deux des trois » est vérifiée par
  `DeclarationRules`. À signaler à l'OSAV (isabv@blv.admin.ch).

Règles : O-SI ABV (RS 812.214.4, état au 01.01.2026) et directives techniques
de l'OSAV du 07.01.2026. À remplacer quand l'OSAV publie une nouvelle version.

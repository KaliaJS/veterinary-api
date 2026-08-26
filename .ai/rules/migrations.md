---
paths:
  - 'database/migrations/**'
---

# Migrations

## Nommage français des tables, enums PHP adossés aux valeurs du front
Tables en français (rendez_vous, employes, lignes_planning, horaires_ouverture…) ; les colonnes issues du front gardent le camelCase converti (starts_at, ends_at, arrived_at, patient_name). Les enums vivent dans app/Enums (clés TitleCase, valeurs = codes du front : 'consult', 'annule', 'ok'/'att'/'ref'…) et les colonnes enum des migrations utilisent array_column(Enum::cases(), 'value'). Les modèles aux pluriels non anglais déclarent $table explicitement.

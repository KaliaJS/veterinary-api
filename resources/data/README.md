# Données de référence

## `localites.json` : NPA et localités de Suisse et du Liechtenstein

- **Source** : swisstopo, Répertoire officiel des localités
  (Amtliches Ortschaftenverzeichnis, collection STAC
  `ch.swisstopo-vd.ortschaftenverzeichnis_plz`), fichier
  `ortschaftenverzeichnis_plz_2056.csv.zip`, téléchargé le 03.11.2026 sur
  https://data.geo.admin.ch/ch.swisstopo-vd.ortschaftenverzeichnis_plz/ortschaftenverzeichnis_plz/ortschaftenverzeichnis_plz_2056.csv.zip
  (mise à jour swisstopo du 01.11.2026 ; CSV daté du 29.10.2026 ;
  SHA-256 du zip `f7a99833d11e773916a57baebe7374040f4fbde0c8821250c77fa7197a176978`,
  le même que dans le catalogue STAC).
- **Licence** : données ouvertes de swisstopo, « utilisation libre,
  obligation d'indiquer la source » (conditions d'utilisation
  opendata.swiss, OGD). Source à citer : « © swisstopo ».
- **Forme** : un objet dont chaque clé est un NPA (`PLZ4`) et chaque valeur
  la liste des localités de ce NPA, `[nom, canton]` : le nom officiel
  (`Ortschaftsname`, tel quel, par exemple « Renens VD ») et l'abréviation
  du canton (`Kantonskürzel`, `null` au Liechtenstein). Une localité
  partagée entre plusieurs communes n'apparaît qu'une fois. Ordre : le
  chiffre additionnel (`Zusatzziffer`) croissant, la localité principale
  du NPA d'abord, puis le nom.
- **Conversion** : `convert-localites.php`, à côté (PHP 8.4 avec
  l'extension intl, pour l'ordre alphabétique allemand) :

  ```sh
  curl -O https://data.geo.admin.ch/ch.swisstopo-vd.ortschaftenverzeichnis_plz/ortschaftenverzeichnis_plz/ortschaftenverzeichnis_plz_2056.csv.zip
  shasum -a 256 ortschaftenverzeichnis_plz_2056.csv.zip   # à comparer au catalogue STAC
  unzip ortschaftenverzeichnis_plz_2056.csv.zip
  php resources/data/convert-localites.php AMTOVZ_CSV_LV95/AMTOVZ_CSV_LV95.csv > resources/data/localites.json
  ```

  Le fichier en place sort de cette commande, octet pour octet.
- **Mise à jour** : refaire ces quatre commandes ; rien n'est ajouté ni
  corrigé à la main.
- **Servi par** `GET /api/v1/localites?npa=1020` (équipe des cliniques
  seulement), `App\Http\Controllers\Api\V1\LocalityController`.

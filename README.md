<DIV align="center">
  <img src="https://dihu.fr/appgithub/iconedihu/9.png" width="120" style="border-radius: 20px; margin-bottom: 15px;">
  <H3>🛡️ RGPD Calculate</H3>
  <h4>Calculateur de durées de conservation RGPD avec export et signature</h4>
</DIV>

---

<b>Utilité :</b><br>
<i>Les développeurs et administrateurs ont besoin de générer des recommandations de durée de conservation des données, de signer et vérifier des rapports, d’exporter des résultats en CSV, JSON, Markdown ou XLSX.  
RGPD Calculate centralise ces actions dans un outil backend + frontend simple et modulable.</i><br><br>

<b>Fonctionnement :</b><br>
1- <i>Ajouter des types de données (nom, catégorie, durée personnalisée optionnelle)<br></i>
2- <i>Générer des recommandations RGPD selon le pays (FR, EU, US, Other)<br></i>
3- <i>Signer un rapport avec HMAC ou RSA-PSS<br></i>
4- <i>Vérifier la signature côté serveur<br></i>
5- <i>Exporter le rapport dans différents formats (CSV, JSON, Markdown, XLSX)<br></i>
6- <i>Sauvegarder configuration MySQL locale et tester connexion<br></i>
7- <i>Persister les rapports signés en base si besoin<br></i>
8- <i>Tout fonctionne 100% localement ou via Node.js pour automatisation<br><br></i>

---

<b>Actions disponibles (backend) :</b><br>

**recommend**  
- Entrée: JSON ou FormData avec `types` et `country`  
- Fonction: génère recommandations pour chaque type  
- Sortie: `{ok:true, items:[{name,category,recommended,note,source}]}`  

**export**  
- Entrée: FormData avec `items` et `format` (csv|json|md|xlsx)  
- Fonction: exporte le dernier rapport dans le format choisi  
- Sortie: fichier téléchargeable ou `{ok:false,error}`  

**sign**  
- Entrée: FormData avec `items` et clé optionnelle  
- Fonction: signe le rapport avec HMAC SHA-256  
- Sortie: `{ok:true,signature,secret}`  

**verify_rsa**  
- Entrée: FormData avec `items`, `public_pem`, `signature_b64`  
- Fonction: vérifie signature RSA-PSS côté serveur  
- Sortie: `{ok:true,verified:true|false}`  

**save_config**  
- Entrée: FormData avec `config` JSON `{host,dbname,user,pass}`  
- Fonction: sauvegarde configuration MySQL locale  
- Sortie: `{ok:true}` ou `{ok:false,error}`  

**test_db**  
- Entrée: FormData avec `config` JSON  
- Fonction: teste connexion MySQL  
- Sortie: `{ok:true}` ou `{ok:false,error}`  

**save_report_db**  
- Entrée: FormData avec `items`, `signature`, optionnel `public_pem`  
- Fonction: persiste rapport signé en base  
- Sortie: `{ok:true}` ou `{ok:false,error}`  

<b>Fonctions utilitaires serveur :</b><br>
- `build_xlsx_from_sheets($sheets)` → génère fichier XLSX  
- `excel_col_letter($n)` → convertit index 1-based en lettre Excel  

<b>Frontend (principaux comportements) :</b><br>
- Changement langue via `#lang-fr` / `#lang-en`  
- Ajout type: bouton `#addBtn` → push dans `items[]`  
- Suppression type: bouton dynamique `.delBtn`  
- Réinitialisation: bouton `#clearBtn` → vide `items[]`  
- Génération recommandations: bouton `#recommendBtn` → POST `action=recommend`  
- Export: boutons `downloadCSV / JSON / MD / XLSX` → POST `action=export`  
- Signature HMAC: bouton `#signBtn`  
- Signature RSA + vérification: bouton `#signRsaBtn`  
- Sauvegarde configuration DB: bouton `#saveDbCfg`  
- Test connexion DB: bouton `#testDb`  
- Sauvegarde rapport en BDD: bouton `#saveDbReport`  

<b>Variables d’état frontend :</b><br>
- `items`: liste brute entrée utilisateur  
- `lastReport`: résultat enrichi après recommend  
- `lastSignature`: signature HMAC  
- `lastRsaSignature`: signature RSA  
- `lastPublicPem`: clé publique RSA correspondante  

<b>Validation / Limitations :</b><br>
- Pas de contrôle de duplication des types  
- Catégories fixes (sélect FR/EN)  
- Durées libres non normalisées  
- Export MD → note sur ligne suivante  

<b>Codes de retour principaux :</b><br>
- Succès: `ok:true`  
- Échec générique: `ok:false,error:"..."`  

<b>Améliorations possibles :</b><br>
- Normalisation des durées (ISO 8601)  
- Authentification avant `save_report_db`  
- Chiffrement config DB  
- Ajout d’index sur `rgpd_reports`  
- Détection langue navigateur initiale

---

  <BR>
<b>Installation via npm:</b><br>
<i>Install locally:</i>

<pre><code>npm install rgpd-calculate
</code></pre>

<b>Exemple rapide POST recommend (JSON)</b><br>
```json
{
  "action":"recommend",
  "country":"FR",
  "types":[{"name":"Email client","category":"contact"},{"name":"Facture","category":"financial"}]
}
```
<br><b>Réponse :</b><br>
```json
{
  "ok":true,
  "items":[
    {"name":"Email client","category":"contact","recommended":"3 years","note":"Contacts commerciaux / prospection : 3 ans ...","source":"internal"},
    {"name":"Facture","category":"financial","recommended":"10 years","note":"Données comptables et fiscales ... (France: obligations fiscales jusqu'à 10 ans).","source":"internal"}
  ]
}

```
<br>

---

<DIV align="center"> 
  
![BadgeCustom](https://img.shields.io/badge/RGPD--Calculate-OpenSource%20%E2%9C%85-blue?style=for-the-badge)
  <BR>
  ![BadgeFast](https://img.shields.io/badge/Instant-%F0%9F%94%82%20Fast%20&%20Safe-0B8FEA?style=for-the-badge)
  
  <h5>Calculez, signez, exportez… vos données en beauté ! 🖥️</h5> </DIV>
<br><br>

---

<br><br>

<div align="center">| ENGLISH |</div>

<br>

<h4>GDPR Data Retention Calculator with Export and Signing</h4>
</DIV>

<b>Purpose:</b><br>
<i>Developers and admins need to generate data retention recommendations, sign and verify reports, and export results in CSV, JSON, Markdown, or XLSX.
GDPR Calculate centralizes these actions in a simple, modular backend + frontend tool.</i><br><br>

<b>How it works:</b><br>
1- <i>Add data types (name, category, optional custom duration)<br></i>
2- <i>Generate GDPR recommendations by country (FR, EU, US, Other)<br></i>
3- <i>Sign a report with HMAC or RSA-PSS<br></i>
4- <i>Verify the signature on the server<br></i>
5- <i>Export the report to CSV, JSON, Markdown, XLSX<br></i>
6- <i>Save local MySQL configuration and test the connection<br></i>
7- <i>Persist signed reports in the database if needed<br></i>
8- <i>Works 100% locally or via Node.js for automation<br><br></i>

---

<b>Available actions (backend):</b><br>

**recommend**  
- Input: JSON or FormData with `types` and `country`  
- Function: generates recommendations for each type  
- Output: `{ok:true, items:[{name,category,recommended,note,source}]}`  

**export**  
- Input: FormData with `items` and `format` (csv|json|md|xlsx)  
- Function: exports the latest report in the chosen format  
- Output: downloadable file or `{ok:false,error}`  

**sign**  
- Input: FormData with `items` and optional secret  
- Function: signs the report using HMAC SHA-256  
- Output: `{ok:true,signature,secret}`  

**verify_rsa**  
- Input: FormData with `items`, `public_pem`, `signature_b64`  
- Function: verifies RSA-PSS signature on the server  
- Output: `{ok:true,verified:true|false}`  

**save_config**  
- Input: FormData with `config` JSON `{host,dbname,user,pass}`  
- Function: saves local MySQL configuration  
- Output: `{ok:true}` or `{ok:false,error}`  

**test_db**  
- Input: FormData with `config` JSON  
- Function: tests MySQL connection  
- Output: `{ok:true}` or `{ok:false,error}`  

**save_report_db**  
- Input: FormData with `items`, `signature`, optional `public_pem`  
- Function: persists signed report to the database  
- Output: `{ok:true}` or `{ok:false,error}`  

<b>Server utility functions:</b><br>
- `build_xlsx_from_sheets($sheets)` → generates XLSX file  
- `excel_col_letter($n)` → converts 1-based index to Excel column letter  

<b>Frontend (main behaviors):</b><br>
- Language switch via `#lang-fr` / `#lang-en`  
- Add type: button `#addBtn` → push into `items[]`  
- Remove type: dynamic button `.delBtn`  
- Reset: button `#clearBtn` → empties `items[]`  
- Generate recommendations: button `#recommendBtn` → POST `action=recommend`  
- Export: buttons `downloadCSV / JSON / MD / XLSX` → POST `action=export`  
- HMAC signing: button `#signBtn`  
- RSA signing + verification: button `#signRsaBtn`  
- Save DB config: button `#saveDbCfg`  
- Test DB connection: button `#testDb`  
- Save report to DB: button `#saveDbReport`  

<b>Frontend state variables:</b><br>
- `items`: raw user-entered list  
- `lastReport`: enriched result after recommend  
- `lastSignature`: HMAC signature  
- `lastRsaSignature`: RSA signature  
- `lastPublicPem`: corresponding RSA public key  

<b>Validation / Limitations:</b><br>
- No duplicate type control  
- Fixed categories (FR/EN select)  
- Free-form durations not normalized  
- MD export → note on following line  

<b>Main return codes:</b><br>
- Success: `ok:true`  
- Generic failure: `ok:false,error:"..."`  

<b>Possible improvements:</b><br>
- Duration normalization (ISO 8601)  
- Authentication before `save_report_db`  
- Encrypt DB config  
- Add indexes on `rgpd_reports`  
- Initial browser language detection

---

  <BR>
<b>Installation via npm:</b><br>
<i>Install locally:</i>

<pre><code>npm install rgpd-calculate
</code></pre>

<b>Quick example POST recommend (JSON)</b><br>
```json
{
  "action":"recommend",
  "country":"FR",
  "types":[{"name":"Client email","category":"contact"},{"name":"Invoice","category":"financial"}]
}
```
<br><b>Response:</b><br>
```json
{
  "ok":true,
  "items":[
    {"name":"Client email","category":"contact","recommended":"3 years","note":"Commercial contacts / prospecting: 3 years ...","source":"internal"},
    {"name":"Invoice","category":"financial","recommended":"10 years","note":"Accounting and tax data ... (France: tax obligations up to 10 years).","source":"internal"}
  ]
}

```
<br>

---

<DIV align="center"> 
  
![BadgeCustom](https://img.shields.io/badge/RGPD--Calculate-OpenSource%20%E2%9C%85-blue?style=for-the-badge)
  <BR>
  ![BadgeFast](https://img.shields.io/badge/Instant-%F0%9F%94%82%20Fast%20&%20Safe-0B8FEA?style=for-the-badge)
  
  <h5>Compute, sign, deploy… keep your data joy! 🖥️</h5> </DIV>


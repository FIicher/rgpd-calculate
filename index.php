<?php
// index.php - Calculatrice RGPD (durée de conservation par type de données)
// Single-file app: PHP backend endpoints + AJAX frontend.
// Features: extended categories & jurisprudence, XLSX export multi-sheet with styles, HMAC signature, RSA/WebCrypto signing (client-side) + server verification, DB plugin (config per-user), CSV/JSON/MD export.

// ---- Helpers: XLSX builder (supports multiple sheets & basic styles) ----
function build_xlsx_from_sheets($sheets) {
    // $sheets = ['SheetName' => [ [row1col1, col2...], [row2...], ... ], ...]
    $temp = sys_get_temp_dir() . '/rgpd_xlsx_' . bin2hex(random_bytes(6));
    @mkdir($temp);

    // [Content_Types].xml
    $parts = '';
    $idx = 1;
    foreach ($sheets as $name => $rows) {
        $parts .= "  <Override PartName=\"/xl/worksheets/sheet{$idx}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>
";
        $idx++;
    }

    $content_types = '<?xml version="1.0" encoding="UTF-8"?>
' .
"<Types xmlns=\"http://schemas.openxmlformats.org/package/2006/content-types\">
" .
"  <Default Extension=\"rels\" ContentType=\"application/vnd.openxmlformats-package.relationships+xml\"/>
" .
"  <Default Extension=\"xml\" ContentType=\"application/xml\"/>
" .
"  <Override PartName=\"/xl/workbook.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml\"/>
" .
$parts .
"  <Override PartName=\"/xl/styles.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml\"/>
" .
"  <Override PartName=\"/docProps/core.xml\" ContentType=\"application/vnd.openxmlformats-package.core-properties+xml\"/>
" .
"  <Override PartName=\"/docProps/app.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.extended-properties+xml\"/>
" .
"</Types>
";
    file_put_contents($temp . '/[Content_Types].xml', $content_types);

    // _rels/.rels
    @mkdir($temp . '/_rels');
    $rels = '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\">
' .
        '<Relationship Id=\"rId1\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument\" Target=\"/xl/workbook.xml\"/>' .
        '
</Relationships>'; 
    file_put_contents($temp . '/_rels/.rels', $rels);

    // docProps
    @mkdir($temp . '/docProps');
    $core = '<?xml version="1.0" encoding="UTF-8"?>
<cp:coreProperties xmlns:cp=\"http://schemas.openxmlformats.org/package/2006/metadata/core-properties\" xmlns:dc=\"http://purl.org/dc/elements/1.1/\" xmlns:dcterms=\"http://purl.org/dc/terms/\" xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\">
' .
            '<dc:creator>RGPD Tool</dc:creator>
' .
            '<cp:lastModifiedBy>RGPD Tool</cp:lastModifiedBy>
' .
            '<dcterms:created xsi:type=\"dcterms:W3CDTF\">' . date('c') . '</dcterms:created>
' .
            '<dcterms:modified xsi:type=\"dcterms:W3CDTF\">' . date('c') . '</dcterms:modified>
' .
            '</cp:coreProperties>';
    file_put_contents($temp . '/docProps/core.xml', $core);
    $app = '<?xml version="1.0" encoding="UTF-8"?>
<Properties xmlns=\"http://schemas.openxmlformats.org/officeDocument/2006/extended-properties\">
<TotalTime>0</TotalTime>
</Properties>';
    file_put_contents($temp . '/docProps/app.xml', $app);

    // xl
    @mkdir($temp . '/xl');
    @mkdir($temp . '/xl/_rels');
    @mkdir($temp . '/xl/worksheets');

    // workbook with sheets
    $workbookSheets = '';
    $sheetIdx = 1;
    foreach ($sheets as $name => $rows) {
        $escaped = htmlspecialchars($name, ENT_XML1|ENT_COMPAT, 'UTF-8');
        $workbookSheets .= "<sheet name=\"{$escaped}\" sheetId=\"{$sheetIdx}\" r:id=\"rId{$sheetIdx}\"/>
";
        $sheetIdx++;
    }
    $workbook = '<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\">
<sheets>
' . $workbookSheets . '</sheets>
</workbook>';
    file_put_contents($temp . '/xl/workbook.xml', $workbook);

    // workbook rels
    $wb_rels = '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\">
';
    $r=1; foreach ($sheets as $name=>$rows){ $wb_rels .= '<Relationship Id=\"rId'.$r.'\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet'.$r.'.xml\"/>
'; $r++; }
    $wb_rels .= '<Relationship Id=\"rId'.$r.'\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/>
</Relationships>';
    file_put_contents($temp . '/xl/_rels/workbook.xml.rels', $wb_rels);

    // styles: add simple fills for header and bold font
    $styles = '<?xml version="1.0" encoding="UTF-8"?>
<styleSheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\">
' .
              '<fonts><font><b/><sz val=\"11\"/><color rgb=\"FF000000\"/><name val=\"Calibri\"/></font></fonts>
' .
              '<fills><fill><patternFill patternType=\"none\"/></fill><fill><patternFill patternType=\"gray125\"/></fill><fill><patternFill patternType=\"solid\"><fgColor rgb=\"FFBDD7EE\"/></patternFill></fill></fills>
' .
              '<borders/><cellStyleXfs/><cellXfs><xf numFmtId=\"0\" fontId=\"0\" fillId=\"2\"/></cellXfs>
' .
              '</styleSheet>';
    file_put_contents($temp . '/xl/styles.xml', $styles);

    // create each sheet xml
    $i = 1;
    foreach ($sheets as $sName => $rows) {
        $sheet = '<?xml version="1.0" encoding="UTF-8"?>
<worksheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\">
';
        // cols widths: simple mapping - first 4 columns wider
        $colsXml = '<cols><col min=\"1\" max=\"4\" width=\"25\" customWidth=\"1\"/></cols>
';
        $sheet .= $colsXml;
        $sheet .= '<sheetData>'; 
        foreach ($rows as $r => $row) {
            $sheet .= '<row r="'.($r+1).'">';
            foreach ($row as $c => $cell) {
                // Excel columns beyond Z not handled; okay for small tables
                $colLetter = excel_col_letter($c+1);
                $val = htmlspecialchars($cell, ENT_XML1|ENT_COMPAT, 'UTF-8');
                // use inlineStr
                $sheet .= '<c r="'.$colLetter.($r+1).'" t="inlineStr"><is><t>'.$val.'</t></is></c>';
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';
        file_put_contents($temp . '/xl/worksheets/sheet'.$i.'.xml', $sheet);
        $i++;
    }

    // create zip
    $zipPath = $temp . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE)!==TRUE) return false;

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp));
    foreach ($it as $file) {
        if ($file->isDir()) continue;
        $filePath = $file->getRealPath();
        $local = substr($filePath, strlen($temp)+1);
        $zip->addFile($filePath, $local);
    }
    $zip->close();

    // cleanup temp folder
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $f) { if ($f->isFile()) unlink($f->getRealPath()); else rmdir($f->getRealPath()); }
    @rmdir($temp);

    return $zipPath;
}

function excel_col_letter($n) {
    $r = '';
    while ($n > 0) {
        $n--; $r = chr(65 + ($n % 26)) . $r; $n = intval($n/26);
    }
    return $r;
}

// ---- Router for AJAX ----
// Accepte FormData (POST classique) et JSON (application/json)
if (php_sapi_name() !== 'cli' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json; charset=utf-8');
  $rawInput = file_get_contents('php://input');
  $isJsonReq = isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false;
  $jsonBody = $isJsonReq ? json_decode($rawInput, true) : null;
  $action = $_POST['action'] ?? ($jsonBody['action'] ?? null);
  if (!$action) { echo json_encode(['ok'=>false,'error'=>'missing action']); exit; }

    // Recommend endpoint (same as before, extended datas)
    if ($action === 'recommend') {
      // Préfère le JSON déjà décodé si application/json
      $payload = $jsonBody ?: json_decode($rawInput, true);
      if (!$payload) { $payload = ['types'=>json_decode($_POST['types']??'[]',true),'country'=>$_POST['country']??'FR']; }
        $types = $payload['types'] ?? [];
        $country = $payload['country'] ?? 'FR';

        $recommendations = [
            'identification' => ['duration' => '3 years', 'note' => 'Données d’identification : RGPD art.5 (minimisation, finalité). Réf : CNIL — conservation liée à la relation client.'],
            'contact' => ['duration' => '3 years', 'note' => 'Contacts commerciaux / prospection : 3 ans conformément aux recommandations CNIL. Ref: https://www.cnil.fr'],
            'financial' => ['duration' => '10 years', 'note' => 'Données comptables et fiscales : code du commerce & obligations fiscales (ex : 10 ans en France).'],
            'sensitive' => ['duration' => 'strictement necessary', 'note' => 'Données sensibles (RGPD art.9) : conserver uniquement si base légale solide. Exemple: santé, opinions.'],
            'health' => ['duration' => '20 years', 'note' => 'Dossiers médicaux : durée variable selon pays. Réf: Code de la santé publique.'],
            'logs' => ['duration' => '6 months', 'note' => 'Logs techniques : conserver minimisé pour sécurité et conformité.'],
            'marketing' => ['duration' => '3 years', 'note' => 'Marketing / consentement : durée liée au consentement et droit de retrait. Documenter la preuve du consentement.'],
            'video' => ['duration' => '30 days', 'note' => 'Vidéosurveillance : généralement 30 jours (CNIL) sauf nécessité probante.'],
            'cookies' => ['duration' => '13 months', 'note' => 'Cookies analytiques : recommandation CNIL 13 mois.'],
            'biometric' => ['duration' => '48 hours - 90 days', 'note' => 'Données biométriques : conserver uniquement si strictement nécessaire et sécurisé.'],
            'judicial' => ['duration' => 'strictly necessary', 'note' => 'Données judiciaires : RGPD art.10 — traitements encadrés.'],
            'other' => ['duration' => '6 months', 'note' => 'Sans catégorie précise : conserver le moins possible et documenter la justification.']
        ];

        $result=[];
        foreach ($types as $t) {
            $name = trim($t['name'] ?? '');
            $cat = $t['category'] ?? 'other';
            $custom = $t['customDuration'] ?? null;
            $rec = ['name'=>$name,'category'=>$cat,'recommended'=>'','note'=>'','source'=>'internal'];
            if ($custom) { $rec['recommended']=$custom; $rec['note']='Durée personnalisée fournie par l’utilisateur.'; }
            else { if (isset($recommendations[$cat])) { $rec['recommended']=$recommendations[$cat]['duration']; $rec['note']=$recommendations[$cat]['note']; } else { $rec['recommended']=$recommendations['other']['duration']; $rec['note']=$recommendations['other']['note']; } }
            if ($country==='FR' && $cat==='financial') $rec['note'].=' (France: obligations fiscales jusqu\'à 10 ans).';
            $result[]=$rec;
        }
        echo json_encode(['ok'=>true,'items'=>$result], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Export endpoint: csv/json/md/xlsx
    if ($action === 'export') {
        $format = $_POST['format'] ?? 'csv';
        $items = json_decode($_POST['items'] ?? '[]', true) ?? [];
        $reportName = 'rgpd_report_' . date('Ymd_His');
        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $reportName . '.csv"');
            $out = fopen('php://output','w'); fputcsv($out,['name','category','recommended','note']); foreach($items as $it) fputcsv($out,[$it['name'],$it['category'],$it['recommended'],$it['note']]); fclose($out); exit;
        }
        if ($format === 'json') { header('Content-Type: application/json; charset=utf-8'); header('Content-Disposition: attachment; filename="'.$reportName.'.json"'); echo json_encode($items, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE); exit; }
        if ($format === 'md') { header('Content-Type: text/markdown; charset=utf-8'); header('Content-Disposition: attachment; filename="'.$reportName.'.md"'); $md="# RGPD - Rapport de conservation

"; foreach($items as $it) $md .= "- **{$it['name']}** ({$it['category']}) — {$it['recommended']}
    - {$it['note']}

"; echo $md; exit; }
        if ($format === 'xlsx') {
            // build multiple sheets: Summary + Details
            $summary = [['Nom','Catégorie','Durée recommandée']];
            $details = [['Nom','Catégorie','Durée recommandée','Note']];
            foreach ($items as $it) { $summary[] = [$it['name'],$it['category'],$it['recommended']]; $details[] = [$it['name'],$it['category'],$it['recommended'],$it['note']]; }
            $sheets = ['Summary'=>$summary,'Details'=>$details];
            $zipPath = build_xlsx_from_sheets($sheets);
            if (!$zipPath) { echo json_encode(['ok'=>false,'error'=>'xlsx build failed']); exit; }
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="'.$reportName.'.xlsx"');
            readfile($zipPath); @unlink($zipPath); exit;
        }
    }

    // HMAC signing (as before)
    if ($action === 'sign') {
        $items = json_decode($_POST['items'] ?? '[]', true) ?? [];
        $secret = $_POST['secret'] ?? null;
        if (!$secret) { $secret = bin2hex(random_bytes(16)); }
        $payload = json_encode($items, JSON_UNESCAPED_UNICODE);
        $sig = hash_hmac('sha256', $payload, $secret);
        echo json_encode(['ok'=>true,'signature'=>$sig,'secret'=>$secret]); exit;
    }

    // RSA verification & storage: expects public_pem and signature_base64
    if ($action === 'verify_rsa') {
        $items = json_decode($_POST['items'] ?? '[]', true) ?? [];
        $public_pem = $_POST['public_pem'] ?? null;
        $signature_b64 = $_POST['signature_b64'] ?? null;
        if (!$public_pem || !$signature_b64) { echo json_encode(['ok'=>false,'error'=>'missing public or signature']); exit; }
        $payload = json_encode($items, JSON_UNESCAPED_UNICODE);
        $pub = openssl_get_publickey($public_pem);
        if (!$pub) { echo json_encode(['ok'=>false,'error'=>'invalid public key']); exit; }
        $res = openssl_verify($payload, base64_decode($signature_b64), $pub, OPENSSL_ALGO_SHA256);
        openssl_free_key($pub);
        if ($res === 1) { echo json_encode(['ok'=>true,'verified'=>true]); } else { echo json_encode(['ok'=>true,'verified'=>false]); }
        exit;
    }

    // Config save for DB
    if ($action === 'save_config') {
        $cfg = json_decode($_POST['config'] ?? '{}', true);
        if (!$cfg) { echo json_encode(['ok'=>false,'error'=>'invalid config']); exit; }
        if (!is_dir(__DIR__ . '/config')) @mkdir(__DIR__ . '/config', 0700, true);
        file_put_contents(__DIR__ . '/config/db.json', json_encode($cfg, JSON_PRETTY_PRINT));
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'test_db') {
        $cfg = json_decode($_POST['config'] ?? '{}', true);
        try { $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['dbname']); $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); echo json_encode(['ok'=>true]); }
        catch (Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
        exit;
    }

    if ($action === 'save_report_db') {
        $cfg = json_decode(file_get_contents(__DIR__ . '/config/db.json'), true) ?? null;
        if (!$cfg) { echo json_encode(['ok'=>false,'error'=>'no config']); exit; }
        $items = json_decode($_POST['items'] ?? '[]', true) ?? [];
        $signature = $_POST['signature'] ?? null;
        $public_pem = $_POST['public_pem'] ?? null; // optional
        try {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['dbname']);
            $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $sql = "CREATE TABLE IF NOT EXISTS rgpd_reports (id INT AUTO_INCREMENT PRIMARY KEY, report JSON NOT NULL, signature VARCHAR(255), public_key TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            $pdo->exec($sql);
            $stmt = $pdo->prepare('INSERT INTO rgpd_reports (report, signature, public_key) VALUES (:report, :signature, :public_key)');
            $reportJson = json_encode($items, JSON_UNESCAPED_UNICODE);
            $stmt->execute([':report'=>$reportJson, ':signature'=>$signature, ':public_key'=>$public_pem]);
            echo json_encode(['ok'=>true]);
        } catch (Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
        exit;
    }

    echo json_encode(['ok'=>false,'error'=>'unknown action']); exit;
}

// Render UI
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Calculatrice RGPD - Durées de conservation (Avancé)</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen font-sans p-6">
  <main class="max-w-6xl mx-auto bg-slate-800 rounded-xl p-6 shadow-lg">
    <header class="flex items-start gap-4 relative">
      <img src="https://dihu.fr/appgithub/iconedihu/9.png" width="84" class="rounded-lg" alt="logo">
      <div>
        <h1 id="i18n-title" class="text-2xl font-bold">Calculatrice RGPD — Durée de conservation (Avancé)</h1>
        <p id="i18n-subtitle" class="text-slate-300 mt-1">Estimez, documentez et exportez facilement les durées de conservation par type de données. Rapports XLSX multi-feuilles, signature RSA/WebCrypto, signature HMAC, sauvegarde dans votre base MySQL (configurable).</p>
      </div>
      <div class="absolute right-0 top-0 flex items-center gap-2">
        <button id="lang-fr" title="Français" class="w-8 h-8 rounded-full overflow-hidden ring-2 ring-white/20 hover:ring-white/40">
          <svg viewBox="0 0 3 2" xmlns="http://www.w3.org/2000/svg">
            <rect width="1" height="2" x="0" y="0" fill="#0055A4"/>
            <rect width="1" height="2" x="1" y="0" fill="#FFFFFF"/>
            <rect width="1" height="2" x="2" y="0" fill="#EF4135"/>
          </svg>
        </button>
        <button id="lang-en" title="English" class="w-8 h-8 rounded-full overflow-hidden ring-2 ring-white/20 hover:ring-white/40">
          <svg viewBox="0 0 60 30" xmlns="http://www.w3.org/2000/svg">
            <clipPath id="t"><path d="M0,0 v30 h60 v-30 z"/></clipPath>
            <clipPath id="s"><path d="M30,15 h30 v15 h-60 v-15 z"/></clipPath>
            <g clip-path="url(#t)">
              <path d="M0,0 v30 h60 v-30 z" fill="#012169"/>
              <path d="M0,0 L60,30 M60,0 L0,30" stroke="#FFF" stroke-width="6"/>
              <path d="M0,0 L60,30 M60,0 L0,30" stroke="#C8102E" stroke-width="4" clip-path="url(#s)"/>
              <path d="M30,0 v30 M0,15 h60" stroke="#FFF" stroke-width="10"/>
              <path d="M30,0 v30 M0,15 h60" stroke="#C8102E" stroke-width="6"/>
            </g>
          </svg>
        </button>
      </div>
    </header>

    <section class="mt-6 grid gap-4 md:grid-cols-3">
      <div class="p-4 bg-slate-700 rounded">
        <label id="i18n-country-label" class="block font-semibold mb-2">Pays (ajuste recommandations)</label>
        <select id="country" class="w-full p-2 rounded bg-slate-800">
          <option value="FR">France</option>
          <option value="EU">Union Européenne</option>
          <option value="US">États-Unis</option>
          <option value="Other">Autre</option>
        </select>

        <label id="i18n-add-type" class="block font-semibold mt-4 mb-2">Ajouter un type de donnée</label>
        <input id="datatype" class="w-full p-2 rounded bg-slate-800" placeholder="ex: Adresse email, Logs serveur, Dossier patient">

        <label id="i18n-category-label" class="block mt-3">Catégorie</label>
        <select id="category" class="w-full p-2 rounded bg-slate-800">
          <option value="identification">Identification (nom, id)</option>
          <option value="contact">Contact (email, phone)</option>
          <option value="financial">Données financières</option>
          <option value="sensitive">Données sensibles</option>
          <option value="health">Santé</option>
          <option value="logs">Logs techniques</option>
          <option value="marketing">Marketing / Prospect</option>
          <option value="video">Vidéosurveillance</option>
          <option value="cookies">Cookies / Tracking</option>
          <option value="biometric">Biométrie</option>
          <option value="judicial">Données judiciaires</option>
          <option value="other">Autre</option>
        </select>

        <label id="i18n-custom-label" class="block mt-3">Durée personnalisée (optionnel)</label>
        <input id="custom" class="w-full p-2 rounded bg-slate-800" placeholder="ex: 30 days, 2 years, 6 months">

        <div class="flex gap-2 mt-3">
          <button id="addBtn" class="flex-1 bg-emerald-600 hover:bg-emerald-700 p-2 rounded">Ajouter au tableau</button>
          <button id="clearBtn" class="bg-red-600 hover:bg-red-700 p-2 rounded">Réinitialiser</button>
        </div>

        <div class="mt-4 text-sm text-slate-300">
          <strong id="i18n-tip-title">Astuce :</strong> <span id="i18n-tip-text">ajoutez les types de données que vous traitez puis générez un rapport. Exporte CSV / JSON / MD / XLSX. Signature HMAC ou RSA disponible.</span>
        </div>

        <hr class="my-4 border-slate-700">
        <div>
          <h3 id="i18n-db-title" class="font-semibold">Sauvegarde / Base de données (optionnel)</h3>
          <p class="text-xs text-slate-400">Configurez une connexion MySQL (vos données restent chez vous).</p>
          <input id="dbHost" class="w-full p-2 mt-2 rounded bg-slate-800" placeholder="DB Host (ex: localhost)">
          <input id="dbName" class="w-full p-2 mt-2 rounded bg-slate-800" placeholder="DB Name">
          <input id="dbUser" class="w-full p-2 mt-2 rounded bg-slate-800" placeholder="DB User">
          <input id="dbPass" type="password" class="w-full p-2 mt-2 rounded bg-slate-800" placeholder="DB Password">
          <div class="flex gap-2 mt-2">
            <button id="saveDbCfg" class="flex-1 bg-indigo-600 hover:bg-indigo-700 p-2 rounded">Sauvegarder la config</button>
            <button id="testDb" class="bg-slate-600 hover:bg-slate-500 p-2 rounded">Tester</button>
          </div>
          <div id="dbMsg" class="text-xs mt-2 text-slate-300"></div>
        </div>

      </div>

      <div class="p-4 bg-slate-700 rounded col-span-2">
        <label id="i18n-table-label" class="font-semibold mb-2">Tableau des données</label>
        <div id="tableWrap" class="overflow-auto bg-slate-800 rounded p-2" style="max-height:320px">
          <table id="dataTable" class="w-full text-sm">
            <thead class="text-slate-300">
              <tr><th class="text-left">Nom</th><th>Catégorie</th><th>Durée</th><th></th></tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>

        <div class="mt-4 flex gap-2">
          <button id="recommendBtn" class="bg-blue-600 hover:bg-blue-700 p-2 rounded">Générer recommandations</button>
          <button id="exportBtn" class="bg-yellow-600 hover:bg-yellow-700 p-2 rounded">Exporter</button>
          <button id="signBtn" class="bg-green-600 hover:bg-green-700 p-2 rounded">Signer (HMAC)</button>
          <button id="signRsaBtn" class="bg-purple-600 hover:bg-purple-700 p-2 rounded">Signer (RSA WebCrypto)</button>
          <button id="saveDbReport" class="bg-emerald-500 hover:bg-emerald-600 p-2 rounded">Sauvegarder en BDD</button>
        </div>

        <div id="i18n-disclaimer" class="mt-3 text-xs text-slate-300">Les recommandations sont indicatives et ne remplacent pas un avis juridique.</div>
      </div>
    </section>

    <section id="resultSection" class="mt-6 hidden p-4 bg-slate-700 rounded">
      <h2 id="i18n-results-title" class="font-bold mb-2">Résultats</h2>
      <div id="results" class="grid gap-2"></div>
      <div class="mt-4 flex gap-2">
        <button id="downloadCSV" class="bg-indigo-600 hover:bg-indigo-700 p-2 rounded">Télécharger CSV</button>
        <button id="downloadJSON" class="bg-indigo-600 hover:bg-indigo-700 p-2 rounded">Télécharger JSON</button>
        <button id="downloadMD" class="bg-slate-400 text-slate-900 hover:bg-slate-300 p-2 rounded">Télécharger MD</button>
        <button id="downloadXLSX" class="bg-purple-600 hover:bg-purple-700 p-2 rounded">Télécharger XLSX</button>
      </div>
    </section>

    <footer id="i18n-footer" class="mt-6 text-xs text-slate-400">Fichier généré localement. Pour usage interne / audit.</footer>
  </main>

<script>
// Frontend logic with RSA/WebCrypto signing
// --- Simple i18n ---
const i18n = {
  fr: {
    title: 'Calculatrice RGPD — Durée de conservation (Avancé)',
    subtitle: 'Estimez, documentez et exportez facilement les durées de conservation par type de données. Rapports XLSX multi-feuilles, signature RSA/WebCrypto, signature HMAC, sauvegarde dans votre base MySQL (configurable).',
    countryLabel: 'Pays (ajuste recommandations)',
    addType: 'Ajouter un type de donnée',
    categoryLabel: 'Catégorie',
    customLabel: 'Durée personnalisée (optionnel)',
    tableLabel: 'Tableau des données',
    dbTitle: 'Sauvegarde / Base de données (optionnel)',
    tipTitle: 'Astuce :',
    tipText: 'ajoutez les types de données que vous traitez puis générez un rapport. Exporte CSV / JSON / MD / XLSX. Signature HMAC ou RSA disponible.',
    disclaimer: 'Les recommandations sont indicatives et ne remplacent pas un avis juridique.',
    resultsTitle: 'Résultats',
    footer: 'Fichier généré localement. Pour usage interne / audit.',
    btnRecommend: 'Générer recommandations', btnExport: 'Exporter', btnSign: 'Signer (HMAC)', btnSignRSA: 'Signer (RSA WebCrypto)', btnSaveDB: 'Sauvegarder en BDD',
    dlCSV: 'Télécharger CSV', dlJSON: 'Télécharger JSON', dlMD: 'Télécharger MD', dlXLSX: 'Télécharger XLSX'
  },
  en: {
    title: 'GDPR Calculator — Data Retention (Advanced)',
    subtitle: 'Estimate, document and export data retention by data type. Multi-sheet XLSX reports, RSA/WebCrypto signing, HMAC signing, and MySQL storage (configurable).',
    countryLabel: 'Country (adjusts recommendations)',
    addType: 'Add a data type',
    categoryLabel: 'Category',
    customLabel: 'Custom duration (optional)',
    tableLabel: 'Data table',
    dbTitle: 'Storage / Database (optional)',
    tipTitle: 'Tip:',
    tipText: 'add the data types you process then generate a report. Export CSV / JSON / MD / XLSX. HMAC or RSA signing available.',
    disclaimer: 'Recommendations are indicative and do not replace legal advice.',
    resultsTitle: 'Results',
    footer: 'File generated locally. For internal/audit use.',
    btnRecommend: 'Generate recommendations', btnExport: 'Export', btnSign: 'Sign (HMAC)', btnSignRSA: 'Sign (RSA WebCrypto)', btnSaveDB: 'Save to DB',
    dlCSV: 'Download CSV', dlJSON: 'Download JSON', dlMD: 'Download MD', dlXLSX: 'Download XLSX'
  }
};

function applyLang(lang){
  const t = i18n[lang] || i18n.fr;
  document.getElementById('i18n-title').textContent = t.title;
  document.getElementById('i18n-subtitle').textContent = t.subtitle;
  document.getElementById('i18n-country-label').textContent = t.countryLabel;
  document.getElementById('i18n-add-type').textContent = t.addType;
  document.getElementById('i18n-category-label').textContent = t.categoryLabel;
  document.getElementById('i18n-custom-label').textContent = t.customLabel;
  document.getElementById('i18n-table-label').textContent = t.tableLabel;
  document.getElementById('i18n-db-title').textContent = t.dbTitle;
  document.getElementById('i18n-tip-title').textContent = t.tipTitle;
  document.getElementById('i18n-tip-text').textContent = t.tipText;
  document.getElementById('i18n-disclaimer').textContent = t.disclaimer;
  document.getElementById('i18n-results-title').textContent = t.resultsTitle;
  document.getElementById('i18n-footer').textContent = t.footer;
  // Buttons
  document.getElementById('recommendBtn').textContent = t.btnRecommend;
  document.getElementById('exportBtn').textContent = t.btnExport;
  document.getElementById('signBtn').textContent = t.btnSign;
  document.getElementById('signRsaBtn').textContent = t.btnSignRSA;
  document.getElementById('saveDbReport').textContent = t.btnSaveDB;
  document.getElementById('downloadCSV').textContent = t.dlCSV;
  document.getElementById('downloadJSON').textContent = t.dlJSON;
  document.getElementById('downloadMD').textContent = t.dlMD;
  document.getElementById('downloadXLSX').textContent = t.dlXLSX;
}

document.addEventListener('DOMContentLoaded',()=>{
  // default FR
  applyLang('fr');
  document.getElementById('lang-fr').addEventListener('click',()=>applyLang('fr'));
  document.getElementById('lang-en').addEventListener('click',()=>applyLang('en'));
});
const items = [];
const tbody = document.querySelector('#dataTable tbody');
const addBtn = document.getElementById('addBtn');
const clearBtn = document.getElementById('clearBtn');
const recommendBtn = document.getElementById('recommendBtn');
const exportBtn = document.getElementById('exportBtn');
const resultsDiv = document.getElementById('results');
const resultSection = document.getElementById('resultSection');
let lastReport = null; let lastSignature = null; let lastRsaSignature = null; let lastPublicPem = null;

function renderTable(){ tbody.innerHTML = ''; items.forEach((it, idx)=>{ const tr = document.createElement('tr'); tr.innerHTML = `<td>${escapeHtml(it.name)}</td><td class="text-center">${it.category}</td><td class="text-center">${it.custom||'-'}</td><td class="text-right"><button data-idx="${idx}" class="delBtn text-red-400">Suppr</button></td>`; tbody.appendChild(tr); }); document.querySelectorAll('.delBtn').forEach(b=>b.addEventListener('click',(e)=>{ const i=e.currentTarget.dataset.idx; items.splice(i,1); renderTable(); })); }
function escapeHtml(s){ return (s+'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
addBtn.addEventListener('click', ()=>{ const name=document.getElementById('datatype').value.trim(); const category=document.getElementById('category').value; const custom=document.getElementById('custom').value.trim(); if(!name) return alert('Entrez un nom de type de donnée.'); items.push({name,category,custom}); document.getElementById('datatype').value=''; document.getElementById('custom').value=''; renderTable(); });
clearBtn.addEventListener('click', ()=>{ if(confirm('Réinitialiser la liste ?')){ items.length=0; renderTable(); resultSection.classList.add('hidden'); lastReport=null; } });
recommendBtn.addEventListener('click', async ()=>{ if(items.length===0) return alert('Ajoutez au moins un type.'); const country=document.getElementById('country').value; const res=await fetch(location.href,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'recommend',types:items,country})}); const json=await res.json(); if(!json.ok) return alert('Erreur serveur'); showResults(json.items); });
function showResults(list){ resultSection.classList.remove('hidden'); resultsDiv.innerHTML=''; list.forEach(it=>{ const el=document.createElement('div'); el.className='p-3 bg-slate-800 rounded flex justify-between items-start gap-4'; el.innerHTML=`<div><strong>${escapeHtml(it.name)}</strong> <span class="text-xs text-slate-400">(${it.category})</span><div class="text-sm text-slate-300 mt-1">${escapeHtml(it.note)}</div></div><div class="text-right"><span class="inline-block px-3 py-1 rounded bg-slate-900 text-xs">${escapeHtml(it.recommended)}</span></div>`; resultsDiv.appendChild(el); }); lastReport=list; }

// Export
async function exportReport(format){ if(!lastReport) return alert('Générez d’abord un rapport.'); const form=new FormData(); form.append('action','export'); form.append('format',format); form.append('items',JSON.stringify(lastReport)); const resp=await fetch(location.href,{method:'POST',body:form}); const blob=await resp.blob(); const url=URL.createObjectURL(blob); const a=document.createElement('a'); a.href=url; a.download='rgpd_report.'+format; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url); }
document.getElementById('downloadCSV').addEventListener('click',()=>exportReport('csv'));
document.getElementById('downloadJSON').addEventListener('click',()=>exportReport('json'));
document.getElementById('downloadMD').addEventListener('click',()=>exportReport('md'));
document.getElementById('downloadXLSX').addEventListener('click',()=>exportReport('xlsx'));

// HMAC sign
document.getElementById('signBtn').addEventListener('click', async ()=>{ if(!lastReport) return alert('Générez d’abord un rapport.'); const secret=prompt('Entrez une clé secrète (laisser vide pour en générer une nouvelle)'); const form=new FormData(); form.append('action','sign'); form.append('items',JSON.stringify(lastReport)); if(secret) form.append('secret',secret); const res=await fetch(location.href,{method:'POST',body:form}); const json=await res.json(); if(json.ok){ lastSignature=json.signature; alert(`Signature HMAC : ${json.signature}\nClé secrète : ${json.secret}`); } });

// RSA WebCrypto: generate keypair, sign payload, export public key (PEM), send for verification
async function generateRsaKeyAndSign(payloadStr){
  const enc = new TextEncoder();
  const keyPair = await window.crypto.subtle.generateKey({name:'RSA-PSS',modulusLength:2048,publicExponent:new Uint8Array([1,0,1]),hash:'SHA-256'}, true, ['sign','verify']);
  const signature = await window.crypto.subtle.sign({name:'RSA-PSS', saltLength:32}, keyPair.privateKey, enc.encode(payloadStr));
  const sigB64 = arrayBufferToBase64(signature);
  const spki = await window.crypto.subtle.exportKey('spki', keyPair.publicKey);
  const pem = spkiToPem(spki);
  return {signature_b64: sigB64, public_pem: pem};
}


 
function arrayBufferToBase64(buffer){ let binary=''; const bytes=new Uint8Array(buffer); const len=bytes.byteLength; for(let i=0;i<len;i++){ binary += String.fromCharCode(bytes[i]); } return btoa(binary); }
function base64ToArrayBuffer(b64){ const binary = atob(b64); const len = binary.length; const bytes = new Uint8Array(len); for(let i=0;i<len;i++){ bytes[i]=binary.charCodeAt(i); } return bytes.buffer; }
function spkiToPem(spki){ const b64 = arrayBufferToBase64(spki); let pem = `-----BEGIN PUBLIC KEY-----\n${b64.match(/.{1,64}/g).join('\n')}\n-----END PUBLIC KEY-----\n`; return pem; }

// Sign with RSA via WebCrypto
document.getElementById('signRsaBtn').addEventListener('click', async ()=>{ if(!lastReport) return alert('Générez d’abord un rapport.'); const payload = JSON.stringify(lastReport); const {signature_b64, public_pem} = await generateRsaKeyAndSign(payload); // verify on server
  const form = new FormData(); form.append('action','verify_rsa'); form.append('items', JSON.stringify(lastReport)); form.append('public_pem', public_pem); form.append('signature_b64', signature_b64);
  const res = await fetch(location.href, {method:'POST', body: form}); const json = await res.json(); if(json.ok && json.verified){ lastRsaSignature = signature_b64; lastPublicPem = public_pem; alert('Signature RSA générée et vérifiée par le serveur.'); } else { alert('Signature non vérifiée par le serveur.'); } });

// Save DB config
document.getElementById('saveDbCfg').addEventListener('click', async ()=>{ const cfg={host:document.getElementById('dbHost').value, dbname:document.getElementById('dbName').value, user:document.getElementById('dbUser').value, pass:document.getElementById('dbPass').value}; const form=new FormData(); form.append('action','save_config'); form.append('config', JSON.stringify(cfg)); const res=await fetch(location.href,{method:'POST',body:form}); const json=await res.json(); document.getElementById('dbMsg').textContent = json.ok ? 'Config saved locally.' : ('Error: '+(json.error||'unknown')); });

// Test DB
document.getElementById('testDb').addEventListener('click', async ()=>{ const cfg={host:document.getElementById('dbHost').value, dbname:document.getElementById('dbName').value, user:document.getElementById('dbUser').value, pass:document.getElementById('dbPass').value}; const form=new FormData(); form.append('action','test_db'); form.append('config', JSON.stringify(cfg)); const res=await fetch(location.href,{method:'POST',body:form}); const json=await res.json(); document.getElementById('dbMsg').textContent = json.ok ? 'Connection successful.' : ('Error: '+(json.error||'unknown')); });

// Save report to DB (requires config saved previously)
document.getElementById('saveDbReport').addEventListener('click', async ()=>{ if(!lastReport) return alert('Générez d’abord un rapport.'); const signature = lastRsaSignature || lastSignature || ''; const form = new FormData(); form.append('action','save_report_db'); form.append('items', JSON.stringify(lastReport)); form.append('signature', signature); form.append('public_pem', lastPublicPem || ''); const res = await fetch(location.href,{method:'POST',body:form}); const json = await res.json(); alert(json.ok ? 'Report saved to DB.' : ('Error: ' + (json.error || 'unknown'))); });

</script>
</body>
</html>

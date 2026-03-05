<?php
// index.php

session_start();

$timeout = 1800;
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > $timeout)) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit();
}
$_SESSION['LAST_ACTIVITY'] = time();

ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

$fullAccess = $_SESSION['fullAccess'] ?? false;
$username   = $_SESSION['username'] ?? '';
$isAdmin    = ($username === 'Admin');

require_once('config.php');

if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    if ($action === 'loadRitten') {
        header('Content-Type: application/json');
        if ($username !== 'Admin') {
            $stmt = $pdo->prepare("SELECT * FROM ritten WHERE chauffeur = :username OR chauffeur = '' OR chauffeur IS NULL OR chauffeur = '-- Kies een chauffeur --'");
            $stmt->execute([':username' => $username]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM ritten");
            $stmt->execute();
        }
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit();
    } elseif ($action === 'saveRitten') {
        header('Content-Type: application/json');
        $input = file_get_contents('php://input');
        $ritten = json_decode($input, true);
        if (!is_array($ritten)) {
            $ritten = [];
        }
        $ids = [];
        foreach ($ritten as $i => $rit) {
            $gebiedsnummer = isset($rit['gebiedsnummer']) ? trim($rit['gebiedsnummer']) : '';
            $gereden = (isset($rit['gereden']) && $rit['gereden'] !== "")
                        ? intval(round($rit['gereden']))
                        : 0;
            $status = $rit['status'] ?? '-';
            $wijknaam = $rit['wijknaam'] ?? '';
            if (isset($rit['id']) && !empty($rit['id'])) {
                $stmt = $pdo->prepare("UPDATE ritten SET 
                    collectegebied = :collectegebied,
                    wijknaam = :wijknaam,
                    gebiedsnummer = :gebiedsnummer,
                    contactpersoon = :contactpersoon,
                    adres = :adres,
                    postcodePlaats = :postcodePlaats,
                    telefoonnummer = :telefoonnummer,
                    email = :email,
                    voorkeurAfhaalmoment = :voorkeurAfhaalmoment,
                    verwachtBedrag = :verwachtBedrag,
                    soort = :soort,
                    chauffeur = :chauffeur,
                    afhaalmoment = :afhaalmoment,
                    afhaaltijd = :afhaaltijd,
                    gestort = :gestort,
                    afgerond = 0,
                    gereden = :gereden,
                    status = :status
                    WHERE id = :id");
                $stmt->execute([
                    ':collectegebied'       => $rit['collectegebied'],
                    ':wijknaam'             => $wijknaam,
                    ':gebiedsnummer'        => $gebiedsnummer,
                    ':contactpersoon'       => $rit['contactpersoon'],
                    ':adres'                => $rit['adres'],
                    ':postcodePlaats'       => $rit['postcodePlaats'],
                    ':telefoonnummer'       => $rit['telefoonnummer'],
                    ':email'                => $rit['email'],
                    ':voorkeurAfhaalmoment' => $rit['voorkeurAfhaalmoment'] ?? '',
                    ':verwachtBedrag'       => $rit['verwachtBedrag'] ?? '',
                    ':soort'                => $rit['soort'],
                    ':chauffeur'            => $rit['chauffeur'],
                    ':afhaalmoment'         => $rit['afhaalmoment'],
                    ':afhaaltijd'           => $rit['afhaaltijd'],
                    ':gestort'              => $rit['gestort'] ?? "",
                    ':gereden'              => $gereden,
                    ':status'               => $status,
                    ':id'                   => $rit['id']
                ]);
                $ids[$i] = $rit['id'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO ritten (
                    collectegebied, wijknaam, gebiedsnummer, contactpersoon, adres, postcodePlaats, telefoonnummer, email,
                    voorkeurAfhaalmoment, verwachtBedrag, soort, chauffeur, afhaalmoment, afhaaltijd, gestort, afgerond, gereden, status
                    ) VALUES (
                    :collectegebied, :wijknaam, :gebiedsnummer, :contactpersoon, :adres, :postcodePlaats, :telefoonnummer, :email,
                    :voorkeurAfhaalmoment, :verwachtBedrag, :soort, :chauffeur, :afhaalmoment, :afhaaltijd, :gestort, 0, :gereden, :status
                    )");
                $stmt->execute([
                    ':collectegebied'       => $rit['collectegebied'],
                    ':wijknaam'             => $wijknaam,
                    ':gebiedsnummer'        => $gebiedsnummer,
                    ':contactpersoon'       => $rit['contactpersoon'],
                    ':adres'                => $rit['adres'],
                    ':postcodePlaats'       => $rit['postcodePlaats'],
                    ':telefoonnummer'       => $rit['telefoonnummer'],
                    ':email'                => $rit['email'],
                    ':voorkeurAfhaalmoment' => $rit['voorkeurAfhaalmoment'] ?? '',
                    ':verwachtBedrag'       => $rit['verwachtBedrag'] ?? '',
                    ':soort'                => $rit['soort'],
                    ':chauffeur'            => $rit['chauffeur'],
                    ':afhaalmoment'         => $rit['afhaalmoment'],
                    ':afhaaltijd'           => $rit['afhaaltijd'],
                    ':gestort'              => $rit['gestort'] ?? "",
                    ':gereden'              => $gereden,
                    ':status'               => $rit['status']
                ]);
                $ids[$i] = $pdo->lastInsertId();
            }
        }
        echo json_encode($ids);
        exit();
        
    } elseif ($action === 'deleteRit') {
        header('Content-Type: text/plain');
        $data = json_decode(file_get_contents('php://input'), true);
        if (isset($data['id']) && !empty($data['id'])) {
            $stmt = $pdo->prepare("DELETE FROM ritten WHERE id = :id");
            echo $stmt->execute([':id' => $data['id']]) ? "Rit verwijderd." : "Fout bij verwijderen rit.";
        } else {
            echo "Onjuist verzoek.";
        }
        exit();
        
    } elseif ($action === 'loadChauffeurs') {
        header('Content-Type: application/json');
        if ($username !== 'Admin') {
            $stmt = $pdo->prepare("SELECT naam, email FROM chauffeurs WHERE naam = :username");
            $stmt->execute([':username' => $username]);
        } else {
            $stmt = $pdo->prepare("SELECT naam, email FROM chauffeurs WHERE naam <> 'Admin' ORDER BY naam ASC");
            $stmt->execute();
        }
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit();
        
    } elseif ($action === 'addChauffeur') {
        header('Content-Type: text/plain');
        $data = json_decode(file_get_contents('php://input'), true);
        $naam = trim($data['chauffeur']);
        $email = trim($data['email'] ?? '');
        $iban = trim($data['IBAN'] ?? '');
        $wachtwoordInput = trim($data['wachtwoord'] ?? '');

        if ($naam === "") {
            echo "Lege waarde.";
            exit();
        }
        if ($wachtwoordInput === "") {
            echo "Vul een wachtwoord in.";
            exit();
        }
        if (!preg_match('/^(?=.*\d)(?=.*[^\da-zA-Z]).{8,}$/', $wachtwoordInput)) {
            echo "Ongeldig wachtwoord. Moet minimaal 8 karakters bevatten, inclusief minstens 1 cijfer en 1 leesteken.";
            exit();
        }

        $wachtwoord = password_hash($wachtwoordInput, PASSWORD_DEFAULT);

        // Postcode (optioneel) en lat/lon bepalen op basis van pc4
        $postcode = trim($data['postcode'] ?? '');
        $lat = null;
        $lon = null;
        if ($postcode !== '' && function_exists('extractPostcode4') && function_exists('geocodePostcode')) {
            $pc4 = extractPostcode4($postcode);
            if ($pc4) {
                list($latTmp, $lonTmp) = geocodePostcode($pc4);
                if ($latTmp !== null && $lonTmp !== null) {
                    $lat = (float)$latTmp;
                    $lon = (float)$lonTmp;
                }
            }
        }

        $stmt = $pdo->prepare("INSERT INTO chauffeurs (naam, email, wachtwoord, IBAN, postcode, lat, lon) VALUES (:naam, :email, :wachtwoord, :IBAN, :postcode, :lat, :lon)");
        if ($stmt->execute([
            ':naam'       => $naam, 
            ':email'      => $email, 
            ':wachtwoord' => $wachtwoord,
            ':IBAN'       => $iban,
            ':postcode'   => $postcode,
            ':lat'        => $lat,
            ':lon'        => $lon
        ])) {
            $_POST = [
                'email'      => $email,
                'naam'       => $naam,
                'wachtwoord' => $wachtwoord,
                'IBAN'       => $iban
            ];
            ob_start();
            include 'sendBevestigingInlog.php';
            $mailResponse = ob_get_clean();
            error_log("Internal mail response: " . $mailResponse);
            echo "Chauffeur toegevoegd.";
        } else {
            echo "Fout bij toevoegen chauffeur.";
        }
        exit();
        
    } elseif ($action === 'updateChauffeur') {
        header('Content-Type: text/plain');
        $data = json_decode(file_get_contents('php://input'), true);
        $naam = trim($data['chauffeur']);
        $email = trim($data['email'] ?? '');
        if ($naam !== "") {
            $stmt = $pdo->prepare("UPDATE chauffeurs SET email = :email WHERE naam = :naam");
            echo $stmt->execute([':email' => $email, ':naam' => $naam])
                ? "Email bijgewerkt voor chauffeur."
                : "Fout bij bijwerken email.";
        } else {
            echo "Lege waarde.";
        }
        exit();
        
    } elseif ($action === 'deleteChauffeur') {
        header('Content-Type: text/plain');
        $data = json_decode(file_get_contents('php://input'), true);
        $naam = trim($data['chauffeur']);
        if ($naam !== "") {
            $stmt = $pdo->prepare("DELETE FROM chauffeurs WHERE naam = :naam");
            echo $stmt->execute([':naam' => $naam])
                ? "Chauffeur verwijderd."
                : "Fout bij verwijderen chauffeur.";
        } else {
            echo "Onjuist verzoek.";
        }
        exit();
        
    } elseif ($action === 'loadEmailTemplate') {
        header('Content-Type: application/json');
        $stmt = $pdo->prepare("SELECT email_template FROM instellingen WHERE id = 1");
        $stmt->execute();
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        exit();
        
    } elseif ($action === 'saveEmailTemplate') {
        header('Content-Type: text/plain');
        $data = json_decode(file_get_contents('php://input'), true);
        $template = $data['template'] ?? '';
        $stmt = $pdo->prepare("UPDATE instellingen SET email_template = :template WHERE id = 1");
        echo $stmt->execute([':template' => $template])
            ? "Email template opgeslagen."
            : "Fout bij opslaan email template.";
        exit();
        
    } elseif ($action === 'loadEmailTemplate3') {
        header('Content-Type: application/json');
        $stmt = $pdo->prepare("SELECT email_template FROM instellingen WHERE id = 3");
        $stmt->execute();
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        exit();
    } elseif ($action === 'saveEmailTemplate3') {
        header('Content-Type: text/plain');
        $data = json_decode(file_get_contents('php://input'), true);
        $template = $data['template'] ?? '';
        $stmt = $pdo->prepare("UPDATE instellingen SET email_template = :template WHERE id = 3");
        echo $stmt->execute([':template' => $template])
            ? "E-mail template (Ophaalbevestiging chauffeur) opgeslagen."
            : "Fout bij opslaan e-mail template (Ophaalbevestiging chauffeur).";
        exit();
    } elseif ($action === 'loadEmailTemplate4') {
        header('Content-Type: application/json');
        $stmt = $pdo->prepare("SELECT email_template FROM instellingen WHERE id = 4");
        $stmt->execute();
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        exit();
    } elseif ($action === 'saveEmailTemplate4') {
        header('Content-Type: text/plain');
        $data = json_decode(file_get_contents('php://input'), true);
        $template = $data['template'] ?? '';
        $stmt = $pdo->prepare("UPDATE instellingen SET email_template = :template WHERE id = 4");
        echo $stmt->execute([':template' => $template])
            ? "E-mail template (Ophaalbevestiging contact) opgeslagen."
            : "Fout bij opslaan e-mail template (Ophaalbevestiging contact).";
        exit();
    } elseif ($action === 'loadEmailTemplate5') {
        header('Content-Type: application/json');
        $stmt = $pdo->prepare("SELECT email_template FROM instellingen WHERE id = 5");
        $stmt->execute();
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        exit();
    } elseif ($action === 'saveEmailTemplate5') {
        header('Content-Type: text/plain');
        $data = json_decode(file_get_contents('php://input'), true);
        $template = $data['template'] ?? '';
        $stmt = $pdo->prepare("UPDATE instellingen SET email_template = :template WHERE id = 5");
        echo $stmt->execute([':template' => $template])
            ? "E-mail template (Afronding) opgeslagen."
            : "Fout bij opslaan e-mail template (Afronding).";
        exit();
    } elseif ($action === 'loadEmailTemplate6') {
        header('Content-Type: application/json');
        $stmt = $pdo->prepare("SELECT email_template FROM instellingen WHERE id = 6");
        $stmt->execute();
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        exit();
    } elseif ($action === 'saveEmailTemplate6') {
        header('Content-Type: text/plain');
        $data = json_decode(file_get_contents('php://input'), true);
        $template = $data['template'] ?? '';
        $stmt = $pdo->prepare("UPDATE instellingen SET email_template = :template WHERE id = 6");
        echo $stmt->execute([':template' => $template])
            ? "E-mail template (Afronding WCO) opgeslagen."
            : "Fout bij opslaan e-mail template (Afronding WCO).";
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/png" href="favicon.png"> 
 <title>Geldtransport Overzicht</title>
  <style>
    /* Responsive input resizing */
    table td input, table td select, table td textarea {
        width: 100% !important;
        min-width: 160px;
        overflow: visible;
        white-space: normal;
    }

    table {
        table-layout: auto !important;
    }

    td {
        max-width: 500px;
        white-space: normal !important;
    }

    body {
      background: url('logohome.png') no-repeat 25px 25px;
      background-size: 125px 125px;
      font-family: Arial, sans-serif;
      background-color: #f8f5f0;
      margin: 20px;
    }
    .logout {
      text-align: right;
      margin-bottom: 10px;
    }
    .logout a {
      text-decoration: none;
      color: #c8102e;
      font-weight: bold;
    }
    h1 {
      color: #c8102e;
      text-align: center;
    }
    table {
      border-collapse: collapse;
      width: 100%;
      background-color: #fff;
      box-shadow: 0 0 10px rgba(0,0,0,0.1);
      border-radius: 5px;
      margin-top: 20px;
    }
    table th, table td {
      padding: 12px;
      text-align: left;
      border: 1px solid #ddd;
      vertical-align: top;
    }
    table th:nth-child(1) {
      background-color: #7a7a7a;
      color: #fff;
      width: 50px;
    }
    table th:nth-child(2) {
      background-color: #7a7a7a;
      color: #fff;
      width: 250px;
    }
    table th:nth-child(3),
    table th:nth-child(4),
    table th:nth-child(5) {
      background-color: #7a7a7a;
      color: #fff;
      width: 150px;
    }
    table th:nth-child(n+6) {
      background-color: #f4d03f;
      color: #000;
      width: 150px;
    }
    table th:last-child {
      background-color: #f4d03f;
      color: #000;
    }
    button {
      background-color: #c8102e;
      color: #fff;
      font-size: 16px;
      padding: 10px 20px;
      border: none;
      cursor: pointer;
      border-radius: 5px;
      margin-top: 5px;
    }
    button:hover {
      background-color: #a00e26;
    }
    .delete-button {
      background-color: #28a7a7a;
      color: #fff;
      padding: 5px 10px;
      border: none;
      cursor: pointer;
      border-radius: 5px;
      font-size: 14px;
    }
    .delete-button:disabled {
      background-color: #ccc;
      cursor: not-allowed;
    }
    table td input, table td select {
      margin: 2px;
      width: calc(100% - 4px);
      box-sizing: border-box;
    }
    .no-spinner::-webkit-inner-spin-button,
    .no-spinner::-webkit-outer-spin-button {
      -webkit-appearance: none;
      margin: 0;
    }
    .no-spinner {
      -moz-appearance: textfield;
    }
    table td input.email-short {
      width: calc(100% - 4px);
      display: inline-block;
      vertical-align: middle;
    }
    <?php if ($fullAccess): ?>
    #chauffeur-section {
      margin-bottom: 20px;
    }
    #emailTemplateContainer, 
    #emailTemplateContainer3,
    #emailTemplateContainer4,
    #emailTemplateContainer5,
    #emailTemplateContainer6 {
      width: 50%;
      margin: 20px auto;
      border: 1px solid #ddd;
      padding: 10px;
    }
    #emailTemplateContainer hr,
    #emailTemplateContainer3 hr,
    #emailTemplateContainer4 hr,
    #emailTemplateContainer5 hr,
    #emailTemplateContainer6 hr {
      border: 0;
      border-top: 1px solid #7a7a7a;
      margin-bottom: 10px;
    }
    #emailTemplateContainer h3,
    #emailTemplateContainer3 h3,
    #emailTemplateContainer4 h3,
    #emailTemplateContainer5 h3,
    #emailTemplateContainer6 h3 {
      margin: 0;
      text-align: center;
    }
    textarea {
      width: 100%;
      height: 240px;
      padding: 5px;
      font-size: 14px;
    }
    <?php endif; ?>
    .send-email-btn {
      background-color: red;
      color: #fff;
      font-size: 10px;
      padding: 2px 5px;
      border: none;
      border-radius: 3px;
      cursor: pointer;
      margin-left: 5px;
    }
    .send-email-test-btn {
      display: none;
      background-color: #ffd60a;
      color: #000;
      font-size: 10px;
      padding: 2px 5px;
      border: none;
      border-radius: 3px;
      cursor: pointer;
      margin-left: 5px;
    }

    .chauffeur-cell {
      display: flex;
      flex-direction: column;
    }
    .chauffeur-select-wrapper {
      display: flex;
      align-items: flex-start;
    }
    .button-container {
      display: flex;
      flex-direction: column;
      gap: 5px;
      width: 100%;
    }
    .action-btn {
      background-color: red;
      color: #fff;
      font-size: 10px;
      padding: 2px 5px;
      border: none;
      border-radius: 3px;
      cursor: pointer;
    }
    #sendEmailOverlay {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0,0,0,0.8);
      z-index: 1500;
      align-items: center;
      justify-content: center;
    }
    #sendEmailOverlay .overlayContent {
      background: #fff;
      padding: 20px;
      border-radius: 5px;
      width: 80%;
      max-width: 800px;
      height: 80%;
      position: relative;
    }
    #sendEmailOverlay .closeOverlay {
      position: absolute;
      top: 10px;
      right: 20px;
      font-size: 20px;
      font-weight: bold;
      cursor: pointer;
    }
    #sendEmailOverlay .closeOverlay:before {
      content: "\00d7";
    }
    #sendEmailOverlay iframe {
      width: 100%;
      height: calc(100% - 40px);
      border: none;
    }
    #confirmRitModal {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0,0,0,0.8);
      z-index: 2000;
      align-items: center;
      justify-content: center;
    }
    #confirmRitModal .modalContent {
      background: #fff;
      padding: 20px;
      border-radius: 5px;
      width: 90%;
      max-width: 500px;
      text-align: center;
    }
    #confirmRitModal button {
      margin: 10px;
    }
    #notification {
      position: fixed;
      top: 20px;
      right: 20px;
      background-color: #c8102e;
      color: #fff;
      font-family: Arial, sans-serif;
      font-size: 20px;
      padding: 10px 20px;
      border-radius: 5px;
      box-shadow: 0 0 10px rgba(0,0,0,0.3);
      display: none;
      z-index: 3000;
    }
    #intro-text {
      width: 90%;
      max-width: 800px;
      margin: 20px auto;
      background-color: #fff;
      border: 1px solid #ddd;
      padding: 15px;
      border-radius: 5px;
      box-shadow: 0 0 10px rgba(0,0,0,0.1);
    }
    #intro-text p {
      font-weight: bold;
      color: #c8102e;
    }
    #intro-text ol {
      margin-top: 10px;
    }
  </style>
</head>
<body> 
  <div class="logout"><a href="logout.php">Uitloggen</a></div>
  <div id="notification"></div>
  <h1>Dashboard afhaalopdrachten 2025</h1>
  <div style="height: 25px;"></div>
  <?php if ($fullAccess): ?>
  <div id="chauffeur-section">
    <h2>Chauffeurs</h2>
    <ul id="chauffeurList"></ul>
    <input type="text" id="newChauffeur" placeholder="Naam">
    <input type="text" id="newChauffeurPostcode" placeholder="Postcode">
    <input type="email" id="newChauffeurEmail" placeholder="E-mail">
    <input type="text" id="newChauffeurIBAN" placeholder="IBAN">
    <input type="password" id="newChauffeurPassword" placeholder="Wachtwoord (8k/1getal/1leesteken)">
    <button onclick="addChauffeur()">Toevoegen</button>
  </div>
  <?php endif; ?>
  
  <hr>
  
  <!-- Instructies voor de ritten -->
  <div id="intro-text">


<p><strong>Verklaring van regelkleuren: <br></strong></p>
<span>Wit = Niet toegewezen aan een chauffeur<br></span>
Rood = Toegewezen maar niet afgehandeld<br>
Groen = Afgehandeld.



    <ol>
      <i><b>De kolommen met de gele koppen kan jij als chauffeur aanpassen.</b></i>
      <li>Neem telefonisch contact op met de contactpersoon voor een afspraak.</li>
      <li>Noteer afhaaldatum en -tijd in de rittenlijst hieronder.</li>
      <li>Selecteer in de kolom 'Chauffeur' jouw naam voor de rit.</li>
      <li>Gebruik 'Bevestig deze rit' voor het versturen van een bevestigingsmail aan de contactpersoon en jezelf.</li>
      <li>Haal de collecte-opbrengst op en onderteken samen met de contactpersoon het afhaalbewijs.</li>
      <li>Stort munt- en/of briefgeld bij Geldmaat.</li>
      <li>Vul na afloop het 'Gestort munt bedrag' en 'Gereden kilometers' in.</li>
      <li>Stel de status van de rit op 'Afgehandeld' als de storting gereed is. Het e-mailscherm opent, hier kun je een foto van de transactiebonnen als bijlage opnemen. Verzend de e-mail, deze rit is nu afgehandeld.</li>
      <li>De rode knop 'Rapport' aan de onderzijde geeft je een overzicht van al jouw ritten met de status 'Afgehandeld'. Hier vind je ook jouw gereden kilometers, incl. het te declareren bedrag. Voor de declaratie hoef je zelf <u>geen</u> actie te ondernemen, wij handelen dit verder af.</li>
    </ol>

<a href="https://vimeo.com/1112880116" target="_blank" style="color: red; text-decoration: underline;">Voor een visuele uitleg, bekijk deze video.</a><br>
 
 </div>
  
  <section id="transport-overzicht">
    <h2>Ritten-overzicht</h2>
    <div class="table-container">
      <table>
        <thead>
          <tr>
            <th></th>
            <th>Gegevens contactpersoon</th>
            <th>Voorkeur afhaaldag</th>
            <th>Verwacht totaal-bedrag</th>
            <th>Soort</th>
            <th>Chauffeur</th>
            <th>Afhaaldatum</th>
            <th>Afhaaltijd</th>
            <th>Gestort munt bedrag</th>
            <th>Gereden kilometers</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody id="tableBody"></tbody>
      </table>
    </div>
    <?php if ($fullAccess): ?>
    <button id="add-rit-button" onclick="addRow()">Nieuwe rit toevoegen</button>
    <?php endif; ?>
  </section>
  
  <!-- Rapportknop -->
  <div style="text-align: center; margin-top: 20px;">
    <button id="rapport-button" onclick="openRapport()">Rapport</button>
  </div>
  
  <?php if ($fullAccess): ?>
  <div id="emailTemplateContainer">
    <hr>
    <h3>1e bevestiging</h3>
    <textarea id="emailTemplate"></textarea>
  </div>
  <div id="emailTemplateContainer3">
    <hr>
    <h3>Ophaalbevestiging chauffeur</h3>
    <textarea id="emailTemplate3"></textarea>
  </div>
  <div id="emailTemplateContainer4">
    <hr>
    <h3>Ophaalbevestiging contact</h3>
    <textarea id="emailTemplate4"></textarea>
  </div>
  <div id="emailTemplateContainer5">
    <hr>
    <h3>Afronding</h3>
    <textarea id="emailTemplate5"></textarea>
  </div>
  <div id="emailTemplateContainer6">
    <hr>
    <h3>Afronding WCO</h3>
    <textarea id="emailTemplate6"></textarea>
  </div>
  <?php endif; ?>
  
  <div id="sendEmailOverlay">
    <div class="overlayContent">
      <span class="closeOverlay" onclick="closeSendEmailOverlay()"></span>
      <iframe id="sendEmailIframe" src=""></iframe>
    </div>
  </div>
  
  <div id="confirmRitModal">
    <div class="modalContent">
      <p id="confirmRitMessage"></p>
      <button id="confirmRitBtn" style="background-color: green; color: white;">Bevestig</button>
      <button id="cancelRitBtn" style="background-color: red; color: white;">Annuleer</button>
    </div>
  </div>
  
  <script>
    const fullAccess = <?php echo json_encode($fullAccess); ?>;
    const username = <?php echo json_encode($username); ?>;
    const inactivityTimeout = 1800 * 1000;
    let autoLogoutTimer;
    let currentConfirmRow = null;
    let saveTimer;
    
    // Globale variabelen voor e-mailtemplates
    let emailTemplateChauffeur = "";
    let emailTemplateContact = "";
    
    // ===== Helpers voor validatie =====
    function isEmpty(v){ return v === null || v === undefined || String(v).trim() === ""; }
    function showIncompleteMsg(){ alert("Niet alle velden zijn ingevuld"); }

    // Controle voor "Zend bevestiging aan contactpersoon"
    function validateContactConfirmationRow(row){
      const required = [
        row.querySelector("input[data-field='collectegebied']"),
        row.querySelector("input[data-field='gebiedsnummer']"),
        row.querySelector("input[data-field='contactpersoon']"),
        row.querySelector("input[data-field='adres']"),
        row.querySelector("input[data-field='postcodePlaats']"),
        row.querySelector("input[data-field='telefoonnummer']"),
        row.querySelector("input[data-field='email']"),
        row.querySelector("input[data-field='voorkeurAfhaalmoment']"),
        row.querySelector("input[data-field='verwachtBedrag']"),
        row.querySelector("select[data-field='soort']")
      ];
      for (const el of required){
        if (!el || isEmpty(el.value)) return false;
      }
      return true;
    }

    // Controle voor "Bevestig deze rit" (chauffeur + afhaalmoment + afhaaltijd)
    function validateRitConfirmationRow(row){
      const chauffeur = row.querySelector("select[data-field='chauffeur']");
      const afhaalmoment = row.querySelector("input[data-field='afhaalmoment']");
      const afhaaltijd = row.querySelector("input[data-field='afhaaltijd']");
      if (!chauffeur || chauffeur.value === "-- Kies een chauffeur --") return false;
      if (!afhaalmoment || isEmpty(afhaalmoment.value)) return false;
      if (!afhaaltijd || isEmpty(afhaaltijd.value)) return false;
      return true;
    }

    // Deze functie schakelt de status dropdown in of uit afhankelijk van of er een chauffeur is geselecteerd én
    // de velden 'gestort' en 'gereden' zijn ingevuld.
    function updateStatusDropdown(row) {
      const chauffeurSelect = row.querySelector("select[data-field='chauffeur']");
      const gestortInput = row.querySelector("input[data-field='gestort']");
      const geredenInput = row.querySelector("input[data-field='gereden']");
      const statusSelect = row.querySelector("select[data-field='status']");
      
      if(chauffeurSelect && gestortInput && geredenInput && statusSelect) {
        const chauffeur = chauffeurSelect.value;
        const gestort = gestortInput.value.trim();
        const gereden = geredenInput.value.trim();
        if(chauffeur !== "-- Kies een chauffeur --" && gestort !== "" && gereden !== "") {
          statusSelect.disabled = false;
        } else {
          statusSelect.disabled = true;
          if(statusSelect.value !== "Afgehandeld") {
            statusSelect.value = "-";
          }
        }
      }
    }
    
    function formatFullDate(dateStr) {
      if (!dateStr) return "";
      var date = new Date(dateStr);
      return date.toLocaleDateString('nl-NL', { day: 'numeric', month: 'long', year: 'numeric' });
    }
    function formatTime(timeStr) {
      return timeStr;
    }
    function resetAutoLogoutTimer() {
      clearTimeout(autoLogoutTimer);
      autoLogoutTimer = setTimeout(() => { window.location.href = "logout.php"; }, inactivityTimeout);
    }
    
    window.addEventListener("load", function() {
      resetAutoLogoutTimer();
      document.addEventListener("mousemove", resetAutoLogoutTimer);
      document.addEventListener("keypress", resetAutoLogoutTimer);
      document.addEventListener("click", resetAutoLogoutTimer);
      
      loadEmailTemplate();
      loadEmailTemplate3();
      loadEmailTemplate4();
      loadEmailTemplate5();
      loadEmailTemplate6();
      loadChauffeurs();
      loadRitten();
      
      document.getElementById("cancelRitBtn")?.addEventListener("click", function() {
        document.getElementById("confirmRitModal").style.display = "none";
      });
      document.getElementById("confirmRitBtn")?.addEventListener("click", confirmRit);
    });
    
    function buildUrl(action) {
      return "index.php?action=" + action + "&_=" + new Date().getTime();
    }
    
    function loadEmailTemplate() {
      fetch(buildUrl("loadEmailTemplate"))
        .then(response => response.json())
        .then(data => {
          document.getElementById("emailTemplate").value = (data && data.email_template && data.email_template.trim() !== "") ? data.email_template : "";
        })
        .catch(err => {
          console.error("Fout bij laden e-mailtemplate:", err);
          document.getElementById("emailTemplate").value = "";
        });
    }
    
    function loadEmailTemplate3() {
      fetch(buildUrl("loadEmailTemplate3"))
        .then(response => response.json())
        .then(data => {
          const templateValue = (data && data.email_template && data.email_template.trim() !== "") ? data.email_template : "";
          emailTemplateChauffeur = templateValue;
          const elem = document.getElementById("emailTemplate3");
          if(elem) {
            elem.value = templateValue;
          }
        })
        .catch(err => {
          console.error("Fout bij laden e-mailtemplate 3:", err);
          emailTemplateChauffeur = "";
          const elem = document.getElementById("emailTemplate3");
          if(elem) {
            elem.value = "";
          }
        });
    }
    
    function loadEmailTemplate4() {
      fetch(buildUrl("loadEmailTemplate4"))
        .then(response => response.json())
        .then(data => {
          const templateValue = (data && data.email_template && data.email_template.trim() !== "") ? data.email_template : "";
          emailTemplateContact = templateValue;
          const elem = document.getElementById("emailTemplate4");
          if(elem) {
            elem.value = templateValue;
          }
        })
        .catch(err => {
          console.error("Fout bij laden e-mailtemplate 4:", err);
          emailTemplateContact = "";
          const elem = document.getElementById("emailTemplate4");
          if(elem) {
            elem.value = "";
          }
        });
    }
    
    function loadEmailTemplate5() {
      fetch(buildUrl("loadEmailTemplate5"))
        .then(response => response.json())
        .then(data => {
          if(document.getElementById("emailTemplate5"))
            document.getElementById("emailTemplate5").value = (data && data.email_template && data.email_template.trim() !== "") ? data.email_template : "";
        })
        .catch(err => {
          console.error("Fout bij laden e-mailtemplate 5:", err);
          if(document.getElementById("emailTemplate5"))
            document.getElementById("emailTemplate5").value = "";
        });
    }
    
    function loadEmailTemplate6() {
      fetch(buildUrl("loadEmailTemplate6"))
        .then(response => response.json())
        .then(data => {
          if(document.getElementById("emailTemplate6"))
            document.getElementById("emailTemplate6").value = (data && data.email_template && data.email_template.trim() !== "") ? data.email_template : "";
        })
        .catch(err => {
          console.error("Fout bij laden e-mailtemplate 6:", err);
          if(document.getElementById("emailTemplate6"))
            document.getElementById("emailTemplate6").value = "";
        });
    }
    
    function loadChauffeurs() {
      fetch(buildUrl("loadChauffeurs"))
        .then(response => response.json())
        .then(data => {
          <?php if ($fullAccess): ?>
          const chauffeurList = document.getElementById("chauffeurList");
          chauffeurList.innerHTML = "";
          data.forEach(chauffeur => {
            const li = document.createElement("li");
            if (chauffeur.naam === 'Admin') {
              li.innerHTML = '<strong>' + chauffeur.naam + '</strong>';
            } else {
              li.innerHTML = '<strong>' + chauffeur.naam + '</strong><span style="color:red;cursor:pointer;" onclick="deleteChauffeur(\'' + chauffeur.naam + '\')"> Verwijder</span>';
            }
            chauffeurList.appendChild(li);
          });
          <?php endif; ?>
          updateChauffeurSelect();
        })
        .catch(err => console.error("Fout bij laden chauffeurs:", err));
    }
    
    function updateChauffeurSelect() {
      const selects = document.querySelectorAll("select[data-field='chauffeur']");
      fetch(buildUrl("loadChauffeurs"))
        .then(response => response.json())
        .then(data => {
          selects.forEach(select => {
            const currentValue = select.getAttribute("data-selected") || "";
            select.innerHTML = '<option value="-- Kies een chauffeur --">-- Kies een chauffeur --</option>';
            data.forEach(chauffeur => {
              if(chauffeur.naam !== 'Admin'){
                const option = document.createElement("option");
                option.value = chauffeur.naam;
                option.textContent = chauffeur.naam;
                option.setAttribute("data-email", chauffeur.email);
                select.appendChild(option);
              }
            });
            if (currentValue && currentValue !== "-- Kies een chauffeur --") {
              select.value = currentValue;
            }
            select.addEventListener("change", function() {
              let row = select.closest("tr");
              row.setAttribute("data-chauffeur", select.value);
              updateRowBackground(row);
              autoSave();
            });
          });
          document.querySelectorAll("#tableBody tr").forEach(row => updateRowBackground(row));
        })
        .catch(err => console.error("Fout bij updaten chauffeur select:", err));
    }
    
    function loadRitten() {
      fetch(buildUrl("loadRitten"))
        .then(response => response.json())
        .then(data => {
          const tableBody = document.getElementById("tableBody");
          tableBody.innerHTML = "";
          if (!data || data.length === 0) {
            tableBody.innerHTML = "<tr><td colspan='11'>Geen ritten gevonden.</td></tr>";
          } else {
            data.forEach(rit => {
              tableBody.appendChild(buildRitRow(rit));
            });
            updateChauffeurSelect();
          }
        })
        .catch(err => console.error("Fout bij laden ritten:", err));
    }
    
    function buildRitRow(rit = {}) {
      const tr = document.createElement("tr");
      tr.setAttribute("data-chauffeur", rit.chauffeur ? rit.chauffeur : "-- Kies een chauffeur --");
      tr.innerHTML = `
        <td>${ fullAccess ? '<button class="delete-button" onclick="deleteRow(this)">x</button>' : '' }<input type="hidden" class="rowId" value="${rit.id ? rit.id : ''}"></td>
        <td>
          <div style="display: flex;">
            <input type="text" placeholder="Collectegebied" value="${rit.collectegebied || ''}" ${ fullAccess ? '' : 'disabled'} data-field="collectegebied" style="flex:1;">
            <input type="text" placeholder="0001234" value="${rit.gebiedsnummer || ''}" ${ fullAccess ? '' : 'disabled'} data-field="gebiedsnummer" maxlength="9" style="width: 9ch; margin-left:2px;">
          </div>
          <input type="text" placeholder="Wijknaam (n.v.t. bij heel gebied)" value="${rit.wijknaam || ''}" ${ fullAccess ? '' : 'disabled'} data-field="wijknaam">
          <br>
          <input type="text" placeholder="Contactpersoon" value="${rit.contactpersoon || ''}" ${ fullAccess ? '' : 'disabled'} data-field="contactpersoon"><br>
          <input type="text" placeholder="Adres" value="${rit.adres || ''}" ${ fullAccess ? '' : 'disabled'} data-field="adres"><br>
          <input type="text" placeholder="Postcode/Plaats" value="${rit.postcodePlaats || ''}" ${ fullAccess ? '' : 'disabled'} data-field="postcodePlaats"><br>
          <input type="text" placeholder="Telefoonnummer" value="${rit.telefoonnummer || ''}" ${ fullAccess ? '' : 'disabled'} data-field="telefoonnummer"><br>
          <input type="email" class="email-short" placeholder="E-mail" value="${rit.email || ''}" ${ fullAccess ? '' : 'disabled'} data-field="email">
          ${ fullAccess ? '<button class="send-email-btn" onclick="sendBasisemail(this)">Zend bevestiging aan contactpersoon</button> <button class="send-email-test-btn" onclick="sendBasisemailTest(this)"></button>' : '' }
        </td>
        <td><input type="date" value="${rit.voorkeurAfhaalmoment || ''}" ${ fullAccess ? '' : 'disabled'} data-field="voorkeurAfhaalmoment"></td>
        <td><input type="number" value="${rit.verwachtBedrag || ''}" ${ fullAccess ? '' : 'disabled'} data-field="verwachtBedrag"></td>
        <td>
          <select ${ fullAccess ? '' : 'disabled'} data-field="soort">
            <option value="munt- en briefgeld" ${rit.soort==="munt- en briefgeld" ? "selected" : ""}>munt- en briefgeld</option>
            <option value="alleen muntgeld" ${rit.soort==="alleen muntgeld" ? "selected" : ""}>alleen muntgeld</option>
            <option value="alleen briefgeld" ${rit.soort==="alleen briefgeld" ? "selected" : ""}>alleen briefgeld</option>
          </select>
        </td>
        <td>
          <div class="chauffeur-cell">
            <div class="chauffeur-select-wrapper">
              <select data-field="chauffeur" data-selected="${rit.chauffeur ? rit.chauffeur : '-- Kies een chauffeur --'}">
                <option value="-- Kies een chauffeur --">-- Kies een chauffeur --</option>
              </select>
            </div>
            <div class="button-container">
              <button class="action-btn" onclick="openRitConfirmationModal(this)">Bevestig deze rit</button>
            </div>
          </div>
        </td>
        <td><input type="date" value="${rit.afhaalmoment || ''}" ${ fullAccess ? '' : 'disabled'} data-field="afhaalmoment"></td>
        <td><input type="time" value="${rit.afhaaltijd || ''}" ${ fullAccess ? '' : 'disabled'} data-field="afhaaltijd"></td>
        <td><input type="number" value="${rit.gestort || ''}" ${ fullAccess ? '' : 'disabled'} data-field="gestort"></td>
        <td><input type="number" class="no-spinner" step="1" value="${rit.gereden ? Math.round(rit.gereden) : ''}" ${ fullAccess ? '' : 'disabled'} data-field="gereden"></td>
        <td>
          <select data-field="status">
            <option value="-">-</option>
            <option value="Afgehandeld" ${rit.status==="Afgehandeld" ? "selected" : ""}>Afgehandeld</option>
          </select>
        </td>
      `;
      tr.querySelectorAll("input, select").forEach(addAutoSaveListeners);
      return tr;
    }
    
    // Direct versturen van de basisbevestigingsmail
    function sendBasisemail(btn) {
      let row = btn.closest("tr");

      // >>> VALIDATIE voor contactbevestiging <<<
      if (!validateContactConfirmationRow(row)) {
        showIncompleteMsg();
        return;
      }

      let idField = row.querySelector(".rowId");
      let ritId = idField ? idField.value : "";
      if (!ritId) {
        alert("De rit moet eerst opgeslagen worden voordat een e-mail verstuurd kan worden.");
        return;
      }
      let contactpersoon  = row.querySelector("input[data-field='contactpersoon']").value; 
      let emailContact    = row.querySelector("input[data-field='email']").value;
      let collectegebied  = row.querySelector("input[data-field='collectegebied']").value;
      let gebiedsnummer   = row.querySelector("input[data-field='gebiedsnummer']").value;
      let adres           = row.querySelector("input[data-field='adres']").value;
      let postcodePlaats  = row.querySelector("input[data-field='postcodePlaats']").value;
      let telefoonnummer  = row.querySelector("input[data-field='telefoonnummer']").value;
      let verwacht        = row.querySelector("input[data-field='verwachtBedrag']").value;
      let afhaalmoment    = row.querySelector("input[data-field='afhaalmoment']").value;
      let afhaaltijd      = row.querySelector("input[data-field='afhaaltijd']").value;
      let soort           = row.querySelector("select[data-field='soort']").value;
      let formattedDatum  = formatFullDate(afhaalmoment);
      let formattedTijd   = formatTime(afhaaltijd);
      let template = document.getElementById("emailTemplate").value;
      template = template
        .replace(/\[naam\]/gi, contactpersoon)
        .replace(/\[contactpersoon\]/gi, contactpersoon)
        .replace(/\[collectegebied\]/gi, collectegebied)
        .replace(/\[gebiedsnummer\]/gi, gebiedsnummer)
        .replace(/\[adres\]/gi, adres)
        .replace(/\[postcodePlaats\]/gi, postcodePlaats)
        .replace(/\[telefoonnummer\]/gi, telefoonnummer)
        .replace(/\[verwacht\]/gi, verwacht)
        .replace(/\[verwachtBedrag\]/gi, verwacht)
        .replace(/\[soort\]/gi, soort)
        .replace(/\[afhaalmoment\]/gi, formattedDatum)
        .replace(/\[afhaaltijd\]/gi, formattedTijd)
        .replace(/\[formattedDatum\]/gi, formattedDatum)
        .replace(/\[formattedTijd\]/gi, formattedTijd);
      let busBriefjeUrl = "https://nierstichtingnederland.nl/afstort/busbriefje.php?id=" + ritId;
      template = template.replace(/\[busbriefje\]/gi,
              "<a href='" + busBriefjeUrl + "' target='_blank'>Busbriefje</a>"
            );
      let afhaalBevestigingUrl = "https://nierstichtingnederland.nl/afstort/maakBriefje.php?id=" + ritId;
      template = template.replace(/\[afhaalbevestiging\]/gi,
              "<a href='" + afhaalBevestigingUrl + "' target='_blank'>Afhaalbevestiging</a>"
            );
      fetch("sendBasisemail.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          email: emailContact,
          subject: "Afhaalopdracht collecte-opbrengst",
          body: template,
          van: "noreply@nierstichtingnederland.nl"
        })
      })
      .then(response => response.json())
      .then(result => {
        if(result.status !== "success") {
          alert("Fout bij versturen bevestiging naar contact: " + result.message);
        } else {
          alert("Bevestigingsmail verstuurd naar contactpersoon.");
          // Stuur nu ook bericht naar alle chauffeurs
          sendRitMailToChauffeurs(row, ritId, false);
        }
      })
      .catch(err => {
        console.error("Fout bij versturen basis e-mail:", err);
        alert("Fout bij versturen basis e-mail.");
      });
    }
    
    
    // Helper: stuur rit-mail naar (alle) chauffeurs of testadres
    
    function sendRitMailToChauffeurs(row, ritId, testMode) {
      let collectegebied  = row.querySelector("input[data-field='collectegebied']").value || "";
      let postcodePlaats  = row.querySelector("input[data-field='postcodePlaats']").value || "";

      // Testmodus: zelfde gedrag als voorheen (alleen mail naar Cees)
      if (testMode) {
        const bodyTpl = "Beste Cees,<br><br> Zojuist is er een nieuwe rit in het Dashboard afhaalopdrachten geplaatst. Hierbij moet de collecteopbrengst van [collectegebied] worden afgehaald. Wanneer jij denkt deze rit uit te kunnen voeren, log dan in op https://nierstichtingnederland.nl/afstort en koppel je naam.<br> Succes en goede reis!<br><br> Met vriendelijke groet,<br> Nierstichting collecteteam";
        const body = bodyTpl.replace(/\[collectegebied\]/g, collectegebied);
        fetch("sendBasisemail.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            email: "mailnaarcees@gmail.com",
            subject: "TEST • Nieuwe rit in Dashboard afhaalopdrachten",
            body: body,
            van: "noreply@nierstichtingnederland.nl"
          })
        })
        .then(r => r.json())
        .then(res => {
          if (res.status !== "success") {
            alert("Fout bij versturen TEST-mail: " + (res.message || ""));
          } else {
            alert("TEST-mail verstuurd naar mailnaarcees@gmail.com.");
          }
        })
        .catch(err => {
          console.error("Fout bij TEST-mail:", err);
          alert("Fout bij TEST-mail.");
        });
        return;
      }

      let gekozenNaam = null;

      // Niet in testmodus: vraag via getNearestChauffeur.php wie de dichtstbijzijnde chauffeur is
      fetch("getNearestChauffeur.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: "ritId=" + encodeURIComponent(ritId)
      })
      .then(r => r.json())
      .then(res => {
        if (!res || res.status !== "ok") {
          alert("Kon de dichtsbijzijnde chauffeur niet bepalen: " + (res && res.message ? res.message : "onbekende fout"));
          return;
        }

        const naam   = res.chauffeurNaam || "chauffeur";
        const email  = res.chauffeurEmail;
        const pcPlaatsFromServer = res.postcodePlaats || postcodePlaats;
        const cgFromServer       = res.collectegebied || collectegebied;
        gekozenNaam = naam;

        if (!email) {
          alert("Geen e-mailadres gevonden voor de aangewezen chauffeur.");
          return;
        }

        const declineLink = "https://nierstichtingnederland.nl/afstort/declineRit.php?rit="
          + encodeURIComponent(ritId)
          + "&chauffeur=" + encodeURIComponent(naam);

        const body = "Beste " + naam + ",<br><br>"
          + "Je bent geselecteerd als <b>dichtstbijzijnde chauffeur</b> voor een afhaalopdracht."
          + "<br>Collectegebied: <b>" + cgFromServer + "</b>"
          + "<br>Postcode/plaats: <b>" + pcPlaatsFromServer + "</b>"
          + "<br><br>Als je deze rit gaat uitvoeren, log dan in op het afstortportaal <a href='https://nierstichting.nl/afstort'>https://nierstichting.nl/afstort</a> om de rit op jouw naam te zetten. <br><br>Kun je deze rit <b>niet</b> uitvoeren? <br>Klik dan op onderstaande link, de rit wordt dan aan de volgende dichtst bij wonende chauffeur toegewezen.<br> "
          + "<a href='" + declineLink + "'>Ik kan deze rit niet uitvoeren</a>."
          + "<br><br>Met vriendelijke groet,<br>Nierstichting collectieteam";

        return fetch("sendBasisemail.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            email: email,
            subject: "Afhaalopdracht collecte-opbrengst (chauffeur)",
            body: body,
            van: "noreply@nierstichtingnederland.nl"
          })
        });
      })
      .then(r => r ? r.json() : null)
      .then(res => {
        if (!res) return;
        if (res.status === "success") {
          alert("Chauffeur " + (gekozenNaam || "") + " is aangeschreven.");
        } else {
          alert("Fout bij versturen mail naar aangewezen chauffeur: " + (res.message || ""));
        }
      })
      .catch(err => {
        console.error("Fout bij bepalen/versturen naar aangewezen chauffeur:", err);
        alert("Fout bij bepalen/versturen naar aangewezen chauffeur.");
      });
    }


// Geel TEST-knopje: zelfde als contactbevestiging, maar mail alleen naar Cees
    function sendBasisemailTest(btn) {
      let row = btn.closest("tr");
      let idField = row.querySelector(".rowId");
      let ritId = idField ? idField.value : "";
      if (!ritId) {
        alert("De rit moet eerst opgeslagen worden voordat een e-mail verstuurd kan worden.");
        return;
      }
      // Hergebruik de bestaande contactmail (met validatie)
      sendBasisemail(btn);
      // En stuur de chauffeursvariant in testmodus
      sendRitMailToChauffeurs(row, ritId, true);
    }

    function deleteRow(btn) {
      const row = btn.closest("tr");
      const idField = row.querySelector(".rowId");
      const ritId = idField ? idField.value : "";
      if (!ritId) {
        row.remove();
        return;
      }
      if (!confirm("Weet je zeker dat je deze rit wilt verwijderen?")) return;
      fetch(buildUrl("deleteRit"), {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({ id: ritId })
      })
      .then(response => response.text())
      .then(text => {
        alert(text);
        row.remove();
      })
      .catch(err => console.error("Fout bij verwijderen rit:", err));
    }
    
    function openRitConfirmationModal(btn) {
      const row = btn.closest("tr");

      // >>> VALIDATIE voor "Bevestig deze rit" <<<
      if (!validateRitConfirmationRow(row)) {
        showIncompleteMsg();
        return;
      }

      currentConfirmRow = row;
      document.getElementById("confirmRitMessage").textContent = "Je staat op het punt een bevestiging te sturen aan de contactpersoon. Je ontvangt zelf ook een mail met alle details van deze rit. Weet je zeker dat je deze rit wilt bevestigen?";
      document.getElementById("confirmRitModal").style.display = "flex";
    }
    
    function closeSendEmailOverlay() {
      document.getElementById("sendEmailOverlay").style.display = "none";
    }
    
    function addAutoSaveListeners(el) {
      el.addEventListener("change", function() {
        let row = el.closest("tr");
        if (el.getAttribute("data-field") === "status" && el.value.trim() === "Afgehandeld") {
          openEmailComposeOverlay(row);
        }
        updateRowBackground(row);
        autoSave();
      });
      el.addEventListener("input", autoSave);
      el.addEventListener("blur", autoSave);
    }
    
    function updateRowBackground(row) {
      const statusSelect = row.querySelector("select[data-field='status']");
      const statusValue = statusSelect ? statusSelect.value : "-";
      if (statusValue === "Afgehandeld") {
         row.style.backgroundColor = "#ccffcc";
         const yellowSelectors = "select[data-field='chauffeur'], input[data-field='afhaalmoment'], input[data-field='afhaaltijd'], input[data-field='gestort'], input[data-field='gereden']";
         row.querySelectorAll(yellowSelectors).forEach(field => { field.disabled = true; });
      } else {
         const chauffeurSelect = row.querySelector("select[data-field='chauffeur']");
         if (chauffeurSelect && chauffeurSelect.value && chauffeurSelect.value !== "-- Kies een chauffeur --") {
             row.style.backgroundColor = "#ffcccc";
             const yellowSelectors = "select[data-field='chauffeur'], input[data-field='afhaalmoment'], input[data-field='afhaaltijd'], input[data-field='gestort'], input[data-field='gereden']";
             row.querySelectorAll(yellowSelectors).forEach(field => { field.disabled = false; });
         } else {
             row.style.backgroundColor = "";
         }
      }
      updateStatusDropdown(row);
    }
    
    function autoSave() {
      clearTimeout(saveTimer);
      saveTimer = setTimeout(() => { saveRitten(); }, 1000);
    }
    
    function saveRitten() {
      const rows = document.querySelectorAll("#tableBody tr");
      let ritten = [];
      rows.forEach(row => {
        let data = {
          id: row.querySelector(".rowId") ? row.querySelector(".rowId").value : "",
          collectegebied: row.querySelector("input[data-field='collectegebied']").value,
          wijknaam: row.querySelector("input[data-field='wijknaam']").value,
          gebiedsnummer: row.querySelector("input[data-field='gebiedsnummer']").value,
          contactpersoon: row.querySelector("input[data-field='contactpersoon']").value,
          adres: row.querySelector("input[data-field='adres']").value,
          postcodePlaats: row.querySelector("input[data-field='postcodePlaats']").value,
          telefoonnummer: row.querySelector("input[data-field='telefoonnummer']").value,
          email: row.querySelector("input[data-field='email']").value,
          voorkeurAfhaalmoment: row.querySelector("input[data-field='voorkeurAfhaalmoment']").value,
          verwachtBedrag: row.querySelector("input[data-field='verwachtBedrag']").value,
          soort: row.querySelector("select[data-field='soort']").value,
          chauffeur: row.querySelector("select[data-field='chauffeur']").value,
          afhaalmoment: row.querySelector("input[data-field='afhaalmoment']").value,
          afhaaltijd: row.querySelector("input[data-field='afhaaltijd']").value,
          gestort: row.querySelector("input[data-field='gestort']").value,
          gereden: row.querySelector("input[data-field='gereden']").value ? parseInt(Math.round(row.querySelector("input[data-field='gereden']").value)) : 0,
          status: row.querySelector("select[data-field='status']").value
        };
        ritten.push(data);
      });
      fetch(buildUrl("saveRitten"), {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify(ritten)
      })
      .then(response => response.json())
      .then(ids => {
        const rows = document.querySelectorAll("#tableBody tr");
        rows.forEach((row, index) => {
          let idField = row.querySelector(".rowId");
          if (idField && (!idField.value || idField.value === "")) {
            idField.value = ids[index];
          }
        });
      })
      .catch(err => console.error("Fout bij opslaan:", err));
    }
    
    // Aangepaste functie: als het veld 'Wijknaam' is ingevuld, stuur deze mee in de JSON-data
    function openEmailComposeOverlay(row) {
      var idField = row.querySelector(".rowId");
      var ritId = idField ? idField.value : "";
      if (!ritId) {
        alert("De rit moet eerst opgeslagen worden voordat een e-mail verstuurd kan worden.");
        return;
      }
      var url = "emailCompose.php?id=" + encodeURIComponent(ritId);
      var wijkField = row.querySelector("input[data-field='wijknaam']");
      if (wijkField && wijkField.value.trim() !== "") {
          url += "&useWijk=1&wijknaam=" + encodeURIComponent(wijkField.value.trim());
      }
      document.getElementById("sendEmailIframe").src = url;
      document.getElementById("sendEmailOverlay").style.display = "flex";
    }
    
    function confirmRit() {
      if (!currentConfirmRow) return;

      // Dubbelcheck (mocht modal via externe call geopend zijn)
      if (!validateRitConfirmationRow(currentConfirmRow)) {
        showIncompleteMsg();
        return;
      }
      
      var bodyContact = (document.getElementById("emailTemplate4")) ? document.getElementById("emailTemplate4").value : emailTemplateContact;
      var bodyChauffeur = (document.getElementById("emailTemplate3")) ? document.getElementById("emailTemplate3").value : emailTemplateChauffeur;
      
      var collectegebied  = currentConfirmRow.querySelector("input[data-field='collectegebied']").value;
      var contactpersoon  = currentConfirmRow.querySelector("input[data-field='contactpersoon']").value;
      var adres           = currentConfirmRow.querySelector("input[data-field='adres']").value;
      var postcodePlaats  = currentConfirmRow.querySelector("input[data-field='postcodePlaats']").value;
      var telefoonnummer  = currentConfirmRow.querySelector("input[data-field='telefoonnummer']").value;
      var emailContact    = currentConfirmRow.querySelector("input[data-field='email']").value;
      var soort           = currentConfirmRow.querySelector("select[data-field='soort']").value;
      var verwacht        = currentConfirmRow.querySelector("input[data-field='verwachtBedrag']").value;
      var afhaalmoment    = currentConfirmRow.querySelector("input[data-field='afhaalmoment']").value;
      var afhaaltijd      = currentConfirmRow.querySelector("input[data-field='afhaaltijd']").value;
      
      var formattedDatum  = formatFullDate(afhaalmoment);
      var formattedTijd   = formatTime(afhaaltijd);
      
      var chauffeurSelect = currentConfirmRow.querySelector("select[data-field='chauffeur']");
      var chauffeurNaam   = chauffeurSelect ? chauffeurSelect.value : "";
      var chauffeurEmail  = "";
      if (chauffeurSelect && chauffeurSelect.selectedOptions.length > 0) {
        chauffeurEmail = chauffeurSelect.selectedOptions[0].getAttribute("data-email") || "";
      }
      
      var gebiedsnummer   = currentConfirmRow.querySelector("input[data-field='gebiedsnummer']").value;
      var ritId           = currentConfirmRow.querySelector(".rowId").value;
      
      bodyContact = bodyContact.replace(/\[gebiedsnummer\]/gi, gebiedsnummer)
                               .replace(/\[collectegebied\]/gi, collectegebied)
                               .replace(/\[contactpersoon\]/gi, contactpersoon)
                               .replace(/\[adres\]/gi, adres)
                               .replace(/\[postcodePlaats\]/gi, postcodePlaats)
                               .replace(/\[telefoonnummer\]/gi, telefoonnummer)
                               .replace(/\[verwachtBedrag\]/gi, verwacht)
                               .replace(/\[afhaalmoment\]/gi, formattedDatum)
                               .replace(/\[afhaaltijd\]/gi, formattedTijd)
                               .replace(/\[soort\]/gi, soort);
      
      bodyChauffeur = bodyChauffeur.replace(/\[gebiedsnummer\]/gi, gebiedsnummer)
                                   .replace(/\[collectegebied\]/gi, collectegebied)
                                   .replace(/\[contactpersoon\]/gi, contactpersoon)
                                   .replace(/\[adres\]/gi, adres)
                                   .replace(/\[postcodePlaats\]/gi, postcodePlaats)
                                   .replace(/\[verwachtBedrag\]/gi, verwacht)
                                   .replace(/\[afhaalmoment\]/gi, formattedDatum)
                                   .replace(/\[afhaaltijd\]/gi, formattedTijd)
                                   .replace(/\[soort\]/gi, soort);
      
      bodyContact = bodyContact.replace(/\[contact\]/gi, contactpersoon)
                               .replace(/\[verwacht\]/gi, verwacht)
                               .replace(/\[formattedDatum\]/gi, formattedDatum)
                               .replace(/\[formattedTijd\]/gi, formattedTijd)
                               .replace(/\[chauffeurnaam\]/gi, chauffeurNaam);
      
      bodyChauffeur = bodyChauffeur.replace(/\[chauffeurNaam\]/gi, chauffeurNaam)
                                   .replace(/\[telefoonnummer\]/gi, telefoonnummer)
                                   .replace(/\[formattedDatum\]/gi, formattedDatum)
                                   .replace(/\[formattedTijd\]/gi, formattedTijd)
                                   .replace(/\[verwacht\]/gi, verwacht);
      
      var busBriefjeUrl = "https://nierstichtingnederland.nl/afstort/busbriefje.php?id=" + ritId;
      bodyContact = bodyContact.replace(/\[busbriefje\]/gi, "<a href='" + busBriefjeUrl + "' target='_blank'>Busbriefje</a>")
                               .replace(/\[brusbriefje\]/gi, "<a href='" + busBriefjeUrl + "' target='_blank'>Busbriefje</a>");
      bodyChauffeur = bodyChauffeur.replace(/\[busbriefje\]/gi, "<a href='" + busBriefjeUrl + "' target='_blank'>Busbriefje</a>")
                                   .replace(/\[brusbriefje\]/gi, "<a href='" + busBriefjeUrl + "' target='_blank'>Busbriefje</a>");
      
      var afhaalBevestigingUrl = "https://nierstichtingnederland.nl/afstort/maakBriefje.php?id=" + ritId;
      bodyContact = bodyContact.replace(/\[afhaalbevestiging\]/gi, "<a href='" + afhaalBevestigingUrl + "' target='_blank'>Afhaalbevestiging</a>");
      bodyChauffeur = bodyChauffeur.replace(/\[afhaalbevestiging\]/gi, "<a href='" + afhaalBevestigingUrl + "' target='_blank'>Afhaalbevestiging</a>");
      
      var wijknaam = "";
      var wijkField = currentConfirmRow.querySelector("input[data-field='wijknaam']");
      if(wijkField) {
          wijknaam = wijkField.value.trim();
      }
      
      // Verstuur e-mail naar de contactpersoon
      fetch("sendBevestigingContact.php", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({
          to: emailContact,
          body: bodyContact,
          busbriefje_url: busBriefjeUrl,
          afhaalbevestiging_url: afhaalBevestigingUrl,
          wijknaam: wijknaam
        })
      })
      .then(response => response.json())
      .then(result => {
        if (result.status !== "success") {
          alert("Fout bij versturen bevestiging naar contact: " + result.message);
        }
      })
      .catch(err => {
        console.error("Fout bij versturen bevestiging contact:", err);
        alert("Fout bij versturen bevestiging contact.");
      });
      
      // Verstuur e-mail naar de chauffeur
      fetch("sendBevestigingChauffeur.php", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({
          to: chauffeurEmail,
          body: bodyChauffeur,
          busbriefje_url: busBriefjeUrl,
          afhaalbevestiging_url: afhaalBevestigingUrl,
          wijknaam: wijknaam
        })
      })
      .then(response => response.json())
      .then(result => {
        if (result.status !== "success") {
          alert("Fout bij versturen bevestiging naar chauffeur: " + result.message);
        } else {
          alert("Bevestigingsmails verstuurd.");
          document.getElementById("confirmRitModal").style.display = "none";
        }
      })
      .catch(err => {
        console.error("Fout bij versturen bevestiging chauffeur:", err);
        alert("Fout bij versturen bevestiging chauffeur.");
      });
    }
    
    <?php if ($fullAccess): ?>
    function deleteChauffeur(name) {
      if (!confirm("Weet je zeker dat je chauffeur '" + name + "' wilt verwijderen?")) return;
      fetch(buildUrl("deleteChauffeur"), {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({ chauffeur: name })
      })
      .then(response => response.text())
      .then(text => {
        alert(text);
        loadChauffeurs();
      })
      .catch(err => { console.error("Fout bij verwijderen chauffeur:", err); });
    }
    function addChauffeur() {
      const chauffeurName = document.getElementById("newChauffeur").value.trim();
      const chauffeurPostcode = document.getElementById("newChauffeurPostcode").value.trim();
      const chauffeurEmail = document.getElementById("newChauffeurEmail").value.trim();
      const chauffeurIBAN = document.getElementById("newChauffeurIBAN").value.trim();
      const chauffeurPassword = document.getElementById("newChauffeurPassword").value.trim();
      if (!chauffeurName) { alert("Vul een naam in."); return; }
      fetch(buildUrl("addChauffeur"), {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({ chauffeur: chauffeurName, postcode: chauffeurPostcode, email: chauffeurEmail, IBAN: chauffeurIBAN, wachtwoord: chauffeurPassword })
      })
      .then(response => response.text())
      .then(text => { alert(text); loadChauffeurs(); })
      .catch(err => { console.error("Fout bij toevoegen chauffeur:", err); });
    }
    <?php endif; ?>
    
    function openRapport() {
      if (username !== "Admin") {
        window.open("createRapport.php?username=" + encodeURIComponent(username) + "&status=Afgehandeld", "_blank");
      } else {
        window.open("createRapport.php?status=Afgehandeld", "_blank");
      }
    }
    
    function addRow() {
      const tableBody = document.getElementById("tableBody");
      const newRow = buildRitRow({});
      tableBody.appendChild(newRow);
      updateChauffeurSelect();
      autoSave();
    }
  </script>
</body>
</html>

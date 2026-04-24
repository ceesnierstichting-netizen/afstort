<?php
// emailCompose.php
require_once('session.php');
require_once 'config.php';

if (empty($_SESSION['username']) || empty($_SESSION['twofa_verified'])) {
    header("Location: login.php");
    exit;
}

// Controleer of er een rit-ID is meegegeven
if (!isset($_GET['id'])) {
    echo "Geen rit-ID opgegeven.";
    exit;
}
$ritId = $_GET['id'];

// Ophalen van de ritgegevens
$stmt = $pdo->prepare("SELECT * FROM ritten WHERE id = :id");
$stmt->execute([':id' => $ritId]);
$rit = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$rit) {
    echo "Rit niet gevonden.";
    exit;
}

// Haal het e-mailadres van de contactpersoon (voor 'Aan') op
$aanEmail = $rit['email'];

// Indien er een chauffeur is opgegeven, haal dan zijn/haar e-mailadres op (voor BCC)
$bccEmail = "";
if (!empty($rit['chauffeur'])) {
    $stmt = $pdo->prepare("SELECT email FROM chauffeurs WHERE naam = :naam");
    $stmt->execute([':naam' => $rit['chauffeur']]);
    $chauffeurData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($chauffeurData) {
        $bccEmail = $chauffeurData['email'];
    }
}

// Bepaal welke template-ID gebruikt moet worden
// Als er useWijk=1 is en er is een wijknaam meegegeven, gebruik dan de template met ID 6
if (isset($_GET['useWijk']) && $_GET['useWijk'] == '1' && isset($_GET['wijknaam']) && trim($_GET['wijknaam']) !== "") {
    $templateId = 6;
    $wijknaam = trim($_GET['wijknaam']);
} else {
    $templateId = 5;
    $wijknaam = "";
}

// Ophalen van het e-mailtemplate uit de instellingen (ID 5 of ID 6)
$stmt = $pdo->prepare("SELECT email_template FROM instellingen WHERE id = :id");
$stmt->execute([':id' => $templateId]);
$templateData = $stmt->fetch(PDO::FETCH_ASSOC);
$emailBody = $templateData ? $templateData['email_template'] : "";

// Bouw de arrays met placeholders en vervangingen
$placeholders = array('[contact]', '[collectegebied]', '[soort]', '[gestort]', '[chauffeur]');
$replacements = array(
    (string)$rit['contactpersoon'],
    (string)$rit['collectegebied'],
    (string)$rit['soort'],
    (string)$rit['gestort'],
    (string)$rit['chauffeur']
);

// Als we de template met ID 6 gebruiken, voeg dan de placeholder [wijknaam] toe
if ($templateId == 6) {
    $placeholders[] = '[wijknaam]';
    // Gebruik de meegegeven wijknaam, anders eventueel de waarde uit de ritgegevens
    $replacements[] = $wijknaam ? $wijknaam : (string)$rit['wijknaam'];
}

$emailBody = str_replace($placeholders, $replacements, $emailBody);

// Vaste waarden voor de overige velden
$vanEmail = "noreply@nierstichtingnederland.nl";
$ccEmail = "collecte@nierstichting.nl";

// Pas het onderwerp aan op basis van de gebruikte template
if ($templateId == 5) {
    $subject = "Afstort afgerond";
} else { // $templateId == 6
    $subject = "Afstort wijk afgerond";
}

// Als het formulier wordt verzonden, verwerk de verzending
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aanEmail = $_POST['aan'] ?? $aanEmail;
    $ccEmail = $_POST['cc'] ?? $ccEmail;
    $bccEmail = $_POST['bcc'] ?? $bccEmail;
    $subject = $_POST['subject'] ?? $subject;
    $body = $_POST['body'] ?? $emailBody;
    
    // Als er bijlagen zijn meegegeven, maak dan een multipart/mixed bericht
    if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
        $semi_rand = md5(time());
        $mime_boundary = "==Multipart_Boundary_x{$semi_rand}x";
        $headers = "From: $vanEmail\r\n";
        $headers .= "CC: $ccEmail\r\n";
        if (!empty($bccEmail)) {
            $headers .= "BCC: $bccEmail\r\n";
        }
        $headers .= "MIME-Version: 1.0\r\n" .
                    "Content-Type: multipart/mixed; boundary=\"{$mime_boundary}\"";
        
        // Bouw het multipart bericht op met eerst de HTML-inhoud
        $message = "This is a multi-part message in MIME format.\n\n" .
                   "--{$mime_boundary}\n" .
                   "Content-Type: text/html; charset=\"utf-8\"\n" .
                   "Content-Transfer-Encoding: 7bit\n\n" .
                   $body . "\n\n";
        
        // Verwerk elke bijlage
        for ($i = 0; $i < count($_FILES['attachments']['name']); $i++) {
            if ($_FILES['attachments']['error'][$i] == UPLOAD_ERR_OK) {
                $tmp_name = $_FILES['attachments']['tmp_name'][$i];
                $filename = $_FILES['attachments']['name'][$i];
                $filetype = $_FILES['attachments']['type'][$i];
                $filecontent = chunk_split(base64_encode(file_get_contents($tmp_name)));
                $message .= "--{$mime_boundary}\n" .
                            "Content-Type: {$filetype}; name=\"{$filename}\"\n" .
                            "Content-Disposition: attachment; filename=\"{$filename}\"\n" .
                            "Content-Transfer-Encoding: base64\n\n" .
                            $filecontent . "\n\n";
            }
        }
        $message .= "--{$mime_boundary}--";
    } else {
        // Geen bijlagen: stel een HTML-mail samen
        $headers = "From: $vanEmail\r\n";
        $headers .= "CC: $ccEmail\r\n";
        if (!empty($bccEmail)) {
            $headers .= "BCC: $bccEmail\r\n";
        }
        $headers .= "MIME-Version: 1.0\r\n" .
                    "Content-Type: text/html; charset=\"utf-8\"";
        $message = $body;
    }
    
    // Verstuur de e-mail
    $success = mail($aanEmail, $subject, $message, $headers);
    if ($success) {
        echo "Email verzonden.";
    } else {
        $errorMessage = error_get_last()['message'] ?? 'Geen extra foutinformatie';
        echo "Fout bij verzenden email: " . $errorMessage;
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <?php echo noIndexMetaTag(); ?>
  <title>Email Opstellen</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    h2 { font-size: 20px; margin-bottom: 15px; }
    .header-title { color: #c8102e; }
    label { display: block; margin-top: 10px; }
    input[type="text"],
    input[type="email"],
    textarea { width: 100%; padding: 8px; margin-top: 5px; box-sizing: border-box; }
    input[readonly] { background-color: #eee; }
    .note { font-size: 0.9em; color: #555; margin-bottom: 5px; }
    .button-group {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-top: 5px;
    }
    .btn {
      display: inline-block;
      width: 150px;
      padding: 6px 12px;
      font-size: 14px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      text-align: center;
      margin-bottom: 5px;
    }
    .send-button {
      background-color: #c8102e;
      color: white;
    }
    .send-button:hover {
      background-color: #a00e26;
    }
    .file-button {
      background-color: #e0e0e0;
      color: black;
    }
    .file-button:hover {
      background-color: #ccc;
    }
    #attachments { display: none; }
  </style>
</head>
<body>
  <h2 class="header-title">Afdracht-gegevens versturen</h2>
  <form method="POST" enctype="multipart/form-data">
    <label for="van">Van:</label>
    <input type="email" id="van" name="van" value="<?php echo $vanEmail; ?>" readonly>
    
    <label for="aan">Aan:</label>
    <input type="email" id="aan" name="aan" value="<?php echo htmlspecialchars($aanEmail); ?>" readonly>
    
    <label for="cc">CC:</label>
    <input type="email" id="cc" name="cc" value="<?php echo $ccEmail; ?>" readonly>
    
    <label for="bcc">BCC:</label>
    <input type="email" id="bcc" name="bcc" value="<?php echo htmlspecialchars($bccEmail); ?>" readonly>
    
    <label for="subject">Onderwerp:</label>
    <input type="text" id="subject" name="subject" value="<?php echo htmlspecialchars($subject); ?>" readonly>
    
    <label for="body">Bericht:</label>
    <textarea id="body" name="body" rows="10" readonly><?php echo htmlspecialchars($emailBody); ?></textarea>
    
    <label>Bijlagen:</label>
    <p class="note">Opmerking: Meerdere bijlagen mogelijk, in JPG, PNG of PDF formaat.</p>
    <div class="button-group">
      <input type="file" id="attachments" name="attachments[]" multiple>
      <label for="attachments" class="btn file-button">Bijlage toevoegen</label>
      <button type="submit" class="btn send-button">Verzend afdracht</button>
    </div>
    
    <input type="hidden" name="ritId" value="<?php echo htmlspecialchars($ritId); ?>">
  </form>
  
  <script>
    document.getElementById('attachments').addEventListener('change', function() {
      var fileNames = [];
      for (var i = 0; i < this.files.length; i++) {
        fileNames.push(this.files[i].name);
      }
      console.log("Geselecteerde bestanden: " + fileNames.join(", "));
    });
  </script>
</body>
</html>

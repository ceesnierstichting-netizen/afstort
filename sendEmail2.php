<?php
if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex', true);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verwerking: E-mail verzenden met bijlagen

    // Ophalen van formuliergegevens
    $to      = $_POST['to'] ?? '';
    $from    = $_POST['from'] ?? '';
    $cc      = $_POST['cc'] ?? '';
    $bcc     = $_POST['bcc'] ?? '';
    $subject = $_POST['subject'] ?? 'Afronding afstort collecte-opbrengst';
    $body    = $_POST['body'] ?? '';

    // Maak een unieke boundary-string
    $boundary = md5(time());

    // Stel de headers in voor een multipart e-mail
    $headers  = "From: $from\r\n";
    if ($cc) {
        $headers .= "Cc: $cc\r\n";
    }
    if ($bcc) {
        $headers .= "Bcc: $bcc\r\n";
    }
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"" . $boundary . "\"\r\n";

    // Bouw de e-mail body: eerst het HTML-deel
    $message  = "--" . $boundary . "\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $message .= $body . "\r\n\r\n";

    // Verwerk eventuele bijlagen (indien aanwezig)
    if (isset($_FILES['attachments']) && $_FILES['attachments']['error'][0] != UPLOAD_ERR_NO_FILE) {
        for ($i = 0; $i < count($_FILES['attachments']['name']); $i++) {
            if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                $file_tmp  = $_FILES['attachments']['tmp_name'][$i];
                $file_name = $_FILES['attachments']['name'][$i];
                $file_type = $_FILES['attachments']['type'][$i];

                // Lees het bestand en codeer de inhoud in base64
                $file_content = file_get_contents($file_tmp);
                $file_content = chunk_split(base64_encode($file_content));

                $message .= "--" . $boundary . "\r\n";
                $message .= "Content-Type: $file_type; name=\"" . $file_name . "\"\r\n";
                $message .= "Content-Transfer-Encoding: base64\r\n";
                $message .= "Content-Disposition: attachment; filename=\"" . $file_name . "\"\r\n\r\n";
                $message .= $file_content . "\r\n\r\n";
            }
        }
    }

    // Sluit de multipart boodschap af
    $message .= "--" . $boundary . "--";

    // Verstuur de e-mail
    $mailSent = mail($to, $subject, $message, $headers);
    if ($mailSent) {
        echo "<p>E-mail is verstuurd.</p>";
    } else {
        echo "<p>Fout bij versturen e-mail.</p>";
    }
    exit;
} else {
    // GET: Toon het e-mailformulier met vooraf ingevulde gegevens

    // Verwachte GET-parameters: to, from, cc, bcc, naam, gebied, soort, gestort, chauffeur
    $to        = $_GET['to'] ?? '';
    $from      = $_GET['from'] ?? 'noreply@nierstichtingnederland.nl';
    $cc        = $_GET['cc'] ?? 'collecte@nierstichtingnederland.nl';
    $bcc       = $_GET['bcc'] ?? '';
    $naam      = $_GET['naam'] ?? '';
    $gebied    = $_GET['gebied'] ?? '';  // Dit moet de waarde van "collectegebied" bevatten
    $soort     = $_GET['soort'] ?? '';
    $gestort   = $_GET['gestort'] ?? '';
    $chauffeur = $_GET['chauffeur'] ?? '';

    $subject = "Afronding afstort collecte-opbrengst";

    // Bouw de e-mailtemplate en vervang de placeholders
    $template = "Beste [naam],<br><br>" .
                "Hiermee bevestig ik de collecte-opbrengst van [gebied] te hebben afgestort bij Geldmaat. <br>Het totaalbedrag aan [soort] is geworden [gestort] euro.<br><br>" .
                "Bijgaand vind je de transactiebon(nen) waarmee je de afrekenstaat in CollecteWeb kunt invullen. Deze bon(nen) kun je als bijlage in de afrekenstaat opnemen.<br>" .
                "Ik wens je veel succes!<br><br>" .
                "Met vriendelijke groet,<br>" .
                "[chauffeur]";
    $template = str_replace("[naam]", $naam, $template);
    $template = str_replace("[gebied]", $gebied, $template);
    $template = str_replace("[soort]", $soort, $template);
    $template = str_replace("[gestort]", $gestort, $template);
    $template = str_replace("[chauffeur]", $chauffeur, $template);
    ?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
    <meta name="googlebot" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
    <title>E-mail versturen</title>
    <style>
      body { font-family: Arial, sans-serif; padding: 20px; }
      input, textarea { width: 100%; margin-bottom: 10px; }
      label { display: block; margin-bottom: 8px; }
      button { padding: 10px 20px; background-color: #c8102e; color: #fff; border: none; border-radius: 5px; cursor: pointer; }
      h2 { color: red; }
    </style>
</head>
<body>
    <h2>Afronding afstort collecte-opbrengst</h2>
    <form method="post" enctype="multipart/form-data" id="emailForm">
        <label>Van:
            <input type="email" name="from" value="<?php echo htmlspecialchars($from); ?>" readonly>
        </label>
        <label>Aan:
            <input type="email" name="to" value="<?php echo htmlspecialchars($to); ?>" readonly>
        </label>
        <label>Cc:
            <input type="email" name="cc" value="<?php echo htmlspecialchars($cc); ?>" readonly>
        </label>
        <label>Bcc:
            <input type="email" name="bcc" value="<?php echo htmlspecialchars($bcc); ?>" readonly>
        </label>
        <label>Onderwerp:
            <input type="text" name="subject" value="<?php echo htmlspecialchars($subject); ?>" readonly>
        </label>
        <label>Bericht:</label>
        <textarea name="body" rows="10"><?php echo htmlspecialchars($template); ?></textarea>
        <label>Bijlage(n):</label>
        <input type="file" name="attachments[]" multiple>
        <button type="submit">Verstuur e-mail</button>
    </form>
</body>
</html>
<?php
}
?>

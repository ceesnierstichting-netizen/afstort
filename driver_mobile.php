<?php
require_once('session.php');
require_once('config.php');

if (!isset($_SESSION['username']) || empty($_SESSION['twofa_verified'])) {
    header("Location: login.php");
    exit();
}

refreshCurrentUserAccess($pdo);

$fullAccess = !empty($_SESSION['fullAccess']);
$username = $_SESSION['username'] ?? '';

if ($fullAccess) {
    header("Location: index.php");
    exit();
}

if (isset($_GET['view']) && $_GET['view'] === 'desktop') {
    header("Location: index.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo noIndexMetaTag(); ?>
    <title>Mijn ritten</title>
    <link rel="icon" type="image/png" href="favicon.png">
    <style>
        :root {
            color-scheme: light;
            --bg: #f5f1e8;
            --surface: #fffdf8;
            --surface-strong: #fff8eb;
            --text: #1e1d1a;
            --muted: #686255;
            --line: #ddd1b7;
            --brand: #b0212e;
            --brand-dark: #8c1924;
            --ok-bg: #e5f7e7;
            --ok-text: #245c2b;
            --warn-bg: #fff1d6;
            --warn-text: #8a5610;
            --idle-bg: #f3ede0;
            --shadow: 0 16px 40px rgba(77, 61, 32, 0.12);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "Segoe UI", Arial, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(176, 33, 46, 0.08), transparent 32%),
                linear-gradient(180deg, #f8f5ee 0%, var(--bg) 100%);
            color: var(--text);
        }

        .page {
            max-width: 760px;
            margin: 0 auto;
            padding: 18px 14px 28px;
        }

        .hero {
            background: linear-gradient(135deg, #fffaf0 0%, #f9f0dc 100%);
            border: 1px solid rgba(176, 33, 46, 0.12);
            border-radius: 24px;
            padding: 18px 18px 16px;
            box-shadow: var(--shadow);
        }

        .hero-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .hero img {
            width: 56px;
            height: 56px;
            object-fit: contain;
        }

        .eyebrow {
            font-size: 0.82rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--muted);
            margin: 0 0 6px;
        }

        h1 {
            margin: 0;
            font-size: 1.8rem;
            line-height: 1.1;
            color: var(--brand-dark);
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 16px;
        }

        .hero-actions a,
        .hero-actions button {
            appearance: none;
            border: 0;
            border-radius: 999px;
            padding: 11px 14px;
            font-size: 0.96rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .primary-link {
            background: var(--brand);
            color: #fff;
        }

        .secondary-link {
            background: rgba(255, 255, 255, 0.8);
            color: var(--text);
            border: 1px solid var(--line);
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin-top: 16px;
        }

        .summary-card {
            background: rgba(255, 255, 255, 0.72);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 12px 14px;
        }

        .summary-label {
            display: block;
            font-size: 0.8rem;
            color: var(--muted);
            margin-bottom: 4px;
        }

        .summary-value {
            font-size: 1.25rem;
            font-weight: 700;
        }

        .section-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: 22px 2px 12px;
        }

        .section-title h2 {
            margin: 0;
            font-size: 1.15rem;
        }

        .helper {
            color: var(--muted);
            font-size: 0.9rem;
        }

        #ritList {
            display: grid;
            gap: 14px;
        }

        .rit-card {
            background: var(--surface);
            border-radius: 22px;
            border: 1px solid var(--line);
            box-shadow: 0 10px 28px rgba(61, 47, 24, 0.08);
            overflow: hidden;
        }

        .rit-card.pending {
            border-color: #efc98b;
            background: linear-gradient(180deg, #fffaf3 0%, #fffdfa 100%);
        }

        .rit-card.done {
            border-color: #bcdcbc;
            background: linear-gradient(180deg, #f2fbf3 0%, #fffdfa 100%);
        }

        .rit-card.unassigned {
            border-color: #ddd4c3;
        }

        .rit-head {
            padding: 16px 16px 12px;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .rit-head h3 {
            margin: 0 0 5px;
            font-size: 1.08rem;
            line-height: 1.25;
        }

        .rit-subtitle {
            color: var(--muted);
            font-size: 0.9rem;
            line-height: 1.4;
        }

        .status-pill {
            flex-shrink: 0;
            border-radius: 999px;
            padding: 8px 10px;
            font-size: 0.82rem;
            font-weight: 700;
        }

        .status-pill.done {
            background: var(--ok-bg);
            color: var(--ok-text);
        }

        .status-pill.pending {
            background: var(--warn-bg);
            color: var(--warn-text);
        }

        .status-pill.unassigned {
            background: var(--idle-bg);
            color: #645d50;
        }

        .rit-body {
            padding: 0 16px 16px;
        }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }

        .meta-item {
            background: var(--surface-strong);
            border: 1px solid #eadfc7;
            border-radius: 16px;
            padding: 10px 12px;
        }

        .meta-item strong,
        .meta-item span {
            display: block;
        }

        .meta-item strong {
            font-size: 0.78rem;
            color: var(--muted);
            margin-bottom: 5px;
        }

        .meta-item span {
            font-size: 0.97rem;
            line-height: 1.35;
            word-break: break-word;
        }

        .rit-links {
            display: grid;
            gap: 10px;
        }

        .rit-links a {
            display: block;
            text-align: center;
            border-radius: 14px;
            padding: 12px 14px;
            font-weight: 700;
            text-decoration: none;
        }

        .route-link {
            background: #1f5f46;
            color: #fff;
        }

        .phone-link,
        .mail-link {
            background: #fff;
            color: var(--text);
            border: 1px solid var(--line);
        }

        .empty-state,
        .loading-state {
            background: rgba(255, 255, 255, 0.8);
            border: 1px dashed var(--line);
            border-radius: 20px;
            padding: 18px;
            text-align: center;
            color: var(--muted);
        }

        @media (max-width: 520px) {
            .summary,
            .meta-grid {
                grid-template-columns: 1fr;
            }

            .hero-top,
            .rit-head,
            .section-title {
                align-items: flex-start;
                flex-direction: column;
            }

            .status-pill {
                align-self: flex-start;
            }
        }
    </style>
</head>
<body>
    <main class="page">
        <section class="hero">
            <div class="hero-top">
                <img src="logohome.png" alt="Logo">
                <div>
                    <p class="eyebrow">Mobiele weergave</p>
                    <h1>Mijn ritten</h1>
                </div>
            </div>
            <div class="hero-actions">
                <a class="secondary-link" href="logout.php">Uitloggen</a>
            </div>
            <div class="summary">
                <div class="summary-card">
                    <span class="summary-label">Ingelogd als</span>
                    <span class="summary-value"><?php echo htmlspecialchars($username); ?></span>
                </div>
            </div>
        </section>

        <div class="section-title">
            <h2>Ritten onderweg</h2>
        </div>

        <div class="hero-actions" style="margin-top: 0; margin-bottom: 14px;">
            <button type="button" class="secondary-link" onclick="loadRitten()">Vernieuwen</button>
        </div>

        <div id="ritList">
            <div class="loading-state">Ritten worden geladen…</div>
        </div>
    </main>

    <script>
        const username = <?php echo json_encode($username); ?>;

        function escapeHtml(value) {
            return String(value ?? "")
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#39;");
        }

        function normalizeChauffeurValue(value) {
            const v = (value || "").trim();
            if (!v || v === "-- Kies een chauffeur --" || v === "Kies een chauffeur") {
                return "Chauffeur kiezen";
            }
            return v;
        }

        function buildMapsLink(rit) {
            const parts = [rit.adres || "", rit.postcodePlaats || ""].filter(Boolean).join(", ");
            if (!parts) {
                return "";
            }

            return "https://www.google.com/maps/search/?api=1&query=" + encodeURIComponent(parts);
        }

        function formatDate(value) {
            if (!value) {
                return "Nog niet gepland";
            }

            const date = new Date(value + "T00:00:00");
            if (Number.isNaN(date.getTime())) {
                return value;
            }

            return date.toLocaleDateString("nl-NL", { day: "numeric", month: "long", year: "numeric" });
        }

        function formatTime(value) {
            if (!value) {
                return "Nog niet ingevuld";
            }

            return value.slice(0, 5);
        }

        function getStatusClass(rit) {
            if ((rit.status || "").trim() === "Afgehandeld") {
                return "done";
            }

            if (normalizeChauffeurValue(rit.chauffeur) !== "Chauffeur kiezen") {
                return "pending";
            }

            return "unassigned";
        }

        function getStatusLabel(rit) {
            if ((rit.status || "").trim() === "Afgehandeld") {
                return "Afgehandeld";
            }

            if (normalizeChauffeurValue(rit.chauffeur) !== "Chauffeur kiezen") {
                return "In behandeling";
            }

            return "Open opdracht";
        }

        function buildMetaItem(label, value) {
            return `
                <div class="meta-item">
                    <strong>${escapeHtml(label)}</strong>
                    <span>${escapeHtml(value || "-")}</span>
                </div>
            `;
        }

        function buildRitCard(rit) {
            const statusClass = getStatusClass(rit);
            const statusLabel = getStatusLabel(rit);
            const mapsLink = buildMapsLink(rit);
            const phoneLink = rit.telefoonnummer ? `tel:${encodeURIComponent(rit.telefoonnummer)}` : "";
            const mailLink = rit.email ? `mailto:${encodeURIComponent(rit.email)}` : "";

            return `
                <article class="rit-card ${statusClass}">
                    <div class="rit-head">
                        <div>
                            <h3>${escapeHtml(rit.collectegebied || "Collectegebied nog leeg")}</h3>
                            <div class="rit-subtitle">${escapeHtml(rit.contactpersoon || "Geen contactpersoon")} · ${escapeHtml(rit.postcodePlaats || "Geen postcode/plaats")}</div>
                        </div>
                        <span class="status-pill ${statusClass}">${escapeHtml(statusLabel)}</span>
                    </div>
                    <div class="rit-body">
                        <div class="meta-grid">
                            ${buildMetaItem("Adres", [rit.adres, rit.postcodePlaats].filter(Boolean).join(", "))}
                            ${buildMetaItem("Telefoon", rit.telefoonnummer || "Niet ingevuld")}
                            ${buildMetaItem("Afhaaldatum", formatDate(rit.afhaalmoment))}
                            ${buildMetaItem("Afhaaltijd", formatTime(rit.afhaaltijd))}
                            ${buildMetaItem("Soort", rit.soort || "Niet ingevuld")}
                            ${buildMetaItem("Verwacht bedrag", rit.verwachtBedrag ? "€ " + rit.verwachtBedrag : "Niet ingevuld")}
                            ${buildMetaItem("Chauffeur", normalizeChauffeurValue(rit.chauffeur) === "Chauffeur kiezen" ? "Nog niet toegewezen" : rit.chauffeur)}
                            ${buildMetaItem("Gestort / km", `${rit.gestort || "-"} / ${rit.gereden || "-"}`)}
                        </div>
                        <div class="rit-links">
                            ${mapsLink ? `<a class="route-link" href="${mapsLink}" target="_blank" rel="noopener noreferrer">Open route</a>` : ""}
                            ${phoneLink ? `<a class="phone-link" href="${phoneLink}">Bel contactpersoon</a>` : ""}
                            ${mailLink ? `<a class="mail-link" href="${mailLink}">Mail contactpersoon</a>` : ""}
                        </div>
                    </div>
                </article>
            `;
        }

        function renderRitten(data) {
            const list = document.getElementById("ritList");
            if (!Array.isArray(data) || data.length === 0) {
                list.innerHTML = '<div class="empty-state">Er zijn nu geen ritten zichtbaar voor jouw account.</div>';
                return;
            }

            list.innerHTML = data.map(buildRitCard).join("");
        }

        function filterMobileRitten(data) {
            if (!Array.isArray(data)) {
                return [];
            }

            return data
                .filter(rit => {
                    const chauffeur = normalizeChauffeurValue(rit.chauffeur);
                    return chauffeur === username
                        || chauffeur === "Chauffeur kiezen"
                        || Boolean(rit.is_aangeboden_aan_ingelogde_chauffeur);
                })
                .filter(rit => (rit.status || "").trim() !== "Afgehandeld")
                .sort((a, b) => {
                    const dateA = (a.afhaalmoment || "9999-12-31") + " " + (a.afhaaltijd || "99:99");
                    const dateB = (b.afhaalmoment || "9999-12-31") + " " + (b.afhaaltijd || "99:99");
                    return dateA.localeCompare(dateB) || String(a.collectegebied || "").localeCompare(String(b.collectegebied || ""));
                });
        }

        function loadRitten() {
            const list = document.getElementById("ritList");
            list.innerHTML = '<div class="loading-state">Ritten worden geladen…</div>';

            fetch("index.php?action=loadRitten&_=" + Date.now())
                .then(async response => {
                    const text = await response.text();
                    let payload = [];

                    try {
                        payload = text ? JSON.parse(text) : [];
                    } catch (err) {
                        throw new Error(text || "Onleesbare serverrespons.");
                    }

                    if (!response.ok || !Array.isArray(payload)) {
                        throw new Error(payload?.message || "Laden van ritten is mislukt.");
                    }

                    return payload;
                })
                .then(filterMobileRitten)
                .then(renderRitten)
                .catch(err => {
                    list.innerHTML = `<div class="empty-state">${escapeHtml(err.message || "Laden van ritten is mislukt.")}</div>`;
                });
        }

        loadRitten();
    </script>
</body>
</html>

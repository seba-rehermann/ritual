<?php
session_start();
require_once __DIR__ . '/config.php';

/* ── Home config (read-only inline — shares data/ via Docker volume) ── */
function home_cfg_defaults(): array
{
    return [
        'site_title'     => 'SEBAJI',
        'site_tagline'   => 'El Despertar',
        'share_ws_url'   => '',
        'share_tg_url'   => '',
        'share_yt_url'   => '',
        'player_idle'    => '◈ SELECCIONA UNA OFRENDA ◈',
        'btn_continuous' => 'Sintonía Continua',
        'btn_random'     => 'Azar Sagrado',
        'chat_ph_name'   => 'Tu nombre',
        'chat_ph_msg'    => 'Comparte tu sentir...',
        'chat_btn'       => 'Elevar Pensamiento',
        'med_ayu_title'  => 'AYAHUASCA',
        'med_ayu_sub'    => 'La Soga del Alma',
        'med_rape_title' => 'RAPÉ',
        'med_rape_sub'   => 'El Soplo Sagrado',
    ];
}
$def = home_cfg_defaults();
$_hcfg_raw = @file_get_contents(__DIR__ . '/data/home_config.json');
$_hcfg_json = $_hcfg_raw ? json_decode($_hcfg_raw, true) : null;
$home_cfg = is_array($_hcfg_json) ? array_merge($def, array_intersect_key($_hcfg_json, $def)) : $def;
// Cast all values to string for safe use in htmlspecialchars
$home_cfg = array_map('strval', $home_cfg);
unset($def, $_hcfg_raw, $_hcfg_json);

$yt_file        = 'data/youtube_links.txt';
$chat_file      = 'data/mensajes.txt';
$presencia_file = 'data/presencia.txt';
$visitas_file   = 'data/visitas.txt';

/* ── Session tokens ── */
if (empty($_SESSION['play_notif_token'])) {
    $_SESSION['play_notif_token'] = bin2hex(random_bytes(16));
}
if (empty($_SESSION['chat_csrf_token'])) {
    $_SESSION['chat_csrf_token'] = bin2hex(random_bytes(16));
}

/* ── Play notification (AJAX, exits early) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['play_notif'])) {
    header('Content-Type: text/plain; charset=utf-8');
    $tok = isset($_POST['token']) ? (string) $_POST['token'] : '';
    if ($tok === '' || !hash_equals($_SESSION['play_notif_token'], $tok)) {
        http_response_code(403);
        exit('Forbidden');
    }
    $track = isset($_POST['track']) ? (string) $_POST['track'] : '';
    $track = preg_replace('/[^\p{L}\p{N}._\- ]/u', '', $track);
    if (strlen($track) > 200) $track = substr($track, 0, 200);
    if ($track !== '') avisarTelegram('🎵 Elevando con: ' . $track, $bot_token, $chat_id);
    exit;
}

/* ── Presencia: active IPs (JSON) ── */
$ip_visitante = $_SERVER['HTTP_CF_CONNECTING_IP']
    ?? $_SERVER['HTTP_X_FORWARDED_FOR']
    ?? $_SERVER['REMOTE_ADDR'];
$ip_visitante = trim(explode(',', $ip_visitante)[0]);

$ahora = time();
$ips   = [];
if (file_exists($presencia_file)) {
    $raw = file_get_contents($presencia_file);
    $dec = $raw !== false ? json_decode($raw, true) : null;
    $ips = is_array($dec) ? $dec : [];
}
if (!isset($ips[$ip_visitante])) {
    $geo = 'Red Local';
    if (filter_var($ip_visitante, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        $geo = 'Desconocida';
        $gj  = @file_get_contents('https://ip-api.com/json/' . $ip_visitante);
        if ($gj) {
            $gd = json_decode($gj);
            if ($gd && $gd->status === 'success') $geo = $gd->city . ', ' . $gd->country;
        }
    }
    avisarTelegram("🌿 Nueva conciencia en la fogata...\n🌐 IP: $ip_visitante\n📍 Lugar: $geo", $bot_token, $chat_id);
}
$ips[$ip_visitante] = $ahora;
foreach ($ips as $ip => $t) {
    if ($ahora - $t > 300) unset($ips[$ip]);
}
@file_put_contents($presencia_file, json_encode($ips));
$almas = count($ips);

/* ── Visit counter (total page loads) ── */
$visitas = is_readable($visitas_file) ? max(0, (int) trim((string) file_get_contents($visitas_file))) : 0;
$visitas++;
@file_put_contents($visitas_file, $visitas);

/* ── Chat form (CSRF + rate limiting) ── */
if (isset($_POST['send_msg'])) {
    $csrf_ok = isset($_POST['chat_csrf_token'])
        && hash_equals($_SESSION['chat_csrf_token'], (string) $_POST['chat_csrf_token']);
    if (!$csrf_ok) { header('Location: index.php#chat-box'); exit; }

    $last = $_SESSION['last_chat_time'] ?? 0;
    if (time() - $last < 15) { header('Location: index.php#chat-box'); exit; }

    $nombre = htmlspecialchars(trim($_POST['chat_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $texto  = htmlspecialchars(trim($_POST['chat_msg']  ?? ''), ENT_QUOTES, 'UTF-8');
    if ($nombre !== '' && $texto !== '') {
        $nombre = mb_substr($nombre, 0, 50);
        $texto  = mb_substr($texto, 0, 300);
        $linea  = "<div class='msg'><b>" . $nombre . ":</b> " . $texto . "</div>" . PHP_EOL;
        file_put_contents($chat_file, $linea, FILE_APPEND);
        $_SESSION['last_chat_time'] = time();
        avisarTelegram("💬 $nombre: $texto", $bot_token, $chat_id);
        header('Location: index.php#chat-box');
        exit;
    }
}

/* ── Data ── */
$links = file_exists($yt_file) ? file($yt_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

$admin_url = BASE_URL_ADMIN . '/admin_ritual.php';

$musica_dir = __DIR__ . '/musica/home/';
$canciones  = [];
if (is_dir($musica_dir)) {
    foreach (glob($musica_dir . '*.*') ?: [] as $f) {
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, ['mp3', 'm4a', 'ogg', 'wav'])) {
            $canciones[] = 'musica/home/' . basename($f);
        }
    }
}
sort($canciones);

/* YouTube share URL: prefer config, fall back to env var */
$yt_share_url = $home_cfg['share_yt_url'] !== ''
    ? $home_cfg['share_yt_url']
    : $enlace_youtube_canal;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($home_cfg['site_title']); ?> — <?php echo htmlspecialchars($home_cfg['site_tagline']); ?></title>
    <style>
        :root {
            --primary: #c4a484;
            --bg:      #14110e;
            --panel:   rgba(35, 30, 25, 0.9);
            --text:    #e8e2d5;
            --accent:  #d47a24;
        }

        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: Georgia, 'Times New Roman', serif;
            background: var(--bg);
            background-image: radial-gradient(ellipse at 50% 0%, rgba(35,30,25,0.9) 0%, #14110e 70%);
            color: var(--text);
            margin: 0;
            overflow-x: hidden;
            opacity: 0;
            animation: pageFade 0.9s ease forwards;
        }

        @keyframes pageFade { to { opacity: 1; } }

        @keyframes glowBreathe {
            0%, 100% { text-shadow: 0 0 10px rgba(196,164,132,0.3); opacity: 0.8; }
            50%       { text-shadow: 0 0 28px rgba(212,122,36,0.7); opacity: 1; color: var(--accent); }
        }

        /* ── Medicine navigation ───────────────────────── */
        .medicina-nav {
            display: flex;
            justify-content: center;
            gap: 20px;
            padding: 0 15px;
            margin-bottom: 40px;
        }
        .med-btn {
            flex: 1;
            max-width: 300px;
            text-decoration: none;
            padding: 30px;
            border-radius: 15px;
            text-align: center;
            transition: transform 0.4s, box-shadow 0.4s, border-color 0.4s;
        }
        .med-btn:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.4);
        }
        .ayu-style {
            background: linear-gradient(135deg, #1b3022 0%, #0a1f12 100%);
            border: 1px solid #4e6e5d;
        }
        .ayu-style:hover { border-color: #d4c3a3; }
        .rape-style {
            background: linear-gradient(135deg, #8d6e63 0%, #4a3728 100%);
            border: 1px solid #a1887f;
        }
        .rape-style:hover { border-color: #fdf5e6; }
        .med-title { display: block; font-size: 1.4em; letter-spacing: 4px; margin-bottom: 5px; }
        .ayu-style  .med-title { color: #d4c3a3; }
        .rape-style .med-title { color: #fdf5e6; }
        .med-sub  { font-size: 0.8em; font-style: italic; }
        .ayu-style  .med-sub { color: #4e6e5d; }
        .rape-style .med-sub { color: #a1887f; }

        /* ── Hero ──────────────────────────────────────── */
        .hero-banner {
            height: 20vh;
            min-height: 120px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            gap: 6px;
        }
        .hero-banner h1 {
            font-size: 3em;
            letter-spacing: 15px;
            margin: 0;
            font-weight: 300;
            animation: glowBreathe 6s infinite ease-in-out;
            color: var(--primary);
        }
        .hero-presence {
            font-size: 0.88em;
            color: rgba(196, 164, 132, 0.55);
            letter-spacing: 2px;
        }

        /* ── Main grid ─────────────────────────────────── */
        .main-grid { max-width: 850px; margin: 0 auto; padding: 15px; }

        /* ── Panel cards ───────────────────────────────── */
        .panel {
            background: var(--panel);
            border: 1px solid rgba(196, 164, 132, 0.12);
            border-radius: 25px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }

        /* ── Share bar ─────────────────────────────────── */
        .share-bar { display: flex; gap: 10px; justify-content: center; margin-bottom: 30px; flex-wrap: wrap; }
        .share-btn {
            text-decoration: none;
            color: var(--text);
            font-size: 0.7em;
            padding: 8px 15px;
            border-radius: 20px;
            border: 1px solid rgba(196,164,132,0.2);
            background: rgba(0,0,0,0.2);
            transition: 0.4s;
        }
        .btn-ws:hover { border-color: #25D366; color: #25D366; box-shadow: 0 0 15px rgba(37,211,102,0.2); }
        .btn-tg:hover { border-color: #0088cc; color: #0088cc; }
        .btn-yt:hover { border-color: #ff0000; color: #ff0000; }

        /* ── Audio player ──────────────────────────────── */
        #now-playing {
            text-align: center;
            color: var(--primary);
            font-size: 1.4em;
            min-height: 1.5em;
            margin-bottom: 10px;
            letter-spacing: 2px;
            font-weight: 300;
            transition: 0.5s;
            text-transform: uppercase;
        }
        audio {
            width: 100%;
            height: 35px;
            filter: sepia(100%) saturate(300%) hue-rotate(330deg) brightness(90%);
            opacity: 0.8;
            margin-top: 15px;
        }
        #visualizer {
            width: 100%;
            height: 80px;
            display: block;
            border-radius: 15px;
            background: rgba(0,0,0,0.1);
        }
        .btn {
            background: var(--primary);
            color: var(--bg);
            border: none;
            font-weight: bold;
            padding: 12px;
            border-radius: 25px;
            cursor: pointer;
            width: 100%;
            font-size: 0.9em;
            margin-top: 5px;
            font-family: inherit;
        }
        .track-list {
            list-style: none;
            padding: 10px 0;
            max-height: 200px;
            overflow-y: auto;
            font-size: 0.85em;
            margin-top: 15px;
            border-top: 1px solid rgba(255,255,255,0.05);
        }
        .track-list li {
            padding: 10px;
            border-bottom: 1px solid rgba(255,255,255,0.02);
            cursor: pointer;
            transition: 0.3s;
        }
        .active-track {
            color: var(--accent);
            background: rgba(255,255,255,0.05);
            border-left: 3px solid var(--accent);
            padding-left: 7px;
        }

        /* ── Media grid ────────────────────────────────── */
        .video-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 12px;
            margin-bottom: 40px;
        }
        .video-item img,
        .video-item video { width: 100%; border-radius: 12px; border: 1px solid var(--primary); opacity: 0.8; }

        /* ── Chat ──────────────────────────────────────── */
        #chat-box {
            height: 180px;
            overflow-y: auto;
            font-size: 0.85em;
            border-bottom: 1px solid rgba(196, 164, 132, 0.1);
            margin-bottom: 15px;
            padding: 8px;
            scroll-behavior: smooth;
        }
        .msg {
            margin-bottom: 8px;
            border-left: 2px solid var(--accent);
            padding-left: 8px;
            background: rgba(255,255,255,0.02);
        }
        .chat-form { display: flex; flex-direction: column; gap: 8px; }
        .chat-form input[type="text"] {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(196,164,132,0.15);
            border-radius: 12px;
            padding: 10px 14px;
            color: var(--text);
            font-family: inherit;
            font-size: 0.9em;
        }
        .chat-form input[type="text"]:focus {
            outline: none;
            border-color: rgba(196,164,132,0.35);
        }

        /* ── Admin gate ────────────────────────────────── */
        .admin-gate { text-align: center; margin-top: 16px; padding-bottom: 16px; }
        .admin-gate-link {
            display: inline-block;
            width: 14px; height: 14px;
            padding: 18px; margin: -18px;
            box-sizing: content-box;
            border-radius: 50%;
            border: 1px solid rgba(196, 164, 132, 0.45);
            background: var(--primary);
            background-clip: content-box;
            opacity: 0.55;
            transition: opacity 0.2s, transform 0.2s, box-shadow 0.2s;
            text-decoration: none;
        }
        .admin-gate-link:hover {
            opacity: 0.95;
            transform: scale(1.06);
            box-shadow: 0 0 14px rgba(212, 122, 36, 0.35);
        }

        /* ── Footer with visit counter ─────────────────── */
        .home-footer {
            text-align: center;
            padding: 60px 24px 48px;
            border-top: 1px solid rgba(196, 164, 132, 0.06);
            margin-top: 20px;
        }
        .footer-visits {
            display: inline-flex;
            align-items: baseline;
            gap: 10px;
            margin-bottom: 18px;
        }
        .footer-visits-num {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 3.2rem;
            font-weight: 300;
            color: var(--primary);
            letter-spacing: 3px;
            line-height: 1;
            text-shadow: 0 0 30px rgba(196, 164, 132, 0.25);
        }
        .footer-visits-lbl {
            font-size: 0.70em;
            letter-spacing: 4px;
            text-transform: uppercase;
            color: rgba(196, 164, 132, 0.38);
        }
        .footer-copy {
            display: block;
            font-size: 0.68em;
            color: rgba(196, 164, 132, 0.22);
            letter-spacing: 2px;
            margin-top: 4px;
        }

        @media (max-width: 600px) {
            .medicina-nav { flex-direction: column; align-items: center; }
            .med-btn { width: 90%; max-width: 100%; }
        }
    </style>
</head>
<body>

    <!-- ── MEDICINE NAV ── -->
    <div class="medicina-nav">
        <a href="ayahuasca.php" class="med-btn ayu-style">
            <span class="med-title"><?php echo htmlspecialchars($home_cfg['med_ayu_title']); ?></span>
            <span class="med-sub"><?php echo htmlspecialchars($home_cfg['med_ayu_sub']); ?></span>
        </a>
        <a href="rape.php" class="med-btn rape-style">
            <span class="med-title"><?php echo htmlspecialchars($home_cfg['med_rape_title']); ?></span>
            <span class="med-sub"><?php echo htmlspecialchars($home_cfg['med_rape_sub']); ?></span>
        </a>
    </div>

    <!-- ── HERO ── -->
    <div class="hero-banner">
        <h1><?php echo htmlspecialchars($home_cfg['site_title']); ?></h1>
        <span class="hero-presence">✨ <?php echo $almas; ?> <?php echo htmlspecialchars($home_cfg['chat_ph_msg'] !== '' ? 'conciencias en sintonía' : 'conciencias en sintonía'); ?></span>
    </div>

    <div class="main-grid">

        <!-- ── SHARE BAR ── -->
        <div class="share-bar">
            <?php if ($home_cfg['share_ws_url'] !== ''): ?>
            <a href="<?php echo htmlspecialchars($home_cfg['share_ws_url']); ?>" class="share-btn btn-ws" target="_blank" rel="noopener noreferrer">WhatsApp</a>
            <?php else: ?>
            <a href="#" class="share-btn btn-ws">WhatsApp</a>
            <?php endif; ?>

            <?php if ($home_cfg['share_tg_url'] !== ''): ?>
            <a href="<?php echo htmlspecialchars($home_cfg['share_tg_url']); ?>" class="share-btn btn-tg" target="_blank" rel="noopener noreferrer">Telegram</a>
            <?php else: ?>
            <a href="#" class="share-btn btn-tg">Telegram</a>
            <?php endif; ?>

            <a href="<?php echo htmlspecialchars($yt_share_url); ?>" class="share-btn btn-yt" target="_blank" rel="noopener noreferrer">YouTube</a>
        </div>

        <!-- ── AUDIO PLAYER ── -->
        <div class="panel">
            <div id="now-playing"><?php echo htmlspecialchars($home_cfg['player_idle']); ?></div>
            <canvas id="visualizer"></canvas>
            <audio id="mplayer" controls crossorigin="anonymous"></audio>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px;">
                <button type="button" class="btn" id="btn-sintonia-continua"><?php echo htmlspecialchars($home_cfg['btn_continuous']); ?></button>
                <button type="button" class="btn" style="background: var(--accent)" id="btn-azar-sagrado"><?php echo htmlspecialchars($home_cfg['btn_random']); ?></button>
            </div>
            <ul class="track-list">
                <?php foreach ($canciones as $idx => $can): ?>
                    <li id="t-<?php echo $idx; ?>" data-name="<?php echo htmlspecialchars(basename($can), ENT_QUOTES, 'UTF-8'); ?>">
                        ✧ <?php echo str_replace(['_', '.mp3', '.wav', '.ogg', '.m4a', 'MP3'], ' ', basename($can)); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- ── MEDIA GRID (YouTube + fotos/videos locales) ── -->
        <div class="video-grid">
            <?php
            foreach ($links as $id) {
                $vid = htmlspecialchars(trim($id), ENT_QUOTES, 'UTF-8');
                echo '<div class="video-item"><a href="https://youtube.com/watch?v=' . $vid . '" target="_blank" rel="noopener">'
                   . '<img src="https://img.youtube.com/vi/' . $vid . '/hqdefault.jpg" loading="lazy" alt="Video"></a></div>';
            }
            $fotos  = glob('fotos/home/*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE) ?: [];
            $videos = glob('videos/home/*.{mp4,webm,MP4}', GLOB_BRACE) ?: [];
            foreach (array_merge($fotos, $videos) as $file) {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                $src = htmlspecialchars($file, ENT_QUOTES, 'UTF-8');
                if (in_array($ext, ['mp4', 'webm'])) {
                    echo "<div class='video-item'><video src='$src' controls playsinline preload='metadata'></video></div>";
                } else {
                    echo "<div class='video-item'><a href='$src' target='_blank'><img src='$src' loading='lazy' alt=''></a></div>";
                }
            }
            ?>
        </div>

        <!-- ── CHAT ── -->
        <div class="panel" id="seccion-chat">
            <div id="chat-box">
                <?php
                if (file_exists($chat_file)) {
                    $lineas = file($chat_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    echo implode("\n", array_slice($lineas, -50));
                }
                ?>
            </div>
            <form action="index.php" method="post" class="chat-form">
                <input type="hidden" name="chat_csrf_token" value="<?php echo $_SESSION['chat_csrf_token']; ?>">
                <input type="text" name="chat_name"
                       placeholder="<?php echo htmlspecialchars($home_cfg['chat_ph_name']); ?>"
                       maxlength="50" required>
                <input type="text" name="chat_msg"
                       placeholder="<?php echo htmlspecialchars($home_cfg['chat_ph_msg']); ?>"
                       maxlength="300" required>
                <input type="submit" name="send_msg"
                       value="<?php echo htmlspecialchars($home_cfg['chat_btn']); ?>"
                       class="btn">
            </form>
        </div>

        <!-- ── ADMIN GATE (invisible dot) ── -->
        <p class="admin-gate">
            <a href="<?php echo htmlspecialchars($admin_url); ?>" class="admin-gate-link"></a>
        </p>

    </div>

    <!-- ── FOOTER WITH VISIT COUNTER ── -->
    <footer class="home-footer">
        <div class="footer-visits">
            <span class="footer-visits-num"><?php echo number_format($visitas, 0, ',', '.'); ?></span>
            <span class="footer-visits-lbl">visitas al portal</span>
        </div>
        <span class="footer-copy">&copy; <?php echo date('Y'); ?> &mdash; <?php echo htmlspecialchars($home_cfg['site_title']); ?></span>
    </footer>

    <script type="application/json" id="sebaji-lista-json"><?php echo json_encode($canciones, JSON_UNESCAPED_SLASHES); ?></script>
    <script type="application/json" id="sebaji-token-json"><?php echo json_encode($_SESSION['play_notif_token']); ?></script>
    <script src="js/index-player.js" defer></script>
</body>
</html>

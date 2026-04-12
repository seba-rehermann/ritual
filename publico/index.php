<?php
session_start();
require_once __DIR__ . '/config.php';

$yt_file       = 'data/youtube_links.txt';
$chat_file     = 'data/mensajes.txt';
$presencia_file = 'data/presencia.txt';

// Tokens de sesión: notificaciones de reproducción y CSRF del chat
if (empty($_SESSION['play_notif_token'])) {
    $_SESSION['play_notif_token'] = bin2hex(random_bytes(16));
}
if (empty($_SESSION['chat_csrf_token'])) {
    $_SESSION['chat_csrf_token'] = bin2hex(random_bytes(16));
}

// --- Notificación de reproducción (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['play_notif'])) {
    header('Content-Type: text/plain; charset=utf-8');
    $tok = isset($_POST['token']) ? (string) $_POST['token'] : '';
    if ($tok === '' || !hash_equals($_SESSION['play_notif_token'], $tok)) {
        http_response_code(403);
        exit('Forbidden');
    }
    $track = isset($_POST['track']) ? (string) $_POST['track'] : '';
    $track = preg_replace('/[^\p{L}\p{N}._\- ]/u', '', $track);
    if (strlen($track) > 200) {
        $track = substr($track, 0, 200);
    }
    if ($track !== '') {
        avisarTelegram('🎵 Elevando con: ' . $track, $bot_token, $chat_id);
    }
    exit;
}

// --- Presencia: tracking de IPs activas (JSON, no serialize) ---
$ip_visitante = $_SERVER['HTTP_CF_CONNECTING_IP']
    ?? $_SERVER['HTTP_X_FORWARDED_FOR']
    ?? $_SERVER['REMOTE_ADDR'];
$ip_visitante = trim(explode(',', $ip_visitante)[0]);

$ahora = time();
$ips = [];
if (file_exists($presencia_file)) {
    $raw_presencia = file_get_contents($presencia_file);
    $decoded = $raw_presencia !== false ? json_decode($raw_presencia, true) : null;
    $ips = is_array($decoded) ? $decoded : [];
}

if (!isset($ips[$ip_visitante])) {
    // Solo notifica geolocalización para IPs públicas
    $geo_info = 'Red Local';
    if (filter_var($ip_visitante, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        $geo_info = 'Desconocida';
        $geo_json = @file_get_contents('https://ip-api.com/json/' . $ip_visitante);
        if ($geo_json) {
            $geo_data = json_decode($geo_json);
            if ($geo_data && $geo_data->status === 'success') {
                $geo_info = $geo_data->city . ', ' . $geo_data->country;
            }
        }
    }
    avisarTelegram(
        "🌿 Nueva conciencia en la fogata...\n🌐 IP: $ip_visitante\n📍 Lugar: $geo_info",
        $bot_token,
        $chat_id
    );
}
$ips[$ip_visitante] = $ahora;

// Limpiar IPs inactivas (> 5 minutos)
foreach ($ips as $ip => $t) {
    if ($ahora - $t > 300) {
        unset($ips[$ip]);
    }
}
@file_put_contents($presencia_file, json_encode($ips));
$almas = count($ips);

// --- Formulario de chat (con CSRF y rate limiting) ---
if (isset($_POST['send_msg'])) {
    $csrf_ok = isset($_POST['chat_csrf_token'])
        && hash_equals($_SESSION['chat_csrf_token'], (string) $_POST['chat_csrf_token']);

    if (!$csrf_ok) {
        // Token inválido: ignorar silenciosamente
        header('Location: index.php#chat-box');
        exit;
    }

    // Rate limiting: máximo 1 mensaje cada 15 segundos por sesión
    $last_msg_time = $_SESSION['last_chat_time'] ?? 0;
    if (time() - $last_msg_time < 15) {
        header('Location: index.php#chat-box');
        exit;
    }

    $nombre = htmlspecialchars(trim($_POST['chat_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $texto  = htmlspecialchars(trim($_POST['chat_msg']  ?? ''), ENT_QUOTES, 'UTF-8');

    if ($nombre !== '' && $texto !== '') {
        // Limitar longitud de nombre y mensaje
        $nombre = mb_substr($nombre, 0, 50);
        $texto  = mb_substr($texto, 0, 300);

        $linea = "<div class='msg'><b>" . $nombre . ":</b> " . $texto . "</div>" . PHP_EOL;
        file_put_contents($chat_file, $linea, FILE_APPEND);
        $_SESSION['last_chat_time'] = time();
        avisarTelegram("💬 $nombre: $texto", $bot_token, $chat_id);
        header('Location: index.php#chat-box');
        exit;
    }
}

$links = file_exists($yt_file) ? file($yt_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

// Construir URL al panel admin (mismo host, puerto 8082)
$admin_host = explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0];
$admin_url  = 'http://' . $admin_host . ':8082/admin_ritual.php';

// Lista de canciones
$musica_dir = __DIR__ . '/musica/home/';
$canciones = [];
if (is_dir($musica_dir)) {
    $files = glob($musica_dir . '*.*') ?: [];
    foreach ($files as $f) {
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, ['mp3', 'm4a', 'ogg', 'wav'])) {
            $canciones[] = 'musica/home/' . basename($f);
        }
    }
}
sort($canciones);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sebaji - El Despertar</title>
    <style>
        :root { --primary: #c4a484; --bg: #14110e; --panel: rgba(35, 30, 25, 0.9); --text: #e8e2d5; --accent: #d47a24; }
        body { font-family: 'Georgia', serif; background: var(--bg); color: var(--text); margin: 0; background-image: radial-gradient(circle at center, #231e19 0%, #14110e 100%); padding-bottom: 80px; overflow-x: hidden;}
        @keyframes glowBreathe { 0%, 100% { text-shadow: 0 0 10px rgba(196,164,132,0.3); opacity:0.8; } 50% { text-shadow: 0 0 25px rgba(212,122,36,0.8); opacity:1; color: var(--accent); } }
        .hero-banner { height: 20vh; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; }
        .hero-banner h1 { font-size: 3em; letter-spacing: 15px; margin: 0; font-weight: 300; animation: glowBreathe 6s infinite ease-in-out; color: var(--primary); }
        .main-grid { max-width: 850px; margin: 0 auto; padding: 15px; }
        .panel { background: var(--panel); border: 1px solid rgba(196, 164, 132, 0.15); border-radius: 25px; padding: 20px; margin-bottom: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); position: relative; }

        #now-playing {
            text-align: center; color: var(--primary); font-size: 1.4em; min-height: 1.5em; margin-bottom: 10px;
            letter-spacing: 2px; font-weight: 300; transition: 0.5s; text-transform: uppercase;
        }

        #chat-box { height: 180px; overflow-y: auto; font-size: 0.85em; border-bottom: 1px solid rgba(196, 164, 132, 0.1); margin-bottom: 15px; padding: 8px; scroll-behavior: smooth; }
        .msg { margin-bottom: 8px; border-left: 2px solid var(--accent); padding-left: 8px; background: rgba(255,255,255,0.02); }

        audio { width: 100%; height: 35px; filter: sepia(100%) saturate(300%) hue-rotate(330deg) brightness(90%); opacity: 0.8; margin-top: 15px; }
        #visualizer { width: 100%; height: 80px; display: block; margin: 0 auto; border-radius: 15px; background: rgba(0,0,0,0.1); }

        .share-bar { display: flex; gap: 10px; justify-content: center; margin-bottom: 30px; flex-wrap: wrap; }
        .share-btn { text-decoration: none; color: var(--text); font-size: 0.7em; padding: 8px 15px; border-radius: 20px; border: 1px solid rgba(196,164,132,0.2); background: rgba(0,0,0,0.2); transition: 0.4s; }
        .btn-ws:hover { border-color: #25D366; color: #25D366; box-shadow: 0 0 15px rgba(37,211,102,0.2); }
        .btn-tg:hover { border-color: #0088cc; color: #0088cc; }
        .btn-yt:hover { border-color: #ff0000; color: #ff0000; }

        .btn { background: var(--primary); color: var(--bg); border: none; font-weight: bold; padding: 12px; border-radius: 25px; cursor: pointer; width: 100%; font-size: 0.9em; margin-top: 5px;}

        .track-list { list-style: none; padding: 10px 0; max-height: 200px; overflow-y: auto; font-size: 0.85em; margin-top: 15px; border-top: 1px solid rgba(255,255,255,0.05); }
        .track-list li { padding: 10px; border-bottom: 1px solid rgba(255,255,255,0.02); cursor: pointer; transition: 0.3s; }
        .active-track { color: var(--accent); background: rgba(255,255,255,0.05); border-left: 3px solid var(--accent); padding-left: 7px; }

        .video-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; margin-bottom: 40px;}
        .video-item img, .video-item video { width: 100%; border-radius: 12px; border: 1px solid var(--primary); opacity: 0.8; }
        .med-btn:hover { transform: translateY(-10px); box-shadow: 0 15px 40px rgba(0,0,0,0.4); border-color: var(--primary) !important; }
        .admin-gate { text-align: center; margin-top: 40px; padding-bottom: 16px; }
        .admin-gate-link {
            display: inline-block; width: 14px; height: 14px; padding: 18px; margin: -18px;
            box-sizing: content-box; border-radius: 50%; border: 1px solid rgba(196, 164, 132, 0.45);
            background: var(--primary); background-clip: content-box; opacity: 0.55;
            transition: opacity 0.2s, transform 0.2s, box-shadow 0.2s; text-decoration: none;
        }
        .admin-gate-link:hover { opacity: 0.95; transform: scale(1.06); box-shadow: 0 0 14px rgba(212, 122, 36, 0.35); }
        @media (max-width: 600px) { .medicina-nav { flex-direction: column; align-items: center; } .med-btn { width: 90%; } }
    </style>
</head>
<body>
    <div class="medicina-nav" style="display: flex; justify-content: center; gap: 20px; margin-bottom: 40px; padding: 0 15px;">
        <a href="ayahuasca.php" class="med-btn ayu-style" style="flex: 1; max-width: 300px; text-decoration: none; padding: 30px; border-radius: 15px; text-align: center; background: linear-gradient(135deg, #1b3022 0%, #0a1f12 100%); border: 1px solid #4e6e5d; transition: 0.4s;">
            <span style="display: block; color: #d4c3a3; font-size: 1.4em; letter-spacing: 4px; margin-bottom: 5px;">AYAHUASCA</span>
            <span style="color: #4e6e5d; font-size: 0.8em; font-style: italic;">La Soga del Alma</span>
        </a>
        <a href="rape.php" class="med-btn rape-style" style="flex: 1; max-width: 300px; text-decoration: none; padding: 30px; border-radius: 15px; text-align: center; background: linear-gradient(135deg, #8d6e63 0%, #4a3728 100%); border: 1px solid #a1887f; transition: 0.4s;">
            <span style="display: block; color: #fdf5e6; font-size: 1.4em; letter-spacing: 4px; margin-bottom: 5px;">RAPÉ</span>
            <span style="color: #a1887f; font-size: 0.8em; font-style: italic;">El Soplo Sagrado</span>
        </a>
    </div>

    <div class="hero-banner"><h1>SEBAJI</h1><span>✨ <?php echo $almas; ?> conciencias en sintonía</span></div>

    <div class="main-grid">
        <div class="share-bar">
            <a href="#" class="share-btn btn-ws">WhatsApp</a>
            <a href="#" class="share-btn btn-tg">Telegram</a>
            <a href="<?php echo htmlspecialchars($enlace_youtube_canal); ?>" class="share-btn btn-yt" target="_blank" rel="noopener noreferrer">YouTube</a>
        </div>

        <div class="panel">
            <div id="now-playing">◈ SELECCIONA UNA OFRENDA ◈</div>
            <canvas id="visualizer"></canvas>
            <audio id="mplayer" controls crossorigin="anonymous"></audio>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px;">
                <button type="button" class="btn" id="btn-sintonia-continua">Sintonía Continua</button>
                <button type="button" class="btn" style="background: var(--accent)" id="btn-azar-sagrado">Azar Sagrado</button>
            </div>
            <ul class="track-list">
                <?php foreach ($canciones as $idx => $can): ?>
                    <li id="t-<?php echo $idx; ?>" data-name="<?php echo htmlspecialchars(basename($can), ENT_QUOTES, 'UTF-8'); ?>">
                        ✧ <?php echo str_replace(['_', '.mp3', '.wav', '.ogg', '.m4a', 'MP3'], ' ', basename($can)); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

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

        <div class="panel" id="seccion-chat">
            <div id="chat-box">
                <?php
                if (file_exists($chat_file)) {
                    $lineas = file($chat_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    echo implode("\n", array_slice($lineas, -50));
                }
                ?>
            </div>
            <form action="index.php" method="post" style="display: flex; flex-direction: column; gap: 8px;">
                <input type="hidden" name="chat_csrf_token" value="<?php echo $_SESSION['chat_csrf_token']; ?>">
                <input type="text" name="chat_name" placeholder="Tu nombre" maxlength="50" required>
                <input type="text" name="chat_msg" placeholder="Comparte tu sentir..." maxlength="300" required>
                <input type="submit" name="send_msg" value="Elevar Pensamiento" class="btn">
            </form>
        </div>

        <p class="admin-gate">
            <a href="<?php echo htmlspecialchars($admin_url); ?>" class="admin-gate-link"></a>
        </p>
    </div>

    <script type="application/json" id="sebaji-lista-json"><?php echo json_encode($canciones, JSON_UNESCAPED_SLASHES); ?></script>
    <script type="application/json" id="sebaji-token-json"><?php echo json_encode($_SESSION['play_notif_token']); ?></script>
    <script src="js/index-player.js" defer></script>
</body>
</html>

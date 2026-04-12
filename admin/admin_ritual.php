<?php
session_start();
require_once __DIR__ . '/config.php';

/* ── LOGIN ───────────────────────────────────────────────────────── */
if (isset($_POST['login'])) {
    if (($_POST['pass'] ?? '') === $password_admin) {
        $_SESSION['admin_logged'] = true;
        header("Location: admin_ritual.php");
        exit;
    }
    $error_login = "Contraseña incorrecta";
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin_ritual.php");
    exit;
}

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true): ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ritual Admin</title>
    <link rel="stylesheet" href="admin-style.css">
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <div class="login-logo">
            <div class="login-logo-dot"></div>
            RITUAL ADMIN
            <div class="login-logo-dot"></div>
        </div>
        <h2>Acceso al Panel</h2>
        <?php if (isset($error_login)): ?>
            <div class="login-error"><?php echo htmlspecialchars($error_login); ?></div>
        <?php endif; ?>
        <form method="post">
            <div class="form-group">
                <label for="pass">Contraseña</label>
                <input type="password" id="pass" name="pass" placeholder="••••••••" required autofocus>
            </div>
            <button type="submit" name="login" class="btn btn-primary" style="margin-top:10px;">
                Entrar
            </button>
        </form>
    </div>
</div>
</body>
</html>
<?php exit; endif; ?>

<?php
/* ── LÓGICA DE ACCIONES (ya autenticado) ─────────────────────────── */
require_once __DIR__ . '/ritual_upload.php';
require_once __DIR__ . '/home_config_lib.php';

$yt_file = 'data/youtube_links.txt';
$res     = null;
$upload_error = null;

// Subir archivo multimedia o añadir link YouTube
if (isset($_POST['submit_any'])) {
    $hadFile = isset($_FILES['fileToUpload']) && (int) $_FILES['fileToUpload']['error'] !== UPLOAD_ERR_NO_FILE;
    if ($hadFile) {
        $up = ritual_process_upload($_FILES['fileToUpload']);
        if (!$up['ok']) {
            $upload_error = $up['error'];
        } else {
            $res = '✅ Archivo subido con éxito';
        }
    }
    if (!empty($_POST['yt_url'])) {
        $url = trim($_POST['yt_url']);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $vars);
        $v_id = $vars['v'] ?? substr((string) parse_url($url, PHP_URL_PATH), 1);
        $v_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $v_id);
        if ($v_id !== '') {
            file_put_contents($yt_file, $v_id . PHP_EOL, FILE_APPEND);
            $res = ($res ? $res . ' y ' : '✅ ') . 'Video de YouTube añadido';
        }
    }
}

// Eliminar video de YouTube
if (isset($_POST['delete_yt'])) {
    $del_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($_POST['del_id'] ?? ''));
    if ($del_id !== '' && file_exists($yt_file)) {
        $lines  = file($yt_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines  = array_values(array_filter($lines, fn($l) => trim($l) !== $del_id));
        file_put_contents($yt_file, $lines ? implode(PHP_EOL, $lines) . PHP_EOL : '');
        $res = '🗑️ Video eliminado de la lista';
    }
}

// Guardar configuración del portal (textos o links — partial save)
if (isset($_POST['save_home_config'])) {
    home_config_update_from_post();
    $res = '✅ Configuración del portal guardada.';
}

// Ajustar contador de visitas
if (isset($_POST['save_visitas'])) {
    visitas_write((int) ($_POST['visitas_new'] ?? 0));
    $res = '✅ Contador de visitas actualizado.';
}

/* ── STATS ───────────────────────────────────────────────────────── */
$yt_ids    = file_exists($yt_file) ? file($yt_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
$n_songs   = count(glob('musica/home/*.{mp3,m4a,ogg,wav}', GLOB_BRACE) ?: []);
$n_fotos   = count(glob('fotos/home/*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE) ?: []);
$n_videos  = count($yt_ids);
$n_visitas = visitas_read();
$home_cfg  = home_config_load();

$pub_host = explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0];
$pub_url  = 'http://' . $pub_host . ':8081';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Ritual</title>
    <link rel="stylesheet" href="admin-style.css">
</head>
<body>

<!-- ── TOPBAR ── -->
<header class="topbar">
    <div class="topbar-brand">
        <div class="dot"></div>
        RITUAL ADMIN
    </div>
    <div class="topbar-right">
        <a href="<?php echo htmlspecialchars($pub_url); ?>" target="_blank" class="btn-pub">
            ↗ Ver sitio público
        </a>
        <a href="?logout=1" class="btn-logout">Salir</a>
    </div>
</header>

<main class="dashboard">

    <!-- ── ALERTAS ── -->
    <?php if ($res): ?>
        <div class="alert alert-success">✓ <?php echo htmlspecialchars($res); ?></div>
    <?php endif; ?>
    <?php if ($upload_error): ?>
        <div class="alert alert-error">✕ <?php echo htmlspecialchars($upload_error); ?></div>
    <?php endif; ?>

    <!-- ── STATS STRIP ── -->
    <div class="stats-strip">
        <div class="stat-card">
            <div class="stat-label">Canciones</div>
            <div class="stat-value"><?php echo $n_songs; ?></div>
            <div class="stat-sub">en musica/home</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Fotos</div>
            <div class="stat-value"><?php echo $n_fotos; ?></div>
            <div class="stat-sub">en fotos/home</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Videos YT</div>
            <div class="stat-value"><?php echo $n_videos; ?></div>
            <div class="stat-sub">en la lista</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Medicinas</div>
            <div class="stat-value">2</div>
            <div class="stat-sub">rapé · ayahuasca</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Visitas</div>
            <div class="stat-value"><?php echo number_format($n_visitas, 0, ',', '.'); ?></div>
            <div class="stat-sub">total al portal</div>
        </div>
    </div>

    <!-- ── SECCIÓN: CONTENIDO ── -->
    <div class="section-title">Gestión de contenido</div>
    <div class="grid-2">

        <!-- Subir multimedia -->
        <div class="card">
            <div class="card-title">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                Subir multimedia
            </div>
            <form action="" method="post" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Archivo (foto, audio o video)</label>
                    <div class="file-drop" id="fileDrop">
                        <input type="file" name="fileToUpload" id="fileInput">
                        <span class="file-drop-icon">📂</span>
                        <span class="file-drop-text">Arrastrá o hacé click para seleccionar</span>
                        <div class="file-drop-name" id="fileName"></div>
                    </div>
                </div>
                <div class="form-group">
                    <label>O pegá un link de YouTube</label>
                    <input type="url" name="yt_url" placeholder="https://www.youtube.com/watch?v=...">
                </div>
                <button type="submit" name="submit_any" class="btn btn-primary">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/><path d="M5 19h14"/></svg>
                    Subir al portal
                </button>
            </form>
        </div>

        <!-- Editar páginas -->
        <div class="card">
            <div class="card-title">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                Editar páginas
            </div>
            <p style="font-size:0.83em; color:var(--text-dim); margin: 0 0 16px;">
                Modificá textos de introducción, bloque de CTA y galerías de cada medicina.
            </p>
            <a href="editar_medicina.php?slug=ayahuasca" class="btn btn-ayu">
                🍃 Editar Ayahuasca
            </a>
            <a href="editar_medicina.php?slug=rape" class="btn btn-rape">
                🌿 Editar Rapé
            </a>
        </div>
    </div>

    <!-- ── SECCIÓN: HOME — PORTAL ── -->
    <div class="section-title">Home — Portal público</div>
    <div class="grid-2">

        <!-- Card: Textos e identidad -->
        <div class="card">
            <div class="card-title">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                Textos e identidad
            </div>
            <form method="post">
                <input type="hidden" name="save_home_config" value="1">

                <div class="form-sub-title">Identidad del sitio</div>
                <div class="form-group">
                    <label>Título principal (H1 del hero)</label>
                    <input type="text" name="hcfg_site_title" value="<?php echo htmlspecialchars($home_cfg['site_title']); ?>" maxlength="80">
                </div>
                <div class="form-group">
                    <label>Tagline (subtítulo en &lt;title&gt;)</label>
                    <input type="text" name="hcfg_site_tagline" value="<?php echo htmlspecialchars($home_cfg['site_tagline']); ?>" maxlength="80">
                </div>

                <div class="form-sub-title">Reproductor & botones</div>
                <div class="form-group">
                    <label>Texto en espera del reproductor</label>
                    <input type="text" name="hcfg_player_idle" value="<?php echo htmlspecialchars($home_cfg['player_idle']); ?>" maxlength="100">
                </div>
                <div class="form-group">
                    <label>Botón izquierdo (modo continuo)</label>
                    <input type="text" name="hcfg_btn_continuous" value="<?php echo htmlspecialchars($home_cfg['btn_continuous']); ?>" maxlength="60">
                </div>
                <div class="form-group">
                    <label>Botón derecho (azar)</label>
                    <input type="text" name="hcfg_btn_random" value="<?php echo htmlspecialchars($home_cfg['btn_random']); ?>" maxlength="60">
                </div>

                <div class="form-sub-title">Chat</div>
                <div class="form-group">
                    <label>Placeholder — nombre</label>
                    <input type="text" name="hcfg_chat_ph_name" value="<?php echo htmlspecialchars($home_cfg['chat_ph_name']); ?>" maxlength="60">
                </div>
                <div class="form-group">
                    <label>Placeholder — mensaje</label>
                    <input type="text" name="hcfg_chat_ph_msg" value="<?php echo htmlspecialchars($home_cfg['chat_ph_msg']); ?>" maxlength="100">
                </div>
                <div class="form-group">
                    <label>Texto del botón de enviar</label>
                    <input type="text" name="hcfg_chat_btn" value="<?php echo htmlspecialchars($home_cfg['chat_btn']); ?>" maxlength="60">
                </div>

                <div class="form-sub-title">Cards de medicinas</div>
                <div class="form-group">
                    <label>Ayahuasca — título</label>
                    <input type="text" name="hcfg_med_ayu_title" value="<?php echo htmlspecialchars($home_cfg['med_ayu_title']); ?>" maxlength="60">
                </div>
                <div class="form-group">
                    <label>Ayahuasca — subtítulo</label>
                    <input type="text" name="hcfg_med_ayu_sub" value="<?php echo htmlspecialchars($home_cfg['med_ayu_sub']); ?>" maxlength="100">
                </div>
                <div class="form-group">
                    <label>Rapé — título</label>
                    <input type="text" name="hcfg_med_rape_title" value="<?php echo htmlspecialchars($home_cfg['med_rape_title']); ?>" maxlength="60">
                </div>
                <div class="form-group">
                    <label>Rapé — subtítulo</label>
                    <input type="text" name="hcfg_med_rape_sub" value="<?php echo htmlspecialchars($home_cfg['med_rape_sub']); ?>" maxlength="100">
                </div>

                <button type="submit" class="btn btn-primary" style="margin-top:10px;">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                    Guardar textos
                </button>
            </form>
        </div>

        <!-- Card: Redes Sociales + Contador de Visitas -->
        <div class="card">
            <div class="card-title">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z"/></svg>
                Redes Sociales
            </div>
            <form method="post">
                <input type="hidden" name="save_home_config" value="1">
                <div class="form-group">
                    <label>WhatsApp (URL completa — wa.me/...)</label>
                    <input type="url" name="hcfg_share_ws_url"
                           value="<?php echo htmlspecialchars($home_cfg['share_ws_url']); ?>"
                           placeholder="https://wa.me/549...">
                </div>
                <div class="form-group">
                    <label>Telegram (t.me/... o enlace de invitación)</label>
                    <input type="url" name="hcfg_share_tg_url"
                           value="<?php echo htmlspecialchars($home_cfg['share_tg_url']); ?>"
                           placeholder="https://t.me/sebaji">
                </div>
                <div class="form-group">
                    <label>YouTube (canal o playlist)</label>
                    <input type="url" name="hcfg_share_yt_url"
                           value="<?php echo htmlspecialchars($home_cfg['share_yt_url']); ?>"
                           placeholder="https://youtube.com/@sebaji">
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top:10px;">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                    Guardar links
                </button>
            </form>

            <hr class="divider">

            <!-- Contador de visitas -->
            <div class="card-title" style="margin-top:4px;">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                Contador de visitas
            </div>
            <div class="visits-display">
                <span class="visits-count"><?php echo number_format($n_visitas, 0, ',', '.'); ?></span>
                <span class="visits-label">visitas registradas</span>
            </div>
            <form method="post">
                <input type="hidden" name="save_visitas" value="1">
                <div class="form-group">
                    <label>Ajustar manualmente</label>
                    <input type="number" name="visitas_new"
                           value="<?php echo $n_visitas; ?>" min="0" step="1">
                </div>
                <button type="submit" class="btn" style="background:var(--amber); color:#0a0a0a; width:100%; margin-top:6px; font-weight:700;">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                    Actualizar contador
                </button>
            </form>
        </div>

    </div>

    <!-- ── SECCIÓN: BIBLIOTECA YOUTUBE ── -->
    <div class="section-title">Biblioteca de YouTube</div>
    <div class="card">
        <div class="card-title">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M19.6 3H4.4A1.4 1.4 0 003 4.4v15.2A1.4 1.4 0 004.4 21h15.2a1.4 1.4 0 001.4-1.4V4.4A1.4 1.4 0 0019.6 3zm-8.1 12.5V8.5l5.6 3.5-5.6 3.5z"/></svg>
            Videos en la lista pública
        </div>

        <div class="yt-search-wrap">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
            <input type="text" class="yt-search" id="ytSearch" placeholder="Buscar por ID de video...">
        </div>

        <div class="yt-counter">
            Mostrando <span id="ytVisible"><?php echo count($yt_ids); ?></span>
            de <?php echo count($yt_ids); ?> videos
        </div>

        <div class="yt-grid" id="ytGrid">
            <?php if (empty($yt_ids)): ?>
                <div class="yt-empty">No hay videos en la lista aún.</div>
            <?php else: ?>
                <?php foreach ($yt_ids as $vid):
                    $vid = trim($vid);
                    if ($vid === '') continue;
                    $safe = htmlspecialchars($vid, ENT_QUOTES, 'UTF-8');
                ?>
                <div class="yt-item" data-id="<?php echo $safe; ?>">
                    <img src="https://img.youtube.com/vi/<?php echo $safe; ?>/mqdefault.jpg"
                         loading="lazy" alt="<?php echo $safe; ?>">
                    <div class="yt-item-overlay">
                        <div class="yt-item-id"><?php echo $safe; ?></div>
                        <div class="yt-item-actions">
                            <a href="https://youtube.com/watch?v=<?php echo $safe; ?>"
                               target="_blank" rel="noopener">▶ Ver</a>
                            <form method="post" style="flex:1; display:contents;"
                                  onsubmit="return confirm('¿Eliminar este video de la lista?')">
                                <input type="hidden" name="del_id" value="<?php echo $safe; ?>">
                                <button type="submit" name="delete_yt"
                                        class="del-btn" style="flex:1; text-align:center; font-size:0.7em; padding:4px 6px; border-radius:5px; background:rgba(0,0,0,0.6); color:var(--red); border:none; cursor:pointer; font-family:inherit;">
                                    🗑 Quitar
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</main>

<script>
/* ── FILE DROP ── */
(function () {
    var drop  = document.getElementById('fileDrop');
    var input = document.getElementById('fileInput');
    var name  = document.getElementById('fileName');
    if (!drop || !input) return;

    input.addEventListener('change', function () {
        name.textContent = input.files[0] ? input.files[0].name : '';
    });
    drop.addEventListener('dragover', function (e) {
        e.preventDefault();
        drop.classList.add('drag-over');
    });
    drop.addEventListener('dragleave', function () {
        drop.classList.remove('drag-over');
    });
    drop.addEventListener('drop', function (e) {
        e.preventDefault();
        drop.classList.remove('drag-over');
        if (e.dataTransfer.files.length) {
            input.files = e.dataTransfer.files;
            name.textContent = e.dataTransfer.files[0].name;
        }
    });
})();

/* ── YOUTUBE SEARCH / FILTER ── */
(function () {
    var searchInput = document.getElementById('ytSearch');
    var grid        = document.getElementById('ytGrid');
    var counter     = document.getElementById('ytVisible');
    if (!searchInput || !grid || !counter) return;

    searchInput.addEventListener('input', function () {
        var q     = searchInput.value.trim().toLowerCase();
        var items = grid.querySelectorAll('.yt-item');
        var shown = 0;

        items.forEach(function (item) {
            var id    = (item.getAttribute('data-id') || '').toLowerCase();
            var match = q === '' || id.includes(q);
            item.style.display = match ? '' : 'none';
            if (match) shown++;
        });

        counter.textContent = shown;
    });
})();
</script>

</body>
</html>

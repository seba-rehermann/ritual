<?php
/**
 * Panel de administración del servidor Mindustry.
 * Ruta: /home/opti/proyectos/ritual/publico/panel/mindustry_admin.php
 * URL:  https://ritual.sebaji.org/panel/mindustry_admin.php
 *
 * Requisitos:
 *   - _auth.php y _docker.php en el mismo directorio.
 *   - /var/run/docker.sock montado en portal_publico (ya está).
 *   - Container mindustry_server con entrypoint que lee stdin desde FIFO
 *     en /tmp/mindustry.in (ya configurado).
 */

declare(strict_types=1);
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_docker.php';

const MINDUSTRY_CONTAINER   = 'mindustry_server';
const MINDUSTRY_FIFO        = '/tmp/mindustry.in';
const MINDUSTRY_MAPS_DIR    = '/config/config/maps';
const MINDUSTRY_BUILTIN_CACHE = '/tmp/mindustry_builtin_maps.txt';
const SELF                  = 'mindustry_admin.php';

// --------------------------------------------------------------------------
// Helpers específicos de Mindustry
// --------------------------------------------------------------------------

/** Inyecta un comando al server vía FIFO, sin devolver logs. */
function mindustry_inject(string $command): bool {
    $cmd = ['sh', '-c', 'printf "%s\n" "$1" > ' . MINDUSTRY_FIFO, 'panel', $command];
    $out = docker_exec(MINDUSTRY_CONTAINER, $cmd, 8);
    return !str_starts_with($out, '✗');
}

/** Inyecta y devuelve las últimas líneas del log como feedback. */
function mindustry_cmd(string $command, int $wait_ms = 700, int $tail = 18): string {
    if (!mindustry_inject($command)) return "✗ No se pudo inyectar el comando";
    usleep($wait_ms * 1000);
    $logs = container_logs(MINDUSTRY_CONTAINER, $tail, false);
    return "> {$command}\n\n" . strip_ansi($logs);
}

function strip_ansi(string $s): string {
    return preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $s) ?? $s;
}

/** Mapas custom subidos a /config/config/maps (*.msav). */
function mindustry_list_custom_maps(): array {
    $raw = docker_exec(MINDUSTRY_CONTAINER,
        ['sh', '-c', 'ls -1 ' . MINDUSTRY_MAPS_DIR . '/*.msav 2>/dev/null | xargs -I{} basename {} .msav'],
        5);
    $raw = trim($raw);
    if ($raw === '' || str_starts_with($raw, '✗')) return [];
    $lines = array_filter(array_map('trim', explode("\n", $raw)));
    sort($lines, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($lines);
}

/**
 * Mapas built-in del juego. Los obtiene ejecutando `maps all` y parseando logs.
 * Resultado cacheado 24h en /tmp porque no cambian dentro de la misma versión.
 */
function mindustry_list_builtin_maps(bool $force = false): array {
    if (!$force && is_file(MINDUSTRY_BUILTIN_CACHE)
        && (time() - filemtime(MINDUSTRY_BUILTIN_CACHE)) < 86400) {
        $c = trim(@file_get_contents(MINDUSTRY_BUILTIN_CACHE) ?: '');
        if ($c !== '') return array_values(array_filter(explode("\n", $c)));
    }
    if (!mindustry_inject('maps all')) return [];
    usleep(900000);
    $logs = container_logs(MINDUSTRY_CONTAINER, 60, false);
    preg_match_all('/\[I\]\s+([A-Za-z_][A-Za-z0-9_]*)\s*:\s*Default\s*\/\s*\d+x\d+/', $logs, $m);
    $maps = array_values(array_unique($m[1] ?? []));
    sort($maps, SORT_NATURAL | SORT_FLAG_CASE);
    if ($maps) @file_put_contents(MINDUSTRY_BUILTIN_CACHE, implode("\n", $maps));
    return $maps;
}

/**
 * Devuelve ['map' => ..., 'wave' => N] si hay juego corriendo, o null si está cerrado.
 * Pide status al server y parsea la salida. Hace ~1 request silencioso.
 */
function mindustry_current_game(): ?array {
    if (!mindustry_inject('status')) return null;
    usleep(600000);
    $logs = container_logs(MINDUSTRY_CONTAINER, 6, false);
    if (preg_match('/Playing on map (\S+) \/ Wave (\d+)/', $logs, $m)) {
        return ['map' => $m[1], 'wave' => (int)$m[2]];
    }
    return null;
}

// --------------------------------------------------------------------------
// Auth + acciones POST
// --------------------------------------------------------------------------
auth_handle_login_logout(SELF);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_authed()) { http_response_code(401); exit('No autenticado.'); }
    auth_require_csrf();

    $op = $_POST['op'] ?? '';
    $result = '';

    switch ($op) {
        case 'host':
            $map  = trim((string)($_POST['map'] ?? ''));
            $mode = trim((string)($_POST['mode'] ?? 'survival'));
            $valid_modes = ['survival', 'attack', 'pvp', 'sandbox'];
            if (!in_array($mode, $valid_modes, true)) $mode = 'survival';
            if ($map !== '' && !preg_match('/^[A-Za-z0-9 _.\-]{1,80}$/u', $map)) {
                $result = '✗ Nombre de mapa inválido.';
                break;
            }
            // Si ya estamos hosting, Mindustry rechaza el nuevo host.
            // Paramos primero (idempotente: si no hay juego, solo imprime un aviso).
            mindustry_inject('stop');
            usleep(900000);
            // Mindustry parsea literalmente las comillas como parte del nombre,
            // así que NO se envuelven. Los built-in usan underscore en vez de espacio.
            $cmd = $map !== '' ? "host $map $mode" : "host";
            $result = mindustry_cmd($cmd, 1500, 20);
            break;

        case 'reloadbuiltin':
            $maps = mindustry_list_builtin_maps(true);
            $result = "✓ Lista de built-in actualizada: " . count($maps) . " mapas.";
            break;

        case 'stop':
            $result = mindustry_cmd('stop');
            break;

        case 'gameover':
            $result = mindustry_cmd('gameover');
            break;

        case 'pause':
            $result = mindustry_cmd('js Vars.state.set(Vars.GameState.State.paused)');
            break;

        case 'resume':
            $result = mindustry_cmd('js Vars.state.set(Vars.GameState.State.playing)');
            break;

        case 'save':
            $slot = (int)($_POST['slot'] ?? 1);
            if ($slot < 1 || $slot > 9) $slot = 1;
            $result = mindustry_cmd("save $slot");
            break;

        case 'clear-items':
            $result = mindustry_cmd('js Groups.item.clear()');
            break;

        case 'runwave':
            $result = mindustry_cmd('runwave');
            break;

        case 'status':
            $result = mindustry_cmd('status');
            break;

        case 'say':
            $msg = trim((string)($_POST['message'] ?? ''));
            if ($msg === '') { $result = '✗ Mensaje vacío.'; break; }
            if (strlen($msg) > 200) $msg = substr($msg, 0, 200);
            $result = mindustry_cmd("say $msg");
            break;

        case 'reloadmaps':
            $result = mindustry_cmd('reloadmaps');
            break;

        case 'restart-container':
            $result = container_restart(MINDUSTRY_CONTAINER);
            break;

        default:
            $result = '✗ operación desconocida';
    }

    flash_set('ok', $result, ['op' => $op]);
    header('Location: ' . SELF . '#output'); exit;
}

$flash = flash_pop();

// --------------------------------------------------------------------------
// Render
// --------------------------------------------------------------------------
$csrf = csrf_token();
$authed = is_authed();
$docker_ok = $authed ? docker_available() : false;
$cstate    = ($authed && $docker_ok) ? container_state(MINDUSTRY_CONTAINER) : ['exists'=>false,'running'=>false,'status'=>'?'];
$custom_maps  = ($authed && $docker_ok && $cstate['running']) ? mindustry_list_custom_maps() : [];
$builtin_maps = ($authed && $docker_ok && $cstate['running']) ? mindustry_list_builtin_maps() : [];
$current_game = ($authed && $docker_ok && $cstate['running']) ? mindustry_current_game() : null;
$logs_live = ($authed && $docker_ok && $cstate['running']) ? strip_ansi(container_logs(MINDUSTRY_CONTAINER, 20, false)) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#1a0f00">
<title>Mindustry · Admin</title>
<style>
  :root {
    --bg:#120a00; --panel:#1a130a; --panel2:#221608; --border:#3a2a0f;
    --text:#f0e6cc; --muted:#8a7a5a;
    --amber:#ffb300; --orange:#ff8a00; --amber-dim:#6b4f00;
    --danger:#ff5555; --ok:#7ed957;
  }
  * { box-sizing: border-box; }
  html, body { margin:0; padding:0; background:var(--bg); color:var(--text);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    -webkit-font-smoothing:antialiased; }
  .wrap { max-width:780px; margin:0 auto; padding:18px 16px 60px; }

  header { display:flex; align-items:center; justify-content:space-between; margin:8px 0 22px; }
  h1 { font-size:1.1rem; margin:0; letter-spacing:3px; font-weight:600; color:var(--amber);
    text-shadow:0 0 12px rgba(255,179,0,.25); }
  h1 .dim { color:var(--orange); letter-spacing:2px; font-weight:300; }
  .topbar a, .topbar button {
    color:var(--muted); text-decoration:none; font-size:.85rem; padding:8px 12px;
    border:1px solid var(--border); border-radius:10px; background:transparent; cursor:pointer;
  }
  .topbar { display:flex; gap:8px; }
  .topbar a:active, .topbar button:active { background:var(--panel2); }

  .status-line { display:flex; gap:10px; align-items:center; margin:-8px 0 18px;
    color:var(--muted); font-size:.82rem; flex-wrap:wrap; }
  .badge { display:inline-flex; align-items:center; gap:6px; padding:4px 10px;
    background:var(--panel); border:1px solid var(--border); border-radius:999px; font-size:.78rem; }
  .badge .dot { width:8px; height:8px; border-radius:50%; background:var(--muted); }
  .badge.on .dot { background:var(--ok); box-shadow:0 0 6px var(--ok); }
  .badge.off .dot { background:var(--danger); }

  .card { background:var(--panel); border:1px solid var(--border); border-radius:14px;
    padding:18px; margin-bottom:14px; }
  .card h2 { margin:0 0 14px; font-size:.75rem; letter-spacing:2.5px; color:var(--amber);
    text-transform:uppercase; font-weight:600; }

  /* Forms / inputs */
  select, input[type=text], input[type=number], input[type=password] {
    width:100%; padding:13px 14px; background:var(--panel2); color:var(--text);
    border:1px solid var(--border); border-radius:10px; font-size:1rem; font-family:inherit;
    appearance:none;
  }
  select { background-image:linear-gradient(45deg,transparent 50%,var(--amber) 50%),
                             linear-gradient(-45deg,transparent 50%,var(--amber) 50%);
           background-position:calc(100% - 18px) center,calc(100% - 13px) center;
           background-size:5px 5px; background-repeat:no-repeat; padding-right:36px; }

  .row { display:flex; gap:8px; margin-bottom:10px; flex-wrap:wrap; }
  .row > * { flex:1; min-width:0; }
  .row .narrow { flex:0 0 120px; }

  /* Buttons */
  .btn {
    display:inline-flex; align-items:center; justify-content:center; gap:8px;
    padding:14px 16px; border-radius:12px; font-size:.95rem; font-weight:600;
    border:1px solid var(--border); cursor:pointer; background:var(--panel2);
    color:var(--text); min-height:48px; text-decoration:none; width:100%;
    transition:transform .08s, background .15s;
  }
  .btn:active { transform:scale(.97); }
  .btn-primary { background:linear-gradient(180deg,var(--amber),var(--orange));
    color:#1a0f00; border-color:transparent; }
  .btn-amber   { background:var(--amber-dim); border-color:var(--amber); color:var(--amber); }
  .btn-amber:active { background:#3a2c00; }
  .btn-danger  { background:#2a0808; border-color:#5a2a2a; color:#ff8a8a; }
  .btn-ghost   { background:transparent; border-color:var(--border); color:var(--muted); }

  .btn-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; }
  @media (max-width:480px) { .btn-grid { grid-template-columns:1fr; } }

  /* Console */
  .console {
    background:#050402; color:#d7c98a; padding:14px; border-radius:10px;
    font-family:"SF Mono","Menlo","Consolas",monospace; font-size:.78rem;
    line-height:1.5; white-space:pre-wrap; word-break:break-word; max-height:360px;
    overflow:auto; border:1px solid var(--border); min-height:120px;
  }
  .console .info { color:#ffd580; }
  .console .err  { color:#ff9a9a; }

  .output-card .op { color:var(--muted); font-size:.75rem; margin-bottom:6px; }

  form.inline { display:inline-flex; gap:8px; margin:0; flex:1; }

  /* Login */
  .login { max-width:360px; margin:16vh auto 0; }
  .login .err { color:var(--danger); margin-top:10px; font-size:.85rem; text-align:center; }

  .warn { background:#2a1a00; color:#ffb300; padding:12px 14px; border-radius:10px;
    border:1px solid var(--amber-dim); font-size:.85rem; margin-bottom:14px; }

  footer { text-align:center; color:var(--muted); font-size:.72rem; margin-top:24px; }
  footer a { color:var(--amber); text-decoration:none; }
</style>
</head>
<body>
<div class="wrap">

<?php if (!$authed): ?>

  <div class="login">
    <h1 style="text-align:center;margin-bottom:20px;">MINDUSTRY · <span class="dim">ADMIN</span></h1>
    <form method="POST" autocomplete="off">
      <input type="hidden" name="op" value="login">
      <input type="password" name="password" placeholder="Contraseña" autofocus required>
      <button class="btn btn-primary" style="margin-top:12px;" type="submit">Entrar</button>
      <?php if ($flash && ($flash['type'] ?? '') === 'err'): ?>
        <div class="err"><?= e($flash['msg']) ?></div>
      <?php endif; ?>
    </form>
  </div>

<?php else: ?>

  <header>
    <h1>MINDUSTRY · <span class="dim">ADMIN</span></h1>
    <div class="topbar">
      <a href="admin_server.php">Búnker</a>
      <a href="./">Panel</a>
      <form method="POST" style="display:inline;margin:0;">
        <input type="hidden" name="op" value="logout">
        <button type="submit">Salir</button>
      </form>
    </div>
  </header>

  <div class="status-line">
    <span class="badge <?= $cstate['running'] ? 'on' : 'off' ?>">
      <span class="dot"></span>
      <span><?= $cstate['running'] ? 'Container activo' : 'Container ' . e($cstate['status']) ?></span>
    </span>
    <?php if ($current_game): ?>
      <span class="badge" style="border-color:var(--amber);color:var(--amber);">
        <span>▶</span>
        <span>Jugando: <b><?= e($current_game['map']) ?></b> · Wave <?= (int)$current_game['wave'] ?></span>
      </span>
    <?php elseif ($cstate['running']): ?>
      <span class="badge" style="color:var(--muted);">
        <span>○</span>
        <span>Server cerrado (ningún juego activo)</span>
      </span>
    <?php endif; ?>
    <span class="badge">
      <span style="color:var(--amber);">⚡</span>
      <span><?= count($builtin_maps) ?> built-in + <?= count($custom_maps) ?> custom</span>
    </span>
    <span style="margin-left:auto;">
      <a href="<?= e(SELF) ?>" style="color:var(--amber);text-decoration:none;">↻ Refrescar</a>
    </span>
  </div>

  <?php if (!$docker_ok): ?>
    <div class="warn">⚠ Docker API no disponible desde este container.
      Verificá el mount de <code>/var/run/docker.sock</code> en <code>portal_publico</code>.</div>
  <?php elseif (!$cstate['running']): ?>
    <div class="warn">⚠ El container <code>mindustry_server</code> no está corriendo.
      Podés reiniciarlo desde este mismo panel (abajo) o desde Gestión Búnker.</div>
  <?php endif; ?>

  <!-- ============ CONTROL DE PARTIDA ============ -->
  <section class="card">
    <h2>Control de partida</h2>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="op" value="host">
      <div class="row">
        <select name="map" aria-label="Mapa">
          <option value="">[Aleatorio — default]</option>
          <?php if ($custom_maps): ?>
            <optgroup label="Custom (./config/maps)">
              <?php foreach ($custom_maps as $m): ?>
                <option value="<?= e($m) ?>"<?= ($current_game && $current_game['map'] === $m) ? ' selected' : '' ?>><?= e($m) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endif; ?>
          <?php if ($builtin_maps): ?>
            <optgroup label="Built-in (juego)">
              <?php foreach ($builtin_maps as $m): ?>
                <option value="<?= e($m) ?>"<?= ($current_game && $current_game['map'] === $m) ? ' selected' : '' ?>><?= e(str_replace('_', ' ', $m)) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endif; ?>
        </select>
        <select name="mode" class="narrow" aria-label="Modo">
          <option value="survival" selected>survival</option>
          <option value="attack">attack</option>
          <option value="pvp">pvp</option>
          <option value="sandbox">sandbox</option>
        </select>
      </div>
      <button class="btn btn-primary" type="submit">
        <?= $current_game ? '🔄 Cambiar Mapa' : '▶ Lanzar Mapa' ?>
      </button>
      <?php if ($current_game): ?>
        <p style="color:var(--muted);font-size:.78rem;margin:10px 0 0;">
          Hay un juego en curso (<?= e($current_game['map']) ?>). Al lanzar otro, se detiene el actual y los jugadores se reconectan.
        </p>
      <?php endif; ?>
    </form>

    <div class="btn-grid" style="margin-top:14px;">
      <form method="POST" class="inline">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button class="btn btn-amber" name="op" value="pause">⏸ Pausar</button>
      </form>
      <form method="POST" class="inline">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button class="btn btn-amber" name="op" value="resume">▶ Reanudar</button>
      </form>
      <form method="POST" class="inline">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="slot" value="1">
        <button class="btn btn-amber" name="op" value="save">💾 Guardar (slot 1)</button>
      </form>
      <form method="POST" class="inline">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button class="btn btn-amber" name="op" value="clear-items">🧹 Limpiar ítems</button>
      </form>
      <form method="POST" class="inline">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button class="btn btn-amber" name="op" value="runwave">🌊 Lanzar wave</button>
      </form>
      <form method="POST" class="inline">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button class="btn btn-amber" name="op" value="reloadmaps">🔄 Recargar mapas</button>
      </form>
      <form method="POST" class="inline" onsubmit="return confirm('¿Detener el juego en curso?');">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button class="btn btn-danger" name="op" value="stop">⛔ Stop</button>
      </form>
      <form method="POST" class="inline" onsubmit="return confirm('¿Forzar game-over?');">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button class="btn btn-danger" name="op" value="gameover">☠ Game over</button>
      </form>
    </div>
  </section>

  <!-- ============ JUGADORES / STATUS ============ -->
  <section class="card">
    <h2>Jugadores &amp; estado</h2>

    <form method="POST" style="margin-bottom:14px;">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <button class="btn btn-amber" name="op" value="status">📊 Ver status del server</button>
    </form>

    <form method="POST">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="op" value="say">
      <label style="display:block;color:var(--muted);font-size:.82rem;margin-bottom:6px;">
        Enviar mensaje global (comando <code>say</code>):
      </label>
      <div class="row">
        <input type="text" name="message" placeholder="Mensaje para todos los jugadores"
               maxlength="200" required>
      </div>
      <button class="btn btn-primary" type="submit">📢 Enviar mensaje</button>
    </form>
  </section>

  <!-- ============ SALIDA DE COMANDO ============ -->
  <?php if ($flash): ?>
  <section class="card output-card" id="output">
    <h2>Salida · <?= e($flash['op'] ?? '') ?></h2>
    <div class="op">últimas 18 líneas del log del server tras el comando</div>
    <div class="console <?= (strpos($flash['msg'], '✗') === 0) ? 'err' : '' ?>"><?= e($flash['msg']) ?></div>
  </section>
  <?php endif; ?>

  <!-- ============ CONSOLA EN VIVO ============ -->
  <section class="card">
    <h2>Consola en vivo <span style="color:var(--muted);font-weight:400;font-size:.7rem;">· últimas 20 líneas</span></h2>
    <div class="console"><?= e($logs_live !== '' ? $logs_live : '(contenedor no corriendo)') ?></div>
    <div style="margin-top:10px;display:flex;gap:8px;justify-content:flex-end;">
      <a class="btn btn-ghost" style="width:auto;padding:8px 14px;" href="<?= e(SELF) ?>">↻ Refrescar</a>
    </div>
  </section>

  <!-- ============ CONTAINER ============ -->
  <section class="card">
    <h2>Container</h2>
    <form method="POST" onsubmit="return confirm('¿Reiniciar el container mindustry_server?');">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <button class="btn btn-danger" name="op" value="restart-container">♻ Reiniciar container</button>
    </form>
  </section>

  <footer>
    <?= e(MINDUSTRY_CONTAINER) ?> · puerto 6567 · <?= date('Y-m-d H:i') ?><br>
    <a href="admin_server.php">← Volver a Gestión Búnker</a>
  </footer>

<?php endif; ?>

</div>
</body>
</html>

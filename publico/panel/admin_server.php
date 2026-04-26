<?php
/**
 * Panel de administración del Búnker Optiplex
 * Ruta: /home/opti/proyectos/ritual/publico/panel/admin_server.php
 * URL:  https://ritual.sebaji.org/panel/admin_server.php
 *
 * ⚙ Requisitos (una única vez):
 *   1. En docker-compose.yml del servicio web_publica, agregar el montaje del socket:
 *        volumes:
 *          - /var/run/docker.sock:/var/run/docker.sock
 *   2. `docker compose up -d --force-recreate web_publica`
 *
 *   No requiere instalar docker-cli: hablamos con la Engine API vía curl.
 */

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_docker.php';

const SELF = 'admin_server.php';
const SERVICES = [
    'ritual-publico' => ['container' => 'portal_publico',   'label' => 'Ritual · Público', 'color' => '#00e676'],
    'ritual-admin'   => ['container' => 'portal_admin',     'label' => 'Ritual · Admin',   'color' => '#00b85a'],
    'mindustry'      => ['container' => 'mindustry_server', 'label' => 'Mindustry',        'color' => '#ff8a00'],
    'portainer'      => ['container' => 'portainer',        'label' => 'Portainer',        'color' => '#00bcd4'],
];
const BACKUP_LOG_GLOB = '/var/www/html/data/backup-*.log';

function containers_list(): string {
    $r = docker_api('GET', '/containers/json?all=true');
    if ($r['http'] !== 200) return "✗ HTTP {$r['http']}";
    $rows = json_decode($r['body'], true) ?: [];
    if (!$rows) return '(sin contenedores)';
    $out = sprintf("%-22s %-35s %-12s %s\n", 'NOMBRE', 'IMAGEN', 'ESTADO', 'PUERTOS');
    foreach ($rows as $c) {
        $name  = ltrim($c['Names'][0] ?? '', '/');
        $image = $c['Image'] ?? '';
        $state = $c['State'] ?? '';
        $ports = [];
        foreach (($c['Ports'] ?? []) as $p) {
            if (!empty($p['PublicPort'])) {
                $ports[] = "{$p['PublicPort']}->{$p['PrivatePort']}/{$p['Type']}";
            }
        }
        $out .= sprintf("%-22s %-35s %-12s %s\n",
            substr($name, 0, 22), substr($image, 0, 35), $state, implode(' ', array_unique($ports)));
    }
    return $out;
}

// --------------------------------------------------------------------------
// Helpers: Sistema (leemos /proc sin sudo)
// --------------------------------------------------------------------------
function read_meminfo(): array {
    $m = [];
    foreach (@file('/proc/meminfo') ?: [] as $line) {
        if (preg_match('/^(\w+):\s+(\d+)\s*kB/', $line, $x)) $m[$x[1]] = (int)$x[2];
    }
    $total = $m['MemTotal'] ?? 0;
    $avail = $m['MemAvailable'] ?? ($m['MemFree'] ?? 0);
    $used  = max(0, $total - $avail);
    return [
        'total_mb' => (int)round($total / 1024),
        'used_mb'  => (int)round($used / 1024),
        'pct'      => $total ? (int)round($used * 100 / $total) : 0,
    ];
}

function read_cpu_pct(): int {
    // Lectura doble con 100ms para calcular %
    $a = @file_get_contents('/proc/stat'); if (!$a) return 0;
    usleep(100000);
    $b = @file_get_contents('/proc/stat'); if (!$b) return 0;
    $parse = function(string $s): array {
        $first = strtok($s, "\n");
        $parts = preg_split('/\s+/', trim($first));
        array_shift($parts); // quitar 'cpu'
        $parts = array_map('intval', $parts);
        $idle  = ($parts[3] ?? 0) + ($parts[4] ?? 0);
        $total = array_sum($parts);
        return [$idle, $total];
    };
    [$i1, $t1] = $parse($a);
    [$i2, $t2] = $parse($b);
    $dt = $t2 - $t1; $di = $i2 - $i1;
    return $dt > 0 ? (int)round(100 * ($dt - $di) / $dt) : 0;
}

function read_uptime(): string {
    $u = (float)explode(' ', (@file_get_contents('/proc/uptime') ?: '0'))[0];
    $d = intdiv((int)$u, 86400); $u -= $d * 86400;
    $h = intdiv((int)$u, 3600);  $u -= $h * 3600;
    $m = intdiv((int)$u, 60);
    return ($d ? "{$d}d " : '') . sprintf('%02d:%02d', $h, $m);
}

function read_loadavg(): string {
    $s = @file_get_contents('/proc/loadavg') ?: '';
    $p = explode(' ', $s);
    return sprintf('%s · %s · %s', $p[0] ?? '?', $p[1] ?? '?', $p[2] ?? '?');
}

function read_disk(): array {
    // /var/www/html está bind-mounted desde el host → refleja el FS del host.
    $out = shell_exec('df -h --output=size,used,avail,pcent / 2>/dev/null | tail -1') ?? '';
    $p = preg_split('/\s+/', trim($out));
    return [
        'total' => $p[0] ?? '?',
        'used'  => $p[1] ?? '?',
        'avail' => $p[2] ?? '?',
        'pct'   => (int) rtrim($p[3] ?? '0%', '%'),
    ];
}

function read_backup_last(): string {
    $files = glob(BACKUP_LOG_GLOB) ?: [];
    if (!$files) return '(no hay logs de backup en data/)';
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $latest = $files[0];
    return basename($latest) . ' · ' . date('Y-m-d H:i', filemtime($latest));
}

// --------------------------------------------------------------------------
// Auth + acciones
// --------------------------------------------------------------------------
auth_handle_login_logout(SELF);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_authed()) { http_response_code(401); exit('No autenticado.'); }
    auth_require_csrf();

    $op     = $_POST['op'] ?? '';
    $target = $_POST['target'] ?? '';
    $svc    = SERVICES[$target] ?? null;
    $result = '';

    switch ($op) {
        case 'restart':
            $result = $svc ? container_restart($svc['container']) : '✗ servicio desconocido';
            break;
        case 'logs':
            $result = $svc ? container_logs($svc['container'], 150) : '✗ servicio desconocido';
            break;
        case 'docker-ls':
            $result = containers_list();
            break;
        case 'backup-show':
            $files = glob(BACKUP_LOG_GLOB) ?: [];
            if (!$files) { $result = '(no hay logs de backup)'; break; }
            usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
            $result = "==> " . basename($files[0]) . "\n\n" . (file_get_contents($files[0]) ?: '');
            break;
        default:
            $result = '✗ operación no permitida';
    }

    flash_set('ok', $result, ['op' => $op, 'target' => $target]);
    header('Location: ' . SELF . '#output'); exit;
}

$flash = flash_pop();

// --------------------------------------------------------------------------
// Render
// --------------------------------------------------------------------------
$csrf = csrf_token();
$authed = is_authed();
$docker_ok = $authed ? docker_available() : false;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#0a0a0a">
<title>Admin · Búnker Optiplex</title>
<style>
  :root {
    --bg:#0a0a0a; --panel:#131313; --panel2:#1a1a1a; --border:#2a2a2a;
    --text:#f0f0f0; --muted:#888; --accent:#00e676; --danger:#ff5555; --warn:#ffb300;
  }
  * { box-sizing: border-box; }
  html, body { margin:0; padding:0; background:var(--bg); color:var(--text);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    -webkit-font-smoothing:antialiased; }
  .wrap { max-width:760px; margin:0 auto; padding:18px 16px 60px; }
  header { display:flex; align-items:center; justify-content:space-between; margin:8px 0 22px; }
  h1 { font-size:1.05rem; margin:0; letter-spacing:2.5px; font-weight:500; color:var(--accent); }
  h1 .dim { color:var(--muted); letter-spacing:2px; font-weight:300; }
  .logout { color:var(--muted); text-decoration:none; font-size:.85rem; padding:8px 12px;
    border:1px solid var(--border); border-radius:10px; }
  .logout:active { background:#222; }

  .card { background:var(--panel); border:1px solid var(--border); border-radius:16px;
    padding:18px; margin-bottom:14px; }
  .card h2 { margin:0 0 12px; font-size:.75rem; letter-spacing:2px; color:var(--muted);
    text-transform:uppercase; font-weight:600; }

  .stats { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; }
  .stat { background:var(--panel2); border-radius:12px; padding:12px 14px; }
  .stat .k { font-size:.7rem; color:var(--muted); letter-spacing:1.5px; text-transform:uppercase; }
  .stat .v { font-size:1.35rem; font-weight:600; margin-top:2px; }
  .stat .s { font-size:.7rem; color:var(--muted); margin-top:2px; }
  .bar { height:6px; background:#222; border-radius:3px; margin-top:8px; overflow:hidden; }
  .bar > i { display:block; height:100%; background:var(--accent); border-radius:3px; }
  .bar > i.hi { background:var(--warn); }
  .bar > i.crit { background:var(--danger); }

  .svc { display:flex; align-items:center; gap:10px; padding:12px; margin-bottom:10px;
    background:var(--panel2); border-radius:12px; border-left:4px solid var(--border); }
  .svc .dot { width:9px; height:9px; border-radius:50%; background:var(--muted); flex:0 0 auto; }
  .svc.on  .dot { background:var(--accent); box-shadow:0 0 8px var(--accent); }
  .svc.off .dot { background:var(--danger); }
  .svc .name { flex:1; min-width:0; }
  .svc .name b { display:block; font-size:.95rem; }
  .svc .name span { font-size:.72rem; color:var(--muted); }
  .svc .acts { display:flex; gap:6px; flex-wrap:wrap; justify-content:flex-end; }
  .svc button { background:#222; color:var(--text); border:1px solid var(--border);
    padding:9px 12px; border-radius:10px; font-size:.8rem; cursor:pointer; min-height:38px; }
  .svc button.danger { border-color:#5a2a2a; color:#ffb4b4; }
  .svc button:active { background:#2a2a2a; transform:scale(.97); }

  form.inline { display:inline; margin:0; }

  .output { white-space:pre-wrap; word-break:break-word; background:#050505; color:#ddd;
    padding:14px; border-radius:12px; font-family:"SF Mono","Menlo","Consolas",monospace;
    font-size:.78rem; max-height:420px; overflow:auto; border:1px solid var(--border); }
  .output.err { color:#ffb4b4; border-color:#5a2a2a; }

  details.acc { background:var(--panel2); border-radius:12px; margin-bottom:10px;
    border:1px solid var(--border); overflow:hidden; }
  details.acc > summary { cursor:pointer; padding:16px; font-weight:500; list-style:none;
    display:flex; align-items:center; justify-content:space-between; }
  details.acc > summary::-webkit-details-marker { display:none; }
  details.acc > summary::after { content:"+"; font-size:1.3rem; color:var(--muted);
    transition:transform .2s ease; }
  details.acc[open] > summary::after { content:"−"; }
  details.acc > div { padding:0 16px 16px; border-top:1px solid var(--border); color:#cfcfcf;
    font-size:.92rem; line-height:1.55; }
  details.acc code { background:#050505; padding:2px 6px; border-radius:4px; font-size:.82rem; }
  details.acc pre { background:#050505; padding:10px; border-radius:8px; overflow:auto;
    font-size:.78rem; }

  .warn-box { background:#2a1a00; color:#ffb300; padding:12px 14px; border-radius:10px;
    border:1px solid #5a4000; font-size:.85rem; margin-bottom:14px; }
  .flash-ok { border-color:#1a4a2a; }
  .flash-err { color:#ffb4b4; border-color:#5a2a2a; }

  /* Login */
  .login { max-width:360px; margin:18vh auto 0; }
  .login input[type=password] { width:100%; padding:14px; background:var(--panel2);
    color:var(--text); border:1px solid var(--border); border-radius:12px; font-size:1rem; }
  .login button { width:100%; margin-top:12px; padding:14px; background:var(--accent);
    color:#001e0b; border:0; border-radius:12px; font-weight:600; font-size:1rem;
    cursor:pointer; }
  .login .err { color:var(--danger); margin-top:10px; font-size:.85rem; text-align:center; }
</style>
</head>
<body>
<div class="wrap">

<?php if (!$authed): ?>
  <div class="login">
    <h1 style="text-align:center;margin-bottom:20px;">BÚNKER · <span class="dim">ADMIN</span></h1>
    <form method="POST" autocomplete="off">
      <input type="hidden" name="op" value="login">
      <input type="password" name="password" placeholder="Contraseña" autofocus required>
      <button type="submit">Entrar</button>
      <?php if ($flash && ($flash['type'] ?? '') === 'err'): ?>
        <div class="err"><?= e($flash['msg']) ?></div>
      <?php endif; ?>
    </form>
  </div>

<?php else: ?>

  <header>
    <h1>BÚNKER · <span class="dim">ADMIN</span></h1>
    <form method="POST" class="inline">
      <input type="hidden" name="op" value="logout">
      <button class="logout" type="submit">Salir</button>
    </form>
  </header>

  <?php if (!$docker_ok): ?>
    <div class="warn-box">
      ⚠ Docker API no disponible. Agregá al <code>docker-compose.yml</code> del servicio
      <code>web_publica</code>:
      <pre style="margin:8px 0 0;background:#050505;padding:10px;border-radius:8px;">volumes:
  - /var/run/docker.sock:/var/run/docker.sock</pre>
      Luego: <code>docker compose up -d --force-recreate web_publica</code>
    </div>
  <?php endif; ?>

  <?php $mem = read_meminfo(); $cpu = read_cpu_pct(); $dsk = read_disk(); ?>
  <section class="card">
    <h2>Sistema</h2>
    <div class="stats">
      <div class="stat">
        <div class="k">CPU</div>
        <div class="v"><?= $cpu ?>%</div>
        <div class="bar"><i class="<?= $cpu>85?'crit':($cpu>60?'hi':'') ?>" style="width:<?= $cpu ?>%"></i></div>
      </div>
      <div class="stat">
        <div class="k">RAM</div>
        <div class="v"><?= $mem['pct'] ?>%</div>
        <div class="s"><?= $mem['used_mb'] ?> / <?= $mem['total_mb'] ?> MB</div>
        <div class="bar"><i class="<?= $mem['pct']>85?'crit':($mem['pct']>60?'hi':'') ?>" style="width:<?= $mem['pct'] ?>%"></i></div>
      </div>
      <div class="stat">
        <div class="k">Disco /</div>
        <div class="v"><?= $dsk['pct'] ?>%</div>
        <div class="s"><?= e($dsk['used']) ?> / <?= e($dsk['total']) ?> · libre <?= e($dsk['avail']) ?></div>
        <div class="bar"><i class="<?= $dsk['pct']>85?'crit':($dsk['pct']>60?'hi':'') ?>" style="width:<?= $dsk['pct'] ?>%"></i></div>
      </div>
      <div class="stat">
        <div class="k">Uptime</div>
        <div class="v" style="font-size:1.1rem;"><?= e(read_uptime()) ?></div>
        <div class="s">load <?= e(read_loadavg()) ?></div>
      </div>
    </div>
    <div style="text-align:right;margin-top:10px;">
      <a href="admin_server.php" style="color:var(--muted);font-size:.8rem;text-decoration:none;">↻ Actualizar</a>
    </div>
  </section>

  <section class="card">
    <h2>Servicios</h2>
    <?php foreach (SERVICES as $key => $s):
      $st = $docker_ok ? container_state($s['container']) : ['exists'=>false,'status'=>'?','running'=>false];
      $cls = !$docker_ok ? '' : ($st['running'] ? 'on' : ($st['exists'] ? 'off' : ''));
      $sub = !$docker_ok ? 'docker no disponible' :
             (!$st['exists'] ? 'contenedor no existe' : ($st['status'] ?? '?'));
    ?>
    <div class="svc <?= $cls ?>" style="border-left-color: <?= $s['color'] ?>;">
      <div class="dot"></div>
      <div class="name"><b><?= e($s['label']) ?></b><span><?= e($s['container']) ?> · <?= e($sub) ?></span></div>
      <div class="acts">
        <form method="POST" class="inline">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="target" value="<?= e($key) ?>">
          <button name="op" value="logs" <?= $docker_ok ? '' : 'disabled' ?>>Logs</button>
        </form>
        <form method="POST" class="inline" onsubmit="return confirm('¿Reiniciar <?= e($s['label']) ?>?');">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="target" value="<?= e($key) ?>">
          <button class="danger" name="op" value="restart" <?= $docker_ok ? '' : 'disabled' ?>>Reiniciar</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </section>

  <section class="card">
    <h2>Docker</h2>
    <form method="POST" class="inline">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="target" value="">
      <button name="op" value="docker-ls" <?= $docker_ok ? '' : 'disabled' ?>
              style="background:#222;color:var(--text);border:1px solid var(--border);
                     padding:11px 14px;border-radius:10px;font-size:.85rem;cursor:pointer;">
        Listar todos los contenedores
      </button>
    </form>
  </section>

  <section class="card">
    <h2>Backups</h2>
    <div style="color:var(--muted);font-size:.85rem;margin-bottom:10px;">
      Último log: <span style="color:#ddd;"><?= e(read_backup_last()) ?></span>
    </div>
    <form method="POST" class="inline">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="target" value="">
      <button name="op" value="backup-show"
              style="background:#222;color:var(--text);border:1px solid var(--border);
                     padding:11px 14px;border-radius:10px;font-size:.85rem;cursor:pointer;">
        Ver último backup.log
      </button>
    </form>
  </section>

  <?php if ($flash): ?>
  <section class="card" id="output">
    <h2>Salida · <?= e($flash['op'] ?? '') ?> <?= e($flash['target'] ?? '') ?></h2>
    <div class="output <?= (strpos($flash['msg'], '✗') === 0) ? 'err' : '' ?>"><?= e($flash['msg']) ?></div>
  </section>
  <?php endif; ?>

  <section class="card">
    <h2>Guía del Búnker</h2>

    <details class="acc">
      <summary>🗄 Backups dominicales</summary>
      <div>
        <p>Los scripts de backup corren los <b>domingos</b> por crontab del usuario <code>opti</code>
          y de <code>root</code>. Revisá con:</p>
        <pre>crontab -l            # opti
sudo crontab -l       # root
ls /home/opti/scripts/backup*.sh</pre>
        <p><b>Destinos:</b></p>
        <ul>
          <li><b>Google Drive</b> — carpeta <i>Respaldo Optiplex</i> (via <code>rclone</code>).</li>
          <li><b>GitHub</b> — repo <a href="https://github.com/seba-rehermann/ritual" style="color:var(--accent)">seba-rehermann/ritual</a> para el código de Ritual.</li>
          <li><b>Pendrive</b> — montado en <code>/srv/respaldo-pen</code> (<code>.fsa</code> de <code>fsarchiver</code>).</li>
        </ul>
        <p><b>Logs:</b> se guardan en <code>/home/opti/proyectos/ritual/compartido/data/backup-*.log</code>
          y los ves desde este panel (tarjeta Backups).</p>
      </div>
    </details>

    <details class="acc">
      <summary>🎮 Mindustry Server</summary>
      <div>
        <p><b>Conexión:</b> LAN <code>192.168.1.60:6567</code> · Tailscale <code>100.80.170.36:6567</code>
          (UDP para juego, TCP también expuesto).</p>
        <p><b>Consola interactiva</b> (desde la Opti):</p>
        <pre>sudo docker attach mindustry_server
# salir sin matarla:  Ctrl+P  Ctrl+Q</pre>
        <p><b>Comandos dentro del server:</b></p>
        <ul>
          <li><code>host</code> — lista mapas y lanza uno.</li>
          <li><code>host maps/XX.msav survival</code> — abrir partida sobre mapa específico.</li>
          <li><code>maps</code> — mapas disponibles.</li>
          <li><code>players</code> — quién está conectado.</li>
          <li><code>admin add &lt;nombre&gt;</code> — dar admin. <code>kick</code> / <code>ban</code> según hace falta.</li>
          <li><code>say Hola a todos</code> · <code>stop</code> detiene partida · <code>exit</code> apaga el server.</li>
        </ul>
        <p><b>Persistencia:</b> <code>/home/opti/proyectos/mindustry/config/</code> (bind-mount).
          Saves, mapas y <code>config.json</code> viven ahí.</p>
      </div>
    </details>

    <details class="acc">
      <summary>🌐 Red · Tailscale &amp; Cloudflare</summary>
      <div>
        <p><b>Tailscale</b> es la red privada que conecta todos tus dispositivos (MX, móvil, Opti)
          como si estuvieran en la misma LAN, con IPs 100.x.x.x, sin abrir puertos en el router.</p>
        <ul>
          <li>Opti: <code>100.80.170.36</code> · login con <code>sudo tailscale up</code>.</li>
          <li>Estado: <code>tailscale status</code>.</li>
          <li>Usalo para SSH, Portainer, Mindustry <i>sin</i> exponer nada a Internet.</li>
        </ul>
        <p><b>Cloudflare Tunnel</b> (<code>cloudflared</code>) expone Ritual a Internet con HTTPS
          gratuito, sin abrir puertos del router. Los subdominios apuntan a contenedores locales:</p>
        <ul>
          <li><code>ritual.sebaji.org</code> → <code>portal_publico:80</code></li>
          <li><code>admin-ritual.sebaji.org</code> → <code>portal_admin:80</code></li>
        </ul>
        <p><b>Gestión:</b> <a href="https://one.dash.cloudflare.com/" style="color:var(--accent)">dash.cloudflare.com → Zero Trust → Networks → Tunnels</a>.
          Logs locales: <code>sudo journalctl -u cloudflared -f</code>.</p>
      </div>
    </details>

    <details class="acc">
      <summary>🔐 Seguridad</summary>
      <div>
        <p>Esta página ejecuta comandos sobre el Docker daemon. La contraseña es
          <b>hardcoded (bcrypt)</b> en <code>admin_server.php</code> → constante
          <code>ADMIN_PASSWORD_HASH</code>.</p>
        <p><b>Cambiar contraseña:</b></p>
        <pre>docker exec portal_publico php -r 'echo password_hash("NUEVO_PASS", PASSWORD_BCRYPT);'
# pegar el resultado en ADMIN_PASSWORD_HASH y recargar la página.</pre>
        <p><b>Buenas prácticas pendientes:</b></p>
        <ul>
          <li>Acceder a este panel <b>solo por Tailscale</b> (no exponer <code>/panel/admin_server.php</code> vía Cloudflare público), o protegerlo con Cloudflare Access.</li>
          <li>Revocar <code>NOPASSWD: ALL</code> en <code>/etc/sudoers.d/99-agente</code> cuando termine la migración.</li>
          <li>Rotar el token del Cloudflare Tunnel tras la configuración inicial.</li>
          <li>Mantener <code>sudo apt upgrade</code> al día (domingos junto con los backups).</li>
        </ul>
      </div>
    </details>

  </section>

  <div style="text-align:center;color:var(--muted);font-size:.72rem;margin-top:24px;">
    Optiplex · <?= e(gethostname() ?: 'opti') ?> · <?= date('Y-m-d H:i') ?>
  </div>

<?php endif; ?>

</div>
</body>
</html>

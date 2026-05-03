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

const MINDUSTRY_CONTAINER     = 'mindustry_server';
const MINDUSTRY_FIFO          = '/tmp/mindustry.in';
const MINDUSTRY_MAPS_DIR      = '/config/config/maps';
const MINDUSTRY_BUILTIN_CACHE = '/tmp/mindustry_builtin_maps.txt';
const SELF                    = 'mindustry_admin.php';

/** Rhino: 4 overlays de spawn en el borde + clamp a tamaño del mapa. */
const JS_SPAWN_EDGE_INJECT = 'var B=Packages.mindustry.content.Blocks;var V=Packages.mindustry.Vars;var W=V.world.width();var H=V.world.height();function p(x,y){x=x|0;y=y|0;if(x<0||y<0||x>=W||y>=H)return;var t=V.world.tile(x,y);if(t!=null)t.setOverlayNet(B.spawn);}p(Math.min(15,W-1),(H/2|0));p(Math.max(0,W-16),(H/2|0));p((W/2|0),Math.min(15,H-1));p((W/2|0),Math.max(0,H-16));V.spawner.reset();"Spawns="+V.spawner.countSpawns()';

const JS_DIAG_SPAWNER = '("Spawns="+Vars.spawner.countSpawns()+" waves="+Vars.state.rules.waves+" timer="+Vars.state.rules.waveTimer)';

const JS_SAVE_CUSTOM_MAP = 'var Om=new Packages.arc.struct.ObjectMap();Om.put("name",Packages.mindustry.Vars.state.map.name());Packages.mindustry.Vars.maps.saveMap(Om);"saveMap_ok"';

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
    if (!mindustry_inject($command)) {
        return "✗ No se pudo inyectar el comando";
    }
    usleep($wait_ms * 1000);
    $logs = container_logs(MINDUSTRY_CONTAINER, $tail, false);
    return "> {$command}\n\n" . strip_ansi($logs);
}

function strip_ansi(string $s): string {
    return preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $s) ?? $s;
}

/** Evita inyección de líneas en el FIFO vía say (un solo renglón). */
function mindustry_sanitize_say(string $msg): string {
    $msg = preg_replace('/[\x00-\x1F\x7F\\\\]/u', '', $msg) ?? '';
    return trim($msg);
}

/** Mapas custom subidos a /config/config/maps (*.msav). */
function mindustry_list_custom_maps(): array {
    $raw = docker_exec(MINDUSTRY_CONTAINER,
        ['sh', '-c', 'ls -1 ' . MINDUSTRY_MAPS_DIR . '/*.msav 2>/dev/null | xargs -I{} basename {} .msav'],
        5);
    $raw = trim($raw);
    if ($raw === '' || str_starts_with($raw, '✗')) {
        return [];
    }
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
        if ($c !== '') {
            return array_values(array_filter(explode("\n", $c)));
        }
    }
    if (!mindustry_inject('maps all')) {
        return [];
    }
    usleep(900000);
    $logs = container_logs(MINDUSTRY_CONTAINER, 60, false);
    preg_match_all('/\[I\]\s+([A-Za-z_][A-Za-z0-9_]*)\s*:\s*Default\s*\/\s*\d+x\d+/', $logs, $m);
    $maps = array_values(array_unique($m[1] ?? []));
    sort($maps, SORT_NATURAL | SORT_FLAG_CASE);
    if ($maps) {
        @file_put_contents(MINDUSTRY_BUILTIN_CACHE, implode("\n", $maps));
    }
    return $maps;
}

/**
 * Devuelve ['map' => ..., 'wave' => N] si hay juego corriendo, o null si está cerrado.
 * Pide status al server y parsea la salida.
 */
function mindustry_current_game(): ?array {
    if (!mindustry_inject('status')) {
        return null;
    }
    usleep(650000);
    $logs = container_logs(MINDUSTRY_CONTAINER, 12, false);
    // Nombres con espacios: "Playing on map Mi mapa / Wave 3"
    if (preg_match('/Playing on map (.+?) \/ Wave (\d+)/u', $logs, $m)) {
        return ['map' => trim($m[1]), 'wave' => (int)$m[2]];
    }
    return null;
}

/**
 * Aplica cambios en Vars.state.rules y los sincroniza a clientes (Call.setRules).
 * $stmt: fragmento JS con asignaciones, sin prefijo «js » ni punto y coma final.
 */
function mindustry_js_rules(string $stmt): string {
    return 'js ' . $stmt
        . ';Packages.mindustry.gen.Call.setRules(Packages.mindustry.Vars.state.rules);"rules_sync"';
}

// --------------------------------------------------------------------------
// Auth + acciones POST
// --------------------------------------------------------------------------
auth_handle_login_logout(SELF);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_authed()) {
        http_response_code(401);
        exit('No autenticado.');
    }
    auth_require_csrf();

    $op = $_POST['op'] ?? '';
    $result = '';

    switch ($op) {
        case 'host':
            $map  = trim((string)($_POST['map'] ?? ''));
            $mode = trim((string)($_POST['mode'] ?? 'survival'));
            $valid_modes = ['survival', 'attack', 'pvp', 'sandbox'];
            if (!in_array($mode, $valid_modes, true)) {
                $mode = 'survival';
            }
            if ($map !== '' && !preg_match('/^[A-Za-z0-9 _.\-]{1,80}$/u', $map)) {
                $result = '✗ Nombre de mapa inválido.';
                break;
            }
            mindustry_inject('stop');
            usleep(900000);
            $cmd = $map !== '' ? "host $map $mode" : 'host';
            $result = mindustry_cmd($cmd, 1500, 22);
            break;

        case 'reloadbuiltin':
            $maps = mindustry_list_builtin_maps(true);
            $result = '✓ Lista built-in actualizada: ' . count($maps) . " mapas.\n"
                . "(Caché en servidor web: " . MINDUSTRY_BUILTIN_CACHE . ')';
            break;

        case 'stop':
            $result = mindustry_cmd('stop');
            break;

        case 'gameover':
            $result = mindustry_cmd('gameover');
            break;

        case 'pause':
            $result = mindustry_cmd('pause on', 700, 16);
            break;

        case 'resume':
            $result = mindustry_cmd('pause off', 700, 16);
            break;

        case 'save':
            $slot = (int)($_POST['slot'] ?? 1);
            if ($slot < 1 || $slot > 9) {
                $slot = 1;
            }
            $result = mindustry_cmd("save $slot", 900, 20);
            break;

        case 'fix-spawns':
            $r1 = mindustry_cmd('js ' . JS_SPAWN_EDGE_INJECT, 1200, 22);
            $r2 = mindustry_cmd('js ' . JS_DIAG_SPAWNER, 900, 12);
            $result = $r1 . "\n\n— Diagnóstico —\n" . $r2;
            break;

        case 'save-custom-map':
            $r1 = mindustry_cmd('js ' . JS_SAVE_CUSTOM_MAP, 2200, 28);
            $note = "\n\nNota: escribe el .msav del mapa en curso (tags + mundo + entidades). "
                . "Conviene partida limpia. El fichero queda en " . MINDUSTRY_MAPS_DIR . " si el mapa es custom.\n";
            $result = $r1 . $note;
            break;

        case 'runwave':
            $r1 = mindustry_cmd('runwave', 1600, 36);
            $r2 = mindustry_cmd('js ' . JS_DIAG_SPAWNER, 1000, 12);
            $hint = "\n(Si Spawns=0: usá «Reparar spawns (borde)» o editá el .msav en el editor Mindustry.)\n";
            $result = $r1 . $hint . $r2;
            break;

        case 'status':
            $result = mindustry_cmd('status', 800, 24);
            break;

        case 'version':
            $result = mindustry_cmd('version', 800, 40);
            break;

        case 'say':
            $msg = mindustry_sanitize_say((string)($_POST['message'] ?? ''));
            if ($msg === '') {
                $result = '✗ Mensaje vacío o inválido.';
                break;
            }
            if (strlen($msg) > 200) {
                $msg = substr($msg, 0, 200);
            }
            $result = mindustry_cmd('say ' . $msg, 800, 16);
            break;

        case 'reloadmaps':
            $result = mindustry_cmd('reloadmaps', 900, 18);
            break;

        // -------- Oleadas (reglas de juego) --------
        case 'wavetimer-off':
            $result = mindustry_cmd(mindustry_js_rules('Vars.state.rules.waveTimer=false'), 900, 18);
            break;
        case 'wavetimer-on':
            $result = mindustry_cmd(mindustry_js_rules('Vars.state.rules.waveTimer=true'), 900, 18);
            break;
        case 'waves-off':
            $result = mindustry_cmd(mindustry_js_rules('Vars.state.rules.waves=false'), 900, 18);
            break;
        case 'waves-on':
            $result = mindustry_cmd(mindustry_js_rules('Vars.state.rules.waves=true'), 900, 18);
            break;
        case 'wavesend-off':
            $result = mindustry_cmd(mindustry_js_rules('Vars.state.rules.waveSending=false'), 900, 18);
            break;
        case 'wavesend-on':
            $result = mindustry_cmd(mindustry_js_rules('Vars.state.rules.waveSending=true'), 900, 18);
            break;
        case 'waitenemies-on':
            $result = mindustry_cmd(mindustry_js_rules('Vars.state.rules.waitEnemies=true'), 900, 18);
            break;
        case 'waitenemies-off':
            $result = mindustry_cmd(mindustry_js_rules('Vars.state.rules.waitEnemies=false'), 900, 18);
            break;

        // -------- Sandbox / trampas (rules globales + equipo sharded) --------
        case 'sandbox-full-on':
            $result = mindustry_cmd(mindustry_js_rules(
                'Vars.state.rules.infiniteResources=true;Vars.state.rules.instantBuild=true;'
                . 'Vars.state.rules.buildSpeedMultiplier=12;Vars.state.rules.disableUnitCap=true;'
                . 'var Tx=Packages.mindustry.game.Team.sharded;'
                . 'Vars.state.rules.teams.get(Tx).cheat=true;'
                . 'Vars.state.rules.teams.get(Tx).infiniteResources=true;'
                . 'Vars.state.rules.teams.get(Tx).infiniteAmmo=true'
            ), 1000, 20);
            break;
        case 'sandbox-full-off':
            $result = mindustry_cmd(mindustry_js_rules(
                'Vars.state.rules.infiniteResources=false;Vars.state.rules.instantBuild=false;'
                . 'Vars.state.rules.buildSpeedMultiplier=1;Vars.state.rules.disableUnitCap=false;'
                . 'var Tx=Packages.mindustry.game.Team.sharded;'
                . 'Vars.state.rules.teams.get(Tx).cheat=false;'
                . 'Vars.state.rules.teams.get(Tx).infiniteResources=false;'
                . 'Vars.state.rules.teams.get(Tx).infiniteAmmo=false'
            ), 1000, 20);
            break;
        case 'infinite-resources-on':
            $result = mindustry_cmd(mindustry_js_rules('Vars.state.rules.infiniteResources=true'), 900, 18);
            break;
        case 'infinite-resources-off':
            $result = mindustry_cmd(mindustry_js_rules('Vars.state.rules.infiniteResources=false'), 900, 18);
            break;
        case 'instant-build-on':
            $result = mindustry_cmd(mindustry_js_rules('Vars.state.rules.instantBuild=true'), 900, 18);
            break;
        case 'instant-build-off':
            $result = mindustry_cmd(mindustry_js_rules('Vars.state.rules.instantBuild=false'), 900, 18);
            break;
        case 'buildspeed-fast':
            $result = mindustry_cmd(mindustry_js_rules('Vars.state.rules.buildSpeedMultiplier=48'), 900, 18);
            break;
        case 'buildspeed-normal':
            $result = mindustry_cmd(mindustry_js_rules('Vars.state.rules.buildSpeedMultiplier=1'), 900, 18);
            break;
        case 'unit-unlimited-on':
            $result = mindustry_cmd(mindustry_js_rules('Vars.state.rules.disableUnitCap=true'), 900, 18);
            break;
        case 'unit-unlimited-off':
            $result = mindustry_cmd(mindustry_js_rules('Vars.state.rules.disableUnitCap=false'), 900, 18);
            break;
        case 'team-cheat-on':
            $result = mindustry_cmd(mindustry_js_rules(
                'var Tx=Packages.mindustry.game.Team.sharded;'
                . 'Vars.state.rules.teams.get(Tx).cheat=true;'
                . 'Vars.state.rules.teams.get(Tx).infiniteResources=true;'
                . 'Vars.state.rules.teams.get(Tx).infiniteAmmo=true'
            ), 900, 18);
            break;
        case 'team-cheat-off':
            $result = mindustry_cmd(mindustry_js_rules(
                'var Tx=Packages.mindustry.game.Team.sharded;'
                . 'Vars.state.rules.teams.get(Tx).cheat=false;'
                . 'Vars.state.rules.teams.get(Tx).infiniteResources=false;'
                . 'Vars.state.rules.teams.get(Tx).infiniteAmmo=false'
            ), 900, 18);
            break;

        // -------- Comandos nativos del servidor (wiki) --------
        case 'maps':
            $result = mindustry_cmd('maps', 900, 45);
            break;
        case 'maps-all':
            $result = mindustry_cmd('maps all', 1100, 70);
            break;
        case 'players':
            $result = mindustry_cmd('players', 800, 30);
            break;
        case 'mods':
            $result = mindustry_cmd('mods', 800, 40);
            break;
        case 'rules':
            $result = mindustry_cmd('rules', 1000, 80);
            break;
        case 'fills-core':
            $result = mindustry_cmd('fillitems sharded', 1500, 25);
            break;
        case 'fills-crux':
            $result = mindustry_cmd('fillitems crux', 1500, 25);
            break;
        case 'saves':
            $result = mindustry_cmd('saves', 800, 35);
            break;
        case 'loadautosave':
            $result = mindustry_cmd('loadautosave', 2500, 40);
            break;
        case 'load':
            $slot = (int)($_POST['slot'] ?? 1);
            if ($slot < 1 || $slot > 99) {
                $slot = 1;
            }
            $result = mindustry_cmd("load $slot", 2500, 40);
            break;
        case 'reloadpatches':
            $result = mindustry_cmd('reloadpatches', 1200, 25);
            break;
        case 'shuffle-none':
            $result = mindustry_cmd('shuffle none', 700, 12);
            break;
        case 'shuffle-all':
            $result = mindustry_cmd('shuffle all', 700, 12);
            break;
        case 'shuffle-custom':
            $result = mindustry_cmd('shuffle custom', 700, 12);
            break;
        case 'shuffle-builtin':
            $result = mindustry_cmd('shuffle builtin', 700, 12);
            break;
        case 'help':
            $result = mindustry_cmd('help', 900, 100);
            break;
        case 'gc':
            $result = mindustry_cmd('gc', 800, 15);
            break;

        case 'kick':
            $who = trim((string)($_POST['player_name'] ?? ''));
            $who = preg_replace('/[^\p{L}\p{N} _.\-]/u', '', $who) ?? '';
            $who = trim($who);
            if ($who === '' || strlen($who) > 42) {
                $result = '✗ Nombre de jugador inválido.';
                break;
            }
            $result = mindustry_cmd('kick ' . $who, 800, 20);
            break;

        case 'restart-container':
            $result = container_restart(MINDUSTRY_CONTAINER);
            break;

        default:
            $result = '✗ operación desconocida';
    }

    flash_set('ok', $result, ['op' => $op, 'tail' => 36]);
    header('Location: ' . SELF . '#output');
    exit;
}

$flash = flash_pop();

// --------------------------------------------------------------------------
// Render
// --------------------------------------------------------------------------
$csrf = csrf_token();
$authed = is_authed();
$docker_ok = $authed ? docker_available() : false;
$cstate    = ($authed && $docker_ok) ? container_state(MINDUSTRY_CONTAINER) : ['exists' => false, 'running' => false, 'status' => '?'];
$custom_maps  = ($authed && $docker_ok && $cstate['running']) ? mindustry_list_custom_maps() : [];
$builtin_maps = ($authed && $docker_ok && $cstate['running']) ? mindustry_list_builtin_maps() : [];
$current_game = ($authed && $docker_ok && $cstate['running']) ? mindustry_current_game() : null;
$logs_tail    = 36;
$logs_live = ($authed && $docker_ok && $cstate['running']) ? strip_ansi(container_logs(MINDUSTRY_CONTAINER, $logs_tail, false)) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#1a0f00">
<meta name="description" content="Panel Mindustry: mapa, oleadas, sandbox, consola y ayuda por fila.">
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
  .wrap { max-width:940px; margin:0 auto; padding:18px 16px 60px; }

  header { display:flex; align-items:center; justify-content:space-between; margin:8px 0 22px; gap:12px; flex-wrap:wrap; }
  h1 { font-size:1.08rem; margin:0; letter-spacing:3px; font-weight:600; color:var(--amber);
    text-shadow:0 0 12px rgba(255,179,0,.25); }
  h1 .dim { color:var(--orange); letter-spacing:2px; font-weight:300; }
  .topbar a, .topbar button {
    color:var(--muted); text-decoration:none; font-size:.82rem; padding:7px 11px;
    border:1px solid var(--border); border-radius:10px; background:transparent; cursor:pointer;
    font-family:inherit;
  }
  .topbar { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
  .topbar a:active, .topbar button:active { background:var(--panel2); }

  .status-line { display:flex; gap:10px; align-items:center; margin:-8px 0 14px;
    color:var(--muted); font-size:.78rem; flex-wrap:wrap; }
  .badge { display:inline-flex; align-items:center; gap:6px; padding:3px 9px;
    background:var(--panel); border:1px solid var(--border); border-radius:999px; font-size:.74rem; }
  .badge .dot { width:7px; height:7px; border-radius:50%; background:var(--muted); }
  .badge.on .dot { background:var(--ok); box-shadow:0 0 6px var(--ok); }
  .badge.off .dot { background:var(--danger); }

  .card { background:var(--panel); border:1px solid var(--border); border-radius:14px;
    padding:14px 16px; margin-bottom:12px; }
  .card > h2 { margin:0 0 12px; font-size:.71rem; letter-spacing:2.2px; color:var(--amber);
    text-transform:uppercase; font-weight:600; padding-bottom:6px;border-bottom:1px solid rgba(58,42,15,.65); }
  .card p.hint { color:var(--muted); font-size:.74rem; line-height:1.5; margin:0 0 12px; }
  .card-intro { font-size:.76rem; color:var(--muted); line-height:1.5; padding:10px 12px;
    background:rgba(255,179,0,.07); border:1px solid var(--amber-dim); border-radius:10px; margin-bottom:12px; }
  .card-intro strong { color:var(--amber); }

  select, input[type=text], input[type=number], input[type=password] {
    width:100%; padding:10px 12px; background:var(--panel2); color:var(--text);
    border:1px solid var(--border); border-radius:8px; font-size:.88rem; font-family:inherit;
    appearance:none;
  }
  select { background-image:linear-gradient(45deg,transparent 50%,var(--amber) 50%),
                             linear-gradient(-45deg,transparent 50%,var(--amber) 50%);
           background-position:calc(100% - 16px) center,calc(100% - 11px) center;
           background-size:5px 5px; background-repeat:no-repeat; padding-right:34px; }

  .row { display:flex; gap:8px; margin-bottom:8px; flex-wrap:wrap; align-items:stretch; }
  .row > * { flex:1; min-width:0; }
  .row .narrow { flex:0 0 100px; }

  .btn {
    display:inline-flex; align-items:center; justify-content:center; gap:4px;
    padding:7px 11px; border-radius:8px; font-size:.76rem; font-weight:600;
    border:1px solid var(--border); cursor:pointer; background:var(--panel2);
    color:var(--text); min-height:32px; text-decoration:none; width:auto;
    transition:background .14s;
  }
  .btn[disabled], .btn:disabled { opacity:.45; pointer-events:none; }
  @media (prefers-reduced-motion: no-preference) {
    .btn { transition:transform .07s, background .14s; }
    .btn:active { transform:scale(.985); }
  }
  .btn-primary { background:linear-gradient(180deg,var(--amber),var(--orange));
    color:#1a0f00; border-color:transparent; }
  .btn-amber   { background:var(--amber-dim); border-color:var(--amber); color:var(--amber); }
  .btn-amber:active { background:#3a2c00; }
  .btn-danger  { background:#2a0808; border-color:#5a2a2a; color:#ff8a8a; }
  .btn-ghost   { background:transparent; border-color:var(--border); color:var(--muted); font-weight:500; }

  .btn-wide { width:100%; padding:11px 14px; min-height:40px; font-size:.82rem; }

  details.section-fold { margin-top:8px; border:1px solid var(--border); border-radius:10px;
    padding:2px 12px 8px; background:rgba(0,0,0,.15); margin-bottom:6px;}
  details.section-fold > summary {
    cursor:pointer; list-style:none; padding:11px 0 7px; font-size:.7rem;
    letter-spacing:1.8px; text-transform:uppercase; font-weight:600; color:var(--amber); user-select:none;
  }
  details.section-fold > summary::-webkit-details-marker { display:none; }

  .tool {
    display:grid;
    grid-template-columns:1fr auto;
    gap:8px 12px;
    align-items:flex-start;
    padding:8px 10px;
    background:var(--panel2);
    border:1px solid var(--border);
    border-radius:9px;
    margin-bottom:6px;
  }
  @media (max-width:640px) {
    .tool { grid-template-columns:1fr; }
    .tool-actions { justify-content:flex-start !important; flex-wrap:wrap; }
  }
  .tool-name { font-size:.74rem; font-weight:650; color:var(--text); display:block; line-height:1.28; }
  .tool-desc { font-size:.66rem; color:var(--muted); line-height:1.43; margin-top:5px; display:block; max-width:58ch;}
  .tool-desc code {
    font-size:.9em; background:rgba(0,0,0,.35); padding:0 .3em; border-radius:3px;
    color:#d7c98a;
  }
  .tool-actions { display:flex; flex-wrap:wrap; gap:6px; align-items:center; justify-content:flex-end; }
  .tool-actions form { margin:0; display:inline-flex; }
  .tool.tool-wide-act { grid-template-columns:1fr; }
  .tool.tool-wide-act .tool-actions { justify-content:stretch; }
  .cmd-stack { padding-top:2px;}

  .console {
    background:#050402; color:#d7c98a; padding:12px; border-radius:9px;
    font-family:"SF Mono","Menlo","Consolas",monospace; font-size:.72rem;
    line-height:1.5; white-space:pre-wrap; word-break:break-word; max-height:min(52vh, 380px);
    overflow:auto; border:1px solid var(--border); min-height:100px;
  }
  .console.err { border-color:#5a2a2a; }

  .output-card .op { color:var(--muted); font-size:.71rem; margin-bottom:6px; }

  .login { max-width:360px; margin:16vh auto 0; }
  .login .err { color:var(--danger); margin-top:10px; font-size:.85rem; text-align:center; }
  .login .btn-primary { font-size:.9rem; min-height:44px; padding:12px 16px; }

  .warn { background:#2a1a00; color:#ffb300; padding:12px 14px; border-radius:10px;
    border:1px solid var(--amber-dim); font-size:.78rem; margin-bottom:14px; }
  .warn code { font-size:.88em; }

  footer { text-align:center; color:var(--muted); font-size:.7rem; margin-top:22px; }
  footer a { color:var(--amber); text-decoration:none; }

  ol.learn { margin:6px 0 0 1.25em; padding:0; font-size:.71rem; color:var(--muted); line-height:1.52; max-width:70ch;}
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
        <span>Mapa: <b><?= e($current_game['map']) ?></b> · oleada <?= (int)$current_game['wave'] ?></span>
      </span>
    <?php elseif ($cstate['running']): ?>
      <span class="badge" style="color:var(--muted);">
        <span>○</span>
        <span>Sin partida (host no iniciado)</span>
      </span>
    <?php endif; ?>
    <?php if (!empty($cstate['image'])): ?>
      <span class="badge" title="Imagen Docker"><span style="opacity:.7;">⎔</span> <?= e($cstate['image']) ?></span>
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
      Podés reiniciarlo abajo o desde Gestión Búnker.</div>
  <?php endif; ?>

    <div class="card-intro">
    <strong>Uso rápido del panel:</strong> cada función está en una <em>tarjeta</em>. Ves el <strong>título</strong>,
    una <strong>descripción</strong> (qué comando o regla cambia el servidor) y <strong>botones compactos</strong>.
    Al pasar el cursor sobre un botón aparece también un tooltip. Las secciones plegables agrupan comandos poco habituales.</div>

  <section class="card">
    <h2>Lanzar o cambiar mapa</h2>
    <p class="hint">El mapa *.msav custom tiene que estar en el volumen del servidor (p. ej. <code>config/config/maps/</code>). Tras copiar archivos, usá «Recargar lista de mapas» abajo o reiniciá Docker.</p>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="op" value="host">
      <div class="row">
        <select name="map" aria-label="Mapa">
          <option value="">[Aleatorio del juego]</option>
          <?php if ($custom_maps): ?>
            <optgroup label="Mapas custom">
              <?php foreach ($custom_maps as $m): ?>
                <option value="<?= e($m) ?>"<?= ($current_game && $current_game['map'] === $m) ? ' selected' : '' ?>><?= e($m) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endif; ?>
          <?php if ($builtin_maps): ?>
            <optgroup label="Mapas built-in">
              <?php foreach ($builtin_maps as $m): ?>
                <option value="<?= e($m) ?>"<?= ($current_game && $current_game['map'] === $m) ? ' selected' : '' ?>><?= e(str_replace('_', ' ', $m)) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endif; ?>
        </select>
        <select name="mode" class="narrow" aria-label="Modo de juego">
          <option value="survival" selected>survival</option>
          <option value="attack">attack</option>
          <option value="pvp">pvp</option>
          <option value="sandbox">sandbox</option>
        </select>
      </div>
      <button class="btn btn-primary btn-wide" type="submit">
        <?= $current_game ? '🔄 Aplicar mapa y modo' : '▶ Iniciar servidor de partida' ?>
      </button>
      <?php if ($current_game): ?>
        <p class="hint" style="margin-top:10px;margin-bottom:0;">Ahora mismo: <b><?= e($current_game['map']) ?></b>. Al cambiar mapa se cierra la partida actual.</p>
      <?php endif; ?>
    </form>

    <div class="tool cmd-stack">
      <div>
        <span class="tool-name">Recargar lista de mapas</span>
        <span class="tool-desc">Comando nativo <code>reloadmaps</code>. Hazlo después de subir un .msav nuevo al directorio del servidor sin reiniciar el contenedor.</span>
      </div>
      <div class="tool-actions">
        <form method="POST"><?php /* keep single line csrf */ ?>
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-amber" name="op" value="reloadmaps" title="reloadmaps — relee mapas desde disco">📂 Recargar mapas</button>
        </form>
      </div>
    </div>
  </section>

  <section class="card">
    <h2>Pausa, guardado y fin de partida</h2>
    <div class="tool">
      <div>
        <span class="tool-name">Pausar la simulación</span>
        <span class="tool-desc"><code>pause on</code>. Congela el mundo (construcción, unidades y oleadas).</span>
      </div>
      <div class="tool-actions">
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-amber" name="op" value="pause">⏸ Pausar</button>
        </form>
      </div>
    </div>
    <div class="tool">
      <div>
        <span class="tool-name">Reanudar</span>
        <span class="tool-desc"><code>pause off</code>. Continúa el juego donde estaba.</span>
      </div>
      <div class="tool-actions">
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-amber" name="op" value="resume">▶ Reanudar</button>
        </form>
      </div>
    </div>
    <div class="tool tool-wide-act">
      <div>
        <span class="tool-name">Guardar la partida en un slot</span>
        <span class="tool-desc"><code>save N</code> (1–9). Creá puntos de restauración dentro del servidor; no sustituye al archivo .msav del mapa.</span>
      </div>
      <div class="tool-actions">
        <form method="POST" style="display:flex;gap:8px;width:100%;align-items:center;flex-wrap:wrap;">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <select name="slot" class="narrow" style="max-width:88px;margin:0;" aria-label="Slot">
            <?php for ($s = 1; $s <= 9; $s++): ?>
              <option value="<?= $s ?>"<?= $s === 1 ? ' selected' : '' ?>><?= $s ?></option>
            <?php endfor; ?>
          </select>
          <button class="btn btn-amber" style="flex:1;min-width:140px;" name="op" value="save">💾 Guardar</button>
        </form>
      </div>
    </div>
    <div class="tool">
      <div>
        <span class="tool-name">Detener el host (<code>stop</code>)</span>
        <span class="tool-desc">Cierra la sesión multiplayer; los jugadores quedan desconectados. El contenedor Docker sigue arriba.</span>
      </div>
      <div class="tool-actions">
        <form method="POST" onsubmit="return confirm('¿Cerrar la partida y desconectar a todos?');">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-danger" name="op" value="stop">⛔ Stop</button>
        </form>
      </div>
    </div>
    <div class="tool">
      <div>
        <span class="tool-name">Forzar victoria / derrota (<code>gameover</code>)</span>
        <span class="tool-desc">Termina la oleada/objetivos según las reglas actuales; distinto de «Stop».</span>
      </div>
      <div class="tool-actions">
        <form method="POST" onsubmit="return confirm('¿Terminar la partida (game over)?');">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-danger" name="op" value="gameover">☠ Game over</button>
        </form>
      </div>
    </div>
  </section>

  <section class="card">
    <h2>Mensajes, estado y datos del servidor</h2>
    <div class="tool">
      <div>
        <span class="tool-name">Resumen de la máquina de juego</span>
        <span class="tool-desc"><code>status</code>. Muestra mapa actual, número de oleada, jugadores online y unidades enemigas.</span>
      </div>
      <div class="tool-actions">
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-primary" name="op" value="status">📊 Status</button>
        </form>
      </div>
    </div>
    <div class="tool">
      <div>
        <span class="tool-name">Versión del ejecutable Mindustry</span>
        <span class="tool-desc"><code>version</code>. Útil tras actualizar Docker o comparar mods.</span>
      </div>
      <div class="tool-actions">
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-ghost" name="op" value="version">ℹ Versión</button>
        </form>
      </div>
    </div>
    <div class="tool">
      <div>
        <span class="tool-name">Actualizar lista de mapas built-in en este panel web</span>
        <span class="tool-desc">Vuelve a ejecutar <code>maps all</code> dentro del servidor y regenera la caché del panel (no cambia Mindustry).</span>
      </div>
      <div class="tool-actions">
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-ghost" name="op" value="reloadbuiltin">↻ Refrescar built-in</button>
        </form>
      </div>
    </div>
    <form method="POST" style="margin-top:10px;">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="op" value="say">
      <div class="tool tool-wide-act" style="margin-bottom:10px;display:block;background:transparent;border-style:dashed;">
        <div>
          <span class="tool-name">Mensaje rojo visible para todos</span>
          <span class="tool-desc"><code>say texto</code>. Prefijo automático «[Server]». Un solo renglón; sin saltos ni caracteres extraños por seguridad.</span>
        </div>
      </div>
      <div class="row">
        <input type="text" name="message" placeholder="Ej.: Reinicio del servidor en 5 minutos" maxlength="200" required>
      </div>
      <button class="btn btn-primary" style="margin-top:6px;">📢 Enviar mensaje</button>
    </form>
  </section>

  <section class="card">
    <h2>Archivo del mapa (spawns y .msav)</h2>
    <div class="tool">
      <div>
        <span class="tool-name">Reparar puntos donde aparecen enemigos</span>
        <span class="tool-desc">Si las oleadas no traen tropas suele haber «Spawns=0». Esta acción añade 4 overlays de spawn en el borde y resetea la lista (<code>js</code> en servidor). Después puede convenir usar «Persistir».</span>
      </div>
      <div class="tool-actions">
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-primary" name="op" value="fix-spawns">🎯 Reparar spawns</button>
        </form>
      </div>
    </div>
    <div class="tool">
      <div>
        <span class="tool-name">Guardar este mapa en disco (.msav)</span>
        <span class="tool-desc">Ejecuta <code>Maps.saveMap</code> dentro del proceso: sobrescribe el archivo del mismo nombre meta e incluye <strong>bloques + unidades + estado</strong> actual. Probá hacerlo sin oleadas frenéticas ni jugadores realizando grandes cambios.</span>
      </div>
      <div class="tool-actions">
        <form method="POST" onsubmit="return confirm('¿Sobreescribir el .msav del mapa en curso?');">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-amber" name="op" value="save-custom-map">💾 Persistir mapa (.msav)</button>
        </form>
      </div>
    </div>
  </section>

  <section class="card">
    <h2>Oleadas y tiempo entre oleadas</h2>
    <p class="hint">Aquí todas las opciones modifican las reglas de la sesión mediante <code>js</code> y <code>Call.setRules</code>. Habla con tus jugadores si cambias el ritmo.</p>
    <div class="tool">
      <div>
        <span class="tool-name">Disparar la siguiente oleada</span>
        <span class="tool-desc"><code>runwave</code>. Equivale a convocar oleada manual desde el juego. Los enemigos ya en el mapa siguen vivos.</span>
      </div>
      <div class="tool-actions">
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-primary" name="op" value="runwave">🌊 Ejecutar oleada</button>
        </form>
      </div>
    </div>
    <div class="tool">
      <div>
        <span class="tool-name">Temporizador automático (<code>waveTimer</code>)</span>
        <span class="tool-desc"><strong>Apagar:</strong> el juego ya no lanzará nuevas oleadas sólo porque pase el tiempo (no es pausa absoluta si sigue <code>waveSending</code>). <strong>Encender:</strong> vuelve el comportamiento habitual.</span>
      </div>
      <div class="tool-actions">
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-danger" name="op" value="wavetimer-off">Apagar cronómetro</button>
        </form>
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-amber" name="op" value="wavetimer-on">Encender cronómetro</button>
        </form>
      </div>
    </div>
    <div class="tool">
      <div>
        <span class="tool-name">Sistema completo de oleadas (<code>waves</code>)</span>
        <span class="tool-desc"><strong>Desactivar</strong> apaga por completo la lógica de oleadas. Si solo querés frenar el tiempo automático, mejor usá «Apagar cronómetro» más arriba.</span>
      </div>
      <div class="tool-actions">
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-danger" name="op" value="waves-off">Desactivar oleadas</button>
        </form>
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-amber" name="op" value="waves-on">Activar oleadas</button>
        </form>
      </div>
    </div>
    <div class="tool">
      <div>
        <span class="tool-name">Botón manual del jugador (<code>waveSending</code>)</span>
        <span class="tool-desc"><strong>Bloquear</strong>: ya no pueden convocar oleada tocando ▶ desde el cliente. <strong>Permitir</strong>: restablece ese control como en modo normal.</span>
      </div>
      <div class="tool-actions">
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-danger" name="op" value="wavesend-off">Sin convocatoria manual</button>
        </form>
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-amber" name="op" value="wavesend-on">Permitir convocatoria manual</button>
        </form>
      </div>
    </div>
    <div class="tool">
      <div>
        <span class="tool-name">Exigir acabar oleada actual antes de avanzar (<code>waitEnemies</code>)</span>
        <span class="tool-desc">Encendido, el temporizador de oleadas no avanza hasta que no queden enemigos en el mapa; apagado, la cuenta sigue con enemigos vivos si el modo lo permite.</span>
      </div>
      <div class="tool-actions">
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-amber" name="op" value="waitenemies-on">Activar espera</button>
        </form>
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-ghost" name="op" value="waitenemies-off">Desactivar espera</button>
        </form>
      </div>
    </div>
  </section>

  <section class="card">
    <h2>Sandbox / trampas (solo host)</h2>
    <div class="card-intro">
      Activa recursos gratis, velocidad brutal o trampas específicas del equipo <strong>sharded</strong>. Los cambios llegan por red a los clientes porque se llama <code>Call.setRules</code>.
    </div>
    <div class="tool">
      <div>
        <span class="tool-name">Pack combinado («modo práctica»)</span>
        <span class="tool-desc">Encender activa infiniteResources + instantBuild + alta velocidad de construcción + sin techo de unidades + cheat para el equipo jugador.<br />
        Apagar intenta revertir cada valor anterior a valores «normales» por defecto.</span>
      </div>
      <div class="tool-actions">
        <form method="POST" onsubmit="return confirm('¿Activar el pack sandbox completo?');">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-primary" name="op" value="sandbox-full-on">Pack ON</button>
        </form>
        <form method="POST" onsubmit="return confirm('¿Quitar trampas combinadas del pack?');">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-ghost" name="op" value="sandbox-full-off">Pack OFF</button>
        </form>
      </div>
    </div>
    <div class="tool">
      <div>
        <span class="tool-name">Recursos globales gratis (<code>infiniteResources</code>)</span>
        <span class="tool-desc">Todos los jugadores construyen como en sandbox oficial; combiná con equipo cheat sólo si lo necesitas.</span>
      </div>
      <div class="tool-actions">
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-amber" name="op" value="infinite-resources-on">ON</button>
        </form>
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-ghost" name="op" value="infinite-resources-off">OFF</button>
        </form>
      </div>
    </div>
    <div class="tool">
      <div>
        <span class="tool-name">Construcción instantánea (<code>instantBuild</code>)</span>
        <span class="tool-desc">Los bloques quedan al instante (sin barra ni límite de construcciones/seg).</span>
      </div>
      <div class="tool-actions">
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-amber" name="op" value="instant-build-on">ON</button>
        </form>
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-ghost" name="op" value="instant-build-off">OFF</button>
        </form>
      </div>
    </div>
    <div class="tool">
      <div>
        <span class="tool-name">Multiplicador de velocidad al construir</span>
        <span class="tool-desc"><code>buildSpeedMultiplier</code>: el perfil <strong>×48</strong> es muy veloz pero no infinito; <strong>normal</strong> vuelve el multiplicador a 1.</span>
      </div>
      <div class="tool-actions">
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-amber" name="op" value="buildspeed-fast">×48 rápido</button>
        </form>
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-ghost" name="op" value="buildspeed-normal">Normal (×1)</button>
        </form>
      </div>
    </div>
    <div class="tool">
      <div>
        <span class="tool-name">Límite global de unidades</span>
        <span class="tool-desc"><code>disableUnitCap</code>. ON permite infinitud de unidades a nivel servidor; OFF restaura límites del mapa/bloques.</span>
      </div>
      <div class="tool-actions">
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-amber" name="op" value="unit-unlimited-on">Sin tope ON</button>
        </form>
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-ghost" name="op" value="unit-unlimited-off">Sin tope OFF</button>
        </form>
      </div>
    </div>
    <div class="tool">
      <div>
        <span class="tool-name">«Cheat» del equipo jugador base</span>
        <span class="tool-desc">Altera sólo Team <code>sharded</code>: edificios no consumen recursos/eléctrica al construir más allá del modo sandbox; ammo infinitas en ese equipo también.</span>
      </div>
      <div class="tool-actions">
        <form method="POST" onsubmit="return confirm('¿Activar cheat sharded para pruebas?');">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-amber" name="op" value="team-cheat-on">Cheat sharded ON</button>
        </form>
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-ghost" name="op" value="team-cheat-off">Cheat OFF</button>
        </form>
      </div>
    </div>
  </section>

  <details class="section-fold">
    <summary>Guardados · listas · parches · rotación aleatoria de mapas</summary>
    <div class="cmd-stack">
    <div class="tool">
      <div>
        <span class="tool-name">Llenar el núcleo de items hasta el máximo</span>
        <span class="tool-desc"><code>fillitems equipo</code>. Para tu facción habitual se usa team <strong>sharded</strong>; <strong>crux</strong> es el rojo típico de oleadas IA.</span>
      </div>
      <div class="tool-actions">
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-amber" name="op" value="fills-core">Jugadores (sharded)</button>
        </form>
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-ghost" name="op" value="fills-crux">Crux</button>
        </form>
      </div>
    </div>
    <div class="tool">
      <div>
        <span class="tool-name">Inventarios de *.msav en carpeta servidor</span>
        <span class="tool-desc"><code>maps</code> muestra sólo customs; <code>maps all</code> también built‑in; es salida sólo texto en el panel.</span>
      </div>
      <div class="tool-actions">
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-ghost" name="op" value="maps">Custom</button>
        </form>
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-ghost" name="op" value="maps-all">Todos</button>
        </form>
      </div>
    </div>
    <div class="tool">
      <div>
        <span class="tool-name">Listar ficheros *.msav de partidas guardadas</span>
        <span class="tool-desc"><code>saves</code>. Después usar «Cargar slot» cuando coincidamos el número con el servidor.</span>
      </div>
      <div class="tool-actions">
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-ghost" name="op" value="saves">Saves disponibles</button>
        </form>
      </div>
    </div>
    <div class="tool">
      <div>
        <span class="tool-name">Carga rápida de respaldo Mindustry (<code>loadautosave</code>)</span>
        <span class="tool-desc">Revierte al último auto‑save escrito por Mindustry (suele usarse ante fallos / respaldos). Reemplaza el estado multiplayer actual sin confirmación dentro del motor.</span>
      </div>
      <div class="tool-actions">
        <form method="POST" onsubmit="return confirm('¿Sobrescribir la sesión desde autosave más reciente del servidor?');">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-danger" name="op" value="loadautosave">↩ Auto‑save más reciente</button>
        </form>
      </div>
    </div>
    <div class="tool tool-wide-act">
      <div>
        <span class="tool-name">Cargar un slot numérico concreto</span>
        <span class="tool-desc"><code>load N</code>. Consultá antes «Saves disponibles» para conocer los números válidos.</span>
      </div>
      <div class="tool-actions">
        <form method="POST" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;width:100%;" onsubmit="return confirm('¿CARGAR SAVE? Esta acción suele tirar jugadores hasta completar snapshot.');">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="op" value="load">
          <input type="number" name="slot" min="1" max="99" value="1" style="width:74px;margin:0;" aria-label="Número de slot save">
          <button class="btn btn-danger" type="submit" style="flex:1;">↩ Ejecutar load</button>
        </form>
      </div>
    </div>
    <div class="tool">
      <div>
        <span class="tool-name"><code>reloadpatches</code></span>
        <span class="tool-desc">Vuelve a leer archivos patch del juego instalado; casi sólo tiene sentido cuando administrás servidor con mods grandes.</span>
      </div>
      <div class="tool-actions">
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-ghost" name="op" value="reloadpatches">Recargar parches del juego</button>
        </form>
      </div>
    </div>
    <div class="tool">
      <div>
        <span class="tool-name"><code>shuffle</code> — cómo se elige el próximo mapa tras game over</span>
        <span class="tool-desc"><strong>none</strong>: no cambia aleatoriamente el siguiente mapa salvo configuración posterior.<br />
<strong>all</strong>: permite cualquier mapa válido.<br />
<strong>custom</strong>: sólo tus <code>.msav</code> externos.<br />
<strong>builtin</strong>: sólo los mapas dentro del ejecutable oficial.</span>
      </div>
      <div class="tool-actions" style="max-width:100%;justify-content:flex-start;">
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-ghost" name="op" value="shuffle-none">none</button>
        </form>
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-ghost" name="op" value="shuffle-all">all</button>
        </form>
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-ghost" name="op" value="shuffle-custom">custom</button>
        </form>
        <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-ghost" name="op" value="shuffle-builtin">builtin</button>
        </form>
      </div>
    </div>
    </div>
  </details>

  <details class="section-fold">
    <summary>Jugadores online · herramientas de diagnóstico</summary>
    <div class="cmd-stack">
      <div class="tool tool-wide-act">
        <div>
          <span class="tool-name"><code>kick nombre</code> — expulsar a alguien conectado</span>
          <span class="tool-desc">Escribí el nick igual que muestra Mindustry («Lista» antes para copiar texto real). No ejecuta bans.</span>
        </div>
        <div class="tool-actions stack-input">
          <form method="POST" style="display:flex;gap:8px;width:100%;flex-wrap:wrap;">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="op" value="kick">
            <input type="text" name="player_name" placeholder="Nombre exactamente igual al del juego" maxlength="42" required style="flex:2;margin:0;min-width:120px;">
            <button class="btn btn-danger" type="submit">Expulsar</button>
          </form>
        </div>
      </div>
      <div class="tool">
        <div>
          <span class="tool-name"><code>players</code></span>
          <span class="tool-desc">Listado rápido con slots y equipo.</span>
        </div>
        <div class="tool-actions">
          <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <button class="btn btn-amber" name="op" value="players">👥 Lista</button>
          </form>
        </div>
      </div>
      <div class="tool">
        <div>
          <span class="tool-name"><code>mods</code> y <code>rules</code></span>
          <span class="tool-desc"><strong>Mods</strong> lista plugins cargados; <strong>rules</strong> muestra JSON de reglas globales persistentes (no confundir con trampas rápidas de arriba).</span>
        </div>
        <div class="tool-actions">
          <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <button class="btn btn-ghost" name="op" value="mods">Mods</button>
          </form>
          <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <button class="btn btn-ghost" name="op" value="rules">Reglas persistidas JSON</button>
          </form>
        </div>
      </div>
      <div class="tool">
        <div>
          <span class="tool-name"><code>help</code> y <code>gc</code></span>
          <span class="tool-desc"><strong>help</strong> lista todos los comandos en inglés. <strong>gc</strong> fuerza recolector JVM (solo pruebas, puede provoc microcortas de CPU).</span>
        </div>
        <div class="tool-actions">
          <form method="POST"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <button class="btn btn-ghost" name="op" value="help">Lista completa (help)</button>
          </form>
          <form method="POST" onsubmit="return confirm('¿Forzar garbage collection sólo servidor?');">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <button class="btn btn-ghost" name="op" value="gc">Garbage collect</button>
          </form>
        </div>
      </div>
    </div>
  </details>


  <?php
    $flash_tail = (int)($flash['tail'] ?? 28);
    $flash_err = $flash && (str_starts_with((string)($flash['msg'] ?? ''), '✗') || str_contains((string)($flash['msg'] ?? ''), '✗ No'));
  ?>
  <?php if ($flash): ?>
  <section class="card output-card" id="output">
    <h2>Salida · <?= e($flash['op'] ?? '') ?></h2>
    <div class="op">Fragmento reciente del log del servidor (comando inyectado por FIFO)</div>
    <div class="console<?= $flash_err ? ' err' : '' ?>"><?= e($flash['msg']) ?></div>
  </section>
  <?php endif; ?>

  <section class="card">
    <h2>Consola en vivo <span style="color:var(--muted);font-weight:400;font-size:.7rem;">· últimas <?= (int)$logs_tail ?> líneas</span></h2>
    <div class="console"><?= e($logs_live !== '' ? $logs_live : '(contenedor no corriendo)') ?></div>
    <div style="margin-top:10px;display:flex;gap:8px;justify-content:flex-end;">
      <a class="btn btn-ghost" style="width:auto;padding:8px 14px;min-height:auto;" href="<?= e(SELF) ?>">↻ Refrescar</a>
    </div>
  </section>

  <section class="card">
    <h2>Container Docker</h2>
    <div class="tool">
      <div>
        <span class="tool-name">Reiniciar el proceso <code>mindustry_server</code></span>
        <span class="tool-desc">Equivalente a <code>docker restart</code>. Corta todas las partidas y vacía el mundo en memoria hasta que vuelvas a hostear.</span>
      </div>
      <div class="tool-actions">
        <form method="POST" onsubmit="return confirm('¿Reiniciar mindustry_server? Los jugadores pierden conexión.');">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-danger" name="op" value="restart-container" title="docker restart mindustry_server">♻ Reiniciar container</button>
        </form>
      </div>
    </div>
  </section>

  <footer>
    <?= e(MINDUSTRY_CONTAINER) ?> · puerto 6567 TCP/UDP · <?= date('Y-m-d H:i') ?><br>
    <a href="admin_server.php">← Gestión Búnker</a>
  </footer>

<?php endif; ?>

</div>
</body>
</html>

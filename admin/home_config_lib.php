<?php
declare(strict_types=1);

/**
 * Home-page configuration helpers.
 * Data stored in data/home_config.json
 * (shared between containers via compartido/ Docker volume).
 */

function home_config_path(): string
{
    return __DIR__ . '/data/home_config.json';
}

function home_config_defaults(): array
{
    return [
        /* ── Core identity ──────────────────────────────── */
        'site_title'     => 'SEBAJI',
        'site_tagline'   => 'El Despertar',

        /* ── Share / social links ───────────────────────── */
        'share_ws_url'   => '',
        'share_tg_url'   => '',
        'share_yt_url'   => '',

        /* ── Audio player ───────────────────────────────── */
        'player_idle'    => '◈ SELECCIONA UNA OFRENDA ◈',
        'btn_continuous' => 'Sintonía Continua',
        'btn_random'     => 'Azar Sagrado',

        /* ── Chat ───────────────────────────────────────── */
        'chat_ph_name'   => 'Tu nombre',
        'chat_ph_msg'    => 'Comparte tu sentir...',
        'chat_btn'       => 'Elevar Pensamiento',

        /* ── Medicine navigation cards ──────────────────── */
        'med_ayu_title'  => 'AYAHUASCA',
        'med_ayu_sub'    => 'La Soga del Alma',
        'med_rape_title' => 'RAPÉ',
        'med_rape_sub'   => 'El Soplo Sagrado',
    ];
}

function home_config_load(): array
{
    $def  = home_config_defaults();
    $path = home_config_path();
    if (!is_readable($path)) {
        return $def;
    }
    $json = json_decode((string) file_get_contents($path), true);
    if (!is_array($json)) {
        return $def;
    }
    foreach ($def as $k => $v) {
        if (isset($json[$k])) {
            $def[$k] = (string) $json[$k];
        }
    }
    return $def;
}

function home_config_save(array $cfg): void
{
    $dir = dirname(home_config_path());
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $clean = [];
    foreach (home_config_defaults() as $k => $v) {
        $clean[$k] = isset($cfg[$k]) ? (string) $cfg[$k] : $v;
    }
    file_put_contents(
        home_config_path(),
        json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

/**
 * Applies only the hcfg_* POST values that are present onto the
 * existing saved config (partial-save — other keys stay untouched).
 */
function home_config_update_from_post(): void
{
    $cfg = home_config_load();
    foreach (array_keys(home_config_defaults()) as $k) {
        $pk = 'hcfg_' . $k;
        if (array_key_exists($pk, $_POST)) {
            $cfg[$k] = (string) $_POST[$pk];
        }
    }
    home_config_save($cfg);
}

/* ── Visit counter ─────────────────────────────────────────────── */

function visitas_path(): string
{
    return __DIR__ . '/data/visitas.txt';
}

function visitas_read(): int
{
    $p = visitas_path();
    return is_readable($p) ? max(0, (int) trim((string) file_get_contents($p))) : 0;
}

function visitas_write(int $n): void
{
    $dir = dirname(visitas_path());
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    file_put_contents(visitas_path(), (string) max(0, $n));
}

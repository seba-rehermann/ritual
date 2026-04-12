<?php
/**
 * Configuración y secretos.
 * Definir las variables de entorno en docker-compose.yml (sección environment:)
 * para no hardcodear valores reales en el código.
 *
 * Variables de entorno disponibles:
 *   SEBAJI_YOUTUBE_CANAL_URL   — URL del canal de YouTube
 *   SEBAJI_ADMIN_PASSWORD      — Contraseña del panel admin
 *   TELEGRAM_BOT_TOKEN         — Token del bot de Telegram
 *   TELEGRAM_CHAT_ID           — Chat ID para notificaciones
 */

$nombre_sitio = 'Sebaji';

$enlace_youtube_canal = getenv('SEBAJI_YOUTUBE_CANAL_URL') ?: 'https://www.youtube.com/@sebajiyou';

$password_admin = getenv('SEBAJI_ADMIN_PASSWORD') ?: 'ritual';

$bot_token = getenv('TELEGRAM_BOT_TOKEN') ?: '';
$chat_id   = getenv('TELEGRAM_CHAT_ID')   ?: '';

/**
 * Notifica por Telegram. No hace nada si no hay credenciales configuradas.
 */
function avisarTelegram(string $msg, string $token, string $id): void
{
    if ($token === '' || $id === '') {
        return;
    }
    $cmd = 'curl -s -X POST https://api.telegram.org/bot' . $token
         . '/sendMessage -d chat_id=' . $id
         . ' -d text=' . escapeshellarg($msg);
    exec($cmd . ' > /dev/null &');
}

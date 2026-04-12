<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/medicina_page_lib.php';

$medicina_slug = 'rape';
$data = medicina_page_load($medicina_slug);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rapé: El Soplo Sagrado</title>
    <style>
        :root { --earth: #8d6e63; --clay: #a1887f; --sand: #fdf5e6; --deep-brown: #4a3728; }
        body { font-family: 'Segoe UI', serif; background-color: var(--sand); color: var(--deep-brown); margin: 0; line-height: 1.8; opacity: 0; animation: fadeInPage 2.5s forwards; }
        @keyframes fadeInPage { from { opacity: 0; } to { opacity: 1; } }
        .contenedor { max-width: 1100px; margin: auto; padding: 60px 20px; }
        .header-rape { text-align: center; border-bottom: 2px solid var(--clay); padding-bottom: 40px; margin-bottom: 50px; }
        .header-rape h1 { font-size: 3.5em; letter-spacing: 12px; color: var(--earth); text-transform: uppercase; }
        .info-profunda { display: grid; grid-template-columns: 1fr 1fr; gap: 50px; background: rgba(255,255,255,0.6); padding: 50px; border-radius: 20px; margin-bottom: 60px; text-align: justify; }
        .venta-box { background: white; border: 2px solid var(--clay); border-radius: 16px; padding: 32px 40px; margin-bottom: 50px; text-align: center; }
        .grid-medios { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
        .medio-card { background: white; padding: 10px; border-radius: 10px; border: 1px solid #eee; }
        .medio-card img, .medio-card video { width: 100%; height: 280px; object-fit: cover; border-radius: 6px; display: block; }
        .medio-caption { margin-top: 10px; font-size: 0.88em; text-align: center; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/menu.php'; ?>
    <div class="contenedor">
        <header class="header-rape">
            <h1>RAPÉ</h1>
            <p>MEDICINA SAGRADA · EL SOPLO DEL GRAN ESPÍRITU</p>
        </header>
        <section class="info-profunda">
            <div class="bloque"><p><?php echo nl2br(htmlspecialchars($data['intro'][0] ?? '')); ?></p></div>
            <div class="bloque"><p><?php echo nl2br(htmlspecialchars($data['intro'][1] ?? '')); ?></p></div>
        </section>
        <section class="venta-box">
            <h2><?php echo htmlspecialchars($data['cta_title'] ?? ''); ?></h2>
            <p><?php echo nl2br(htmlspecialchars($data['cta_text'] ?? '')); ?></p>
        </section>
        <div class="grid-medios">
            <?php
            foreach ($data['items'] as $row) {
                if (!is_file(__DIR__ . '/' . $row['path'])) {
                    continue;
                }
                $src = htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8');
                echo '<div class="medio-card">';
                if (str_ends_with($src, '.mp4') || str_ends_with($src, '.webm')) {
                    echo "<video controls src=\"$src\" preload=\"metadata\"></video>";
                } else {
                    echo "<img src=\"$src\" loading=\"lazy\" alt=\"\">";
                }
                if (!empty($row['caption'])) {
                    echo '<div class="medio-caption">' . htmlspecialchars($row['caption']) . '</div>';
                }
                echo '</div>';
            }
            ?>
        </div>
    </div>
    <footer style="text-align: center; padding: 40px; color: var(--clay);">
        &copy; <?php echo date('Y'); ?> — Sebaji
    </footer>
</body>
</html>

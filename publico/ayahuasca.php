<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/medicina_page_lib.php';

$medicina_slug = 'ayahuasca';
$data = medicina_page_load($medicina_slug);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ayahuasca: La Soga del Alma</title>
    <style>
        :root { --forest: #1b3022; --leaf: #4e6e5d; --gold: #d4c3a3; --bg: #f0ede4; --dark-green: #0a1f12; }
        body { font-family: Georgia, 'Times New Roman', serif; background-color: var(--bg); color: var(--dark-green); margin: 0; line-height: 1.8; opacity: 0; animation: fadeInPage 2s forwards; }
        @keyframes fadeInPage { from { opacity: 0; } to { opacity: 1; } }
        .contenedor { max-width: 1100px; margin: auto; padding: 60px 20px; }
        .hero { text-align: center; margin-bottom: 50px; }
        .hero h1 { font-size: 3.5em; font-weight: 300; letter-spacing: 5px; color: var(--forest); }
        .texto-sagrado { background: white; padding: 50px; border-radius: 5px; border-left: 4px solid var(--leaf); margin-bottom: 50px; text-align: justify; }
        .venta-box { background: white; border: 2px solid var(--leaf); border-radius: 12px; padding: 32px 40px; margin-bottom: 50px; text-align: center; }
        .grid-medios { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 25px; }
        .medio-card { background: white; padding: 15px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .medio-card img, .medio-card video { width: 100%; height: 250px; object-fit: cover; border-radius: 4px; display: block; }
        .medio-caption { margin-top: 12px; font-size: 0.88em; text-align: center; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/menu.php'; ?>
    <div class="contenedor">
        <header class="hero">
            <h1>AYAHUASCA</h1>
            <p>«La medicina que despierta la visión interna»</p>
        </header>
        <section class="texto-sagrado">
            <p><?php echo nl2br(htmlspecialchars($data['intro'][0] ?? '')); ?></p>
            <p><?php echo nl2br(htmlspecialchars($data['intro'][1] ?? '')); ?></p>
        </section>
        <section class="venta-box">
            <h2><?php echo htmlspecialchars($data['cta_title'] ?? ''); ?></h2>
            <p><?php echo nl2br(htmlspecialchars($data['cta_text'] ?? '')); ?></p>
        </section>
        <div class="grid-medios">
            <?php
            foreach ($data['items'] as $row) {
                if (!is_file(__DIR__ . '/' . $row['path'])) continue;
                $src = htmlspecialchars($row['path']);
                echo '<div class="medio-card">';
                if (str_ends_with($src, '.mp4') || str_ends_with($src, '.webm')) {
                    echo "<video controls src=\"$src\" preload=\"metadata\"></video>";
                } else {
                    echo "<img src=\"$src\" loading=\"lazy\" alt=\"\">";
                }
                if (!empty($row['caption'])) echo '<div class="medio-caption">' . htmlspecialchars($row['caption']) . '</div>';
                echo '</div>';
            }
            ?>
        </div>
    </div>
    <footer style="text-align: center; padding: 40px; color: #666;">
        &copy; <?php echo date('Y'); ?> — Sebaji
    </footer>
</body>
</html>

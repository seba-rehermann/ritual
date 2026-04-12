<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/medicina_page_lib.php';

$medicina_slug = 'rape';
$data = medicina_page_load($medicina_slug);

/* Build gallery HTML + lightbox data in one pass */
$lb_items     = [];
$gallery_html = '';

foreach ($data['items'] as $row) {
    if (!is_file(__DIR__ . '/' . $row['path'])) continue;

    $ext      = strtolower(pathinfo($row['path'], PATHINFO_EXTENSION));
    $is_video = in_array($ext, ['mp4', 'webm'], true);
    $src      = htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8');
    $cap      = htmlspecialchars($row['caption'] ?? '', ENT_QUOTES, 'UTF-8');
    $lb_idx   = count($lb_items);

    $lb_items[] = [
        'src'     => $row['path'],
        'caption' => $row['caption'] ?? '',
        'type'    => $is_video ? 'video' : 'image',
    ];

    $label      = $is_video ? 'Reproducir video' : 'Ver imagen en grande';
    $item_class = 'gallery-item' . ($is_video ? ' is-video' : '');

    $gallery_html .= '<figure class="' . $item_class . '" data-lb-index="' . $lb_idx . '"'
                  . ' tabindex="0" role="button" aria-label="' . $label . '">';
    $gallery_html .= '<div class="gallery-thumb">';

    if ($is_video) {
        $gallery_html .= '<video src="' . $src . '" preload="metadata" muted></video>';
        $gallery_html .= '<div class="gallery-play-badge"><div class="gallery-play-btn"></div></div>';
    } else {
        $gallery_html .= '<img src="' . $src . '" loading="lazy" alt="' . $cap . '">';
    }

    $gallery_html .= '<div class="gallery-thumb-overlay"></div>';
    $gallery_html .= '</div>';

    if ($cap !== '') {
        $gallery_html .= '<figcaption>' . $cap . '</figcaption>';
    }

    $gallery_html .= '</figure>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rapé: El Soplo Sagrado</title>
    <link rel="stylesheet" href="medicina-page.css">
    <style>
        /* ── Rapé palette — deep earth / terracotta / warm cream ── */
        :root {
            --med-bg:            #0c0806;
            --med-bg2:           #130f0b;
            --med-surface:       rgba(141, 110, 99, 0.07);
            --med-border:        rgba(161, 136, 127, 0.18);
            --med-border-bright: rgba(161, 136, 127, 0.48);
            --med-accent:        #a07060;
            --med-text:          #e8d9c4;
            --med-text-dim:      rgba(232, 217, 196, 0.50);
            --med-glow:          rgba(160, 112, 96, 0.22);
            --med-glow-strong:   rgba(160, 112, 96, 0.52);
        }
    </style>
</head>
<body>

    <?php include __DIR__ . '/menu.php'; ?>

    <!-- ── HERO ───────────────────────────────────────── -->
    <header class="med-hero">
        <div class="med-hero-rule"></div>
        <h1>RAP&Eacute;</h1>
        <div class="med-hero-rule"></div>
        <p class="med-hero-sub">«El soplo que devuelve el guerrero a su centro»</p>
    </header>

    <!-- ── INTRO TEXT ─────────────────────────────────── -->
    <?php if (!empty($data['intro'][0]) || !empty($data['intro'][1])): ?>
    <section class="med-section med-intro">
        <div class="med-section-narrow">
            <?php if (!empty($data['intro'][0])): ?>
            <p><?php echo nl2br(htmlspecialchars($data['intro'][0])); ?></p>
            <?php endif; ?>
            <?php if (!empty($data['intro'][1])): ?>
            <p><?php echo nl2br(htmlspecialchars($data['intro'][1])); ?></p>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- ── CTA ────────────────────────────────────────── -->
    <?php if (!empty($data['cta_title']) || !empty($data['cta_text'])): ?>
    <section class="med-section">
        <div class="med-section-narrow">
            <div class="med-cta">
                <?php if (!empty($data['cta_title'])): ?>
                <h2><?php echo htmlspecialchars($data['cta_title']); ?></h2>
                <?php endif; ?>
                <?php if (!empty($data['cta_text'])): ?>
                <p><?php echo nl2br(htmlspecialchars($data['cta_text'])); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ── GALLERY ────────────────────────────────────── -->
    <?php if ($gallery_html !== ''): ?>
    <section class="med-section med-gallery">
        <div class="med-section-wide">
            <h3 class="med-section-title">Galería</h3>
            <div class="gallery-grid">
                <?php echo $gallery_html; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ── BOTTOM NAVIGATION ──────────────────────────── -->
    <nav class="med-bottom-nav" aria-label="Navegación entre medicinas">
        <p class="med-bottom-nav-label">Continúa el camino</p>
        <div class="med-bottom-nav-links">
            <a href="index.php" class="med-nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                    <path d="M19 12H5M5 12l7-7M5 12l7 7"/>
                </svg>
                Inicio
            </a>
            <a href="ayahuasca.php" class="med-nav-link">
                Ayahuasca
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                    <path d="M5 12h14M14 5l7 7-7 7"/>
                </svg>
            </a>
        </div>
    </nav>

    <footer class="med-footer">
        &copy; <?php echo date('Y'); ?> &mdash; Sebaji
    </footer>

    <!-- ── LIGHTBOX ───────────────────────────────────── -->
    <div id="lb" class="lb" role="dialog" aria-modal="true" aria-label="Visor de medios">
        <div class="lb-backdrop"></div>
        <div class="lb-stage">
            <div class="lb-media-wrap" id="lb-media"></div>
            <p class="lb-caption" id="lb-caption"></p>
            <span class="lb-counter" id="lb-counter"></span>
        </div>
        <button class="lb-close" aria-label="Cerrar">&#10005;</button>
        <button class="lb-prev" aria-label="Anterior">&#8249;</button>
        <button class="lb-next" aria-label="Siguiente">&#8250;</button>
    </div>

    <script>
    window.GALLERY_ITEMS = <?php echo json_encode($lb_items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    </script>
    <script src="medicina-lightbox.js"></script>

</body>
</html>

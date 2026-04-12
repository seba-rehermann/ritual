<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header("Location: admin_ritual.php");
    exit;
}

require_once __DIR__ . '/medicina_page_lib.php';

$medicina_slug = $_GET['slug'] ?? 'rape';
if (!in_array($medicina_slug, ['rape', 'ayahuasca'])) {
    exit('Medicina no válida: ' . htmlspecialchars($medicina_slug));
}

$upload_msg = isset($_GET['ok']) ? medicina_ok_message((string) $_GET['ok']) : null;
$upload_err = null;

$postResult = medicina_handle_post($medicina_slug, 'editar_medicina.php?slug=' . $medicina_slug);

if (!empty($postResult['redirect'])) {
    header('Location: ' . $postResult['redirect']);
    exit;
}
if (!empty($postResult['err'])) {
    $upload_err = $postResult['err'];
}

$data = medicina_page_load($medicina_slug);
$medicina_post_field = 'medicina_post_' . $medicina_slug;
$medicina_script     = 'editar_medicina.php?slug=' . $medicina_slug;

// Paleta de color según la medicina
$med_config = $medicina_slug === 'ayahuasca'
    ? ['label' => 'Ayahuasca', 'accent' => '#4e6e5d', 'accent_bg' => 'rgba(78,110,93,0.15)', 'border' => '#2e5040', 'text' => '#d4c3a3', 'icon' => '🍃']
    : ['label' => 'Rapé',      'accent' => '#a1887f', 'accent_bg' => 'rgba(161,136,127,0.15)', 'border' => '#5c3d2a', 'text' => '#fdf5e6', 'icon' => '🌿'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editando <?php echo $med_config['label']; ?> — Ritual Admin</title>
    <link rel="stylesheet" href="admin-style.css">
    <style>
        /* ── Estilos específicos del editor de medicina ───────────────── */

        /* Badge de medicina en el topbar */
        .med-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.75em;
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: 600;
            letter-spacing: 0.5px;
            background: <?php echo $med_config['accent_bg']; ?>;
            border: 1px solid <?php echo $med_config['border']; ?>;
            color: <?php echo $med_config['text']; ?>;
        }

        /* Encabezado de sección dentro del editor */
        .editor-section-title {
            font-size: 0.7em;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--text-dim);
            margin: 28px 0 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Subencabezado dentro de una card */
        .sub-title {
            font-size: 0.72em;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: <?php echo $med_config['accent']; ?>;
            margin: 20px 0 10px;
            padding-top: 18px;
            border-top: 1px solid var(--border);
        }
        .sub-title:first-of-type { margin-top: 0; padding-top: 0; border-top: none; }

        textarea {
            resize: vertical;
            line-height: 1.6;
        }

        /* Botón guardar grande */
        .btn-save {
            background: var(--green);
            color: #0a0a0a;
            width: 100%;
            padding: 13px 20px;
            font-size: 0.9em;
            letter-spacing: 1px;
            margin-top: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: none;
            border-radius: var(--radius-sm);
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            transition: all var(--transition);
        }
        .btn-save:hover {
            background: #a4cc45;
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(139,174,57,0.3);
        }
        .btn-save:active { transform: translateY(0); }

        /* Galería de ítems */
        .gallery-list { display: flex; flex-direction: column; gap: 12px; }

        .gallery-item {
            display: grid;
            grid-template-columns: 150px 1fr;
            gap: 16px;
            background: #111;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            padding: 14px;
            transition: border-color var(--transition);
        }
        .gallery-item:hover { border-color: <?php echo $med_config['border']; ?>; }

        .gallery-preview {
            border-radius: 7px;
            overflow: hidden;
            background: #000;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100px;
            border: 1px solid var(--border);
            position: relative;   /* needed for badge overlay */
        }
        .gallery-preview img,
        .gallery-preview video {
            width: 100%;
            height: 100%;
            max-height: 140px;
            object-fit: cover;
            display: block;
        }

        /* ── Media type badge (VIDEO / FOTO) in gallery preview ────── */
        .media-badge {
            position: absolute;
            top: 7px;
            right: 7px;
            font-size: 0.60em;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            padding: 2px 7px;
            border-radius: 5px;
            pointer-events: none;
            z-index: 2;
        }
        .media-badge-video {
            background: rgba(0, 0, 0, 0.72);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.22);
        }
        .media-badge-image {
            background: rgba(0, 0, 0, 0.50);
            color: rgba(255, 255, 255, 0.55);
            border: 1px solid rgba(255, 255, 255, 0.10);
        }

        /* ── Live upload preview ────────────────────────────────────── */
        .upload-preview-wrap {
            display: none;
            margin-top: 12px;
            border-radius: var(--radius-sm);
            overflow: hidden;
            background: #000;
            border: 1px solid var(--border-light);
            max-height: 200px;
            text-align: center;
        }
        .upload-preview-wrap.visible { display: block; }
        .upload-preview-wrap img,
        .upload-preview-wrap video {
            max-width: 100%;
            max-height: 200px;
            object-fit: contain;
            display: block;
            margin: 0 auto;
        }

        .gallery-content {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .gallery-path {
            font-size: 0.7em;
            color: var(--text-dim);
            font-family: monospace;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .gallery-content textarea {
            flex: 1;
            min-height: 60px;
            font-size: 0.85em;
        }
        .gallery-actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .btn-order {
            background: var(--card);
            border: 1px solid var(--border-light);
            color: var(--text-dim);
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 0.8em;
            cursor: pointer;
            font-family: inherit;
            transition: all var(--transition);
        }
        .btn-order:hover { border-color: var(--green-dim); color: var(--text); }

        /* Botón sync disco */
        .btn-sync {
            background: transparent;
            border: 1px solid var(--border-light);
            color: var(--text-dim);
            padding: 9px 16px;
            border-radius: var(--radius-sm);
            font-size: 0.8em;
            cursor: pointer;
            font-family: inherit;
            width: 100%;
            margin-top: 12px;
            transition: all var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .btn-sync:hover { border-color: var(--green-dim); color: var(--text); }

        .hint {
            font-size: 0.74em;
            color: var(--text-dim);
            margin-top: 6px;
        }

        @media (max-width: 600px) {
            .gallery-item { grid-template-columns: 1fr; }
            .gallery-preview { max-height: 180px; }
            .gallery-preview img, .gallery-preview video { max-height: 180px; }
        }
    </style>
</head>
<body>

<!-- ── TOPBAR ── -->
<header class="topbar">
    <div class="topbar-brand" style="gap: 14px;">
        <a href="admin_ritual.php" class="btn-pub" style="font-size:0.9em; display:flex; align-items:center; gap:5px;">
            ← Dashboard
        </a>
        <span style="color: var(--border-light);">|</span>
        <span class="med-badge">
            <?php echo $med_config['icon']; ?>
            <?php echo $med_config['label']; ?>
        </span>
    </div>
    <div class="topbar-right">
        <a href="?logout=1" class="btn-logout">Salir</a>
    </div>
</header>

<main class="dashboard">

    <!-- ── ALERTAS ── -->
    <?php if ($upload_msg): ?>
        <div class="alert alert-success">✓ <?php echo htmlspecialchars($upload_msg); ?></div>
    <?php endif; ?>
    <?php if ($upload_err): ?>
        <div class="alert alert-error">✕ <?php echo htmlspecialchars($upload_err); ?></div>
    <?php endif; ?>

    <!-- ── CONTENIDO DEL EDITOR ── -->
    <?php include __DIR__ . '/medicina_admin_inc.php'; ?>

</main>

</body>
</html>

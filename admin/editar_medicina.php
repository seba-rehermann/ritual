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
$medicina_script = 'editar_medicina.php?slug=' . $medicina_slug;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editando <?php echo ucfirst($medicina_slug); ?></title>
    <style>
        body { background: #0f0f0f; color: #e0e0e0; font-family: sans-serif; padding: 40px; }
        .container { max-width: 900px; margin: auto; background: #1a1a1a; padding: 30px; border-radius: 15px; border: 1px solid #333; }
        h1 { color: #8bae39; margin-top: 0; }
        .back { color: #8bae39; text-decoration: none; margin-bottom: 20px; display: inline-block; font-size: 0.9em; }
        .admin-area { margin-top: 20px; }
        .admin-medicina { background: transparent !important; border: none !important; color: #eee !important; padding: 0 !important; }
        .admin-sub { color: #8bae39 !important; border-bottom: 1px solid #333 !important; }
        input, textarea { background: #000 !important; color: #fff !important; border: 1px solid #444 !important; padding: 10px !important; border-radius: 5px !important; }
        button, .admin-inline-form button { background: #8bae39 !important; color: #000 !important; border: none !important; padding: 8px 15px !important; border-radius: 5px !important; cursor: pointer; font-weight: bold !important; }
        button.btn-danger { background: #ff5252 !important; color: #fff !important; }
        .admin-item-block { background: #222 !important; border: 1px solid #333 !important; padding: 15px !important; margin-bottom: 15px !important; border-radius: 10px !important; }
    </style>
</head>
<body>
    <div class="container">
        <a href="admin_ritual.php" class="back">⬅ Volver al Panel Maestro</a>
        <h1>Editando: <?php echo ucfirst($medicina_slug); ?></h1>

        <?php if ($upload_msg) echo "<p style='color:#8bae39; background: rgba(139,174,57,0.1); padding: 10px; border-radius: 5px;'>" . htmlspecialchars($upload_msg) . "</p>"; ?>
        <?php if ($upload_err) echo "<p style='color:#ff5252; background: rgba(255,82,82,0.1); padding: 10px; border-radius: 5px;'>" . htmlspecialchars($upload_err) . "</p>"; ?>

        <div class="admin-area">
            <?php include __DIR__ . '/medicina_admin_inc.php'; ?>
        </div>
    </div>
</body>
</html>

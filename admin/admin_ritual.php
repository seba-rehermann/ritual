<?php
session_start();
require_once __DIR__ . '/config.php';

// 1. Gestión de Login
if (isset($_POST['login'])) {
    if (($_POST['pass'] ?? '') === $password_admin) {
        $_SESSION['admin_logged'] = true;
    } else {
        $error_login = "Contraseña incorrecta";
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin_ritual.php");
    exit;
}

// Bloqueo de acceso si no está logueado
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true): ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Ritual Admin</title>
    <style>
        body { background: #0f0f0f; color: #e0e0e0; font-family: 'Segoe UI', sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-card { background: #1a1a1a; padding: 40px; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); border: 1px solid #333; text-align: center; width: 300px; }
        input { width: 100%; padding: 12px; margin: 10px 0; background: #000; border: 1px solid #444; color: #fff; border-radius: 8px; box-sizing: border-box; }
        button { width: 100%; padding: 12px; background: linear-gradient(135deg, #8bae39, #558b2f); border: none; color: #000; font-weight: bold; border-radius: 8px; cursor: pointer; }
        .error { color: #ff5252; font-size: 0.8em; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="login-card">
        <h2>🌿 Portal Admin</h2>
        <?php if (isset($error_login)) echo "<p class='error'>$error_login</p>"; ?>
        <form method="post">
            <input type="password" name="pass" placeholder="Contraseña Maestra" required autofocus>
            <button type="submit" name="login">ENTRAR</button>
        </form>
    </div>
</body>
</html>
<?php exit; endif; ?>

<?php
// 2. Lógica de Procesamiento (Ya logueado)
require_once __DIR__ . '/ritual_upload.php';
$yt_file = 'data/youtube_links.txt';
$res = null;
$upload_error = null;

if (isset($_POST['submit_any'])) {
    $hadFile = isset($_FILES['fileToUpload']) && (int) $_FILES['fileToUpload']['error'] !== UPLOAD_ERR_NO_FILE;
    if ($hadFile) {
        $up = ritual_process_upload($_FILES['fileToUpload']);
        if (!$up['ok']) { $upload_error = $up['error']; } 
        else { $res = '✅ Archivo subido con éxito'; }
    }
    if (!empty($_POST['yt_url'])) {
        $url = $_POST['yt_url'];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $vars);
        $v_id = $vars['v'] ?? substr((string) parse_url($url, PHP_URL_PATH), 1);
        if ($v_id) {
            file_put_contents($yt_file, trim($v_id) . PHP_EOL, FILE_APPEND);
            $res = ($res ? $res . ' y ' : '✅ ') . 'Video de YouTube añadido';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Control - Sebaji</title>
    <style>
        :root { --primary: #8bae39; --bg: #0f0f0f; --card: #1a1a1a; --text: #e0e0e0; }
        body { background: var(--bg); color: var(--text); font-family: 'Segoe UI', sans-serif; margin: 0; padding: 40px; }
        .container { max-width: 900px; margin: auto; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 40px; border-bottom: 1px solid #333; padding-bottom: 20px; }
        .header h1 { margin: 0; color: var(--primary); letter-spacing: 2px; }
        .logout { color: #ff5252; text-decoration: none; font-size: 0.9em; border: 1px solid #ff5252; padding: 5px 15px; border-radius: 20px; }
        
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
        .card { background: var(--card); padding: 25px; border-radius: 15px; border: 1px solid #333; }
        h2 { font-size: 1.2em; margin-top: 0; color: var(--primary); }
        
        input, select, textarea { width: 100%; padding: 12px; margin: 10px 0; background: #000; border: 1px solid #444; color: #fff; border-radius: 8px; box-sizing: border-box; }
        .btn { background: var(--primary); color: #000; font-weight: bold; border: none; padding: 12px 20px; border-radius: 8px; cursor: pointer; width: 100%; transition: 0.3s; }
        .btn:hover { background: #b0d356; transform: translateY(-2px); }
        .btn-med { text-decoration: none; display: block; text-align: center; margin-top: 10px; }
        
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; }
        .alert-success { background: rgba(139, 174, 57, 0.2); color: var(--primary); border: 1px solid var(--primary); }
        .alert-error { background: rgba(255, 82, 82, 0.2); color: #ff5252; border: 1px solid #ff5252; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🌿 PANEL RITUAL</h1>
            <a href="?logout=1" class="logout">Cerrar Sesión</a>
        </div>

        <?php if ($res): ?> <div class="alert alert-success"><?php echo $res; ?></div> <?php endif; ?>
        <?php if ($upload_error): ?> <div class="alert alert-error"><?php echo $upload_error; ?></div> <?php endif; ?>

        <div class="grid">
            <div class="card">
                <h2>📦 Gestión de Contenido</h2>
                <form action="" method="post" enctype="multipart/form-data">
                    <label>Subir Multimedia (Fotos/Videos/Audio):</label>
                    <input type="file" name="fileToUpload">
                    <label>Añadir Link de YouTube:</label>
                    <input type="text" name="yt_url" placeholder="https://www.youtube.com/watch?v=...">
                    <button type="submit" name="submit_any" class="btn">EJECUTAR CARGA</button>
                </form>
            </div>

            <div class="card">
                <h2>🎨 Edición de Páginas</h2>
                <p>Modifica los textos y galerías de las medicinas sagradas:</p>
                <a href="editar_medicina.php?slug=ayahuasca" class="btn btn-med" style="background: #1b3022; color: #d4c3a3;">EDITAR AYAHUASCA</a>
                <a href="editar_medicina.php?slug=rape" class="btn btn-med" style="background: #4a3728; color: #fdf5e6;">EDITAR RAPÉ</a>
            </div>
        </div>

        <div style="text-align: center;">
            <?php
            $pub_host = explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0];
            $pub_url  = 'http://' . $pub_host . ':8081';
            ?>
            <a href="<?php echo htmlspecialchars($pub_url); ?>" style="color: #666; text-decoration: none;">⬅ Ver la Web Pública</a>
        </div>
    </div>
</body>
</html>

<?php
/**
 * Bloque de administración para medicina (incluido desde editar_medicina.php).
 * La autenticación ya fue verificada en editar_medicina.php via sesión.
 */
if (!isset($_SESSION['admin_logged'])) {
    exit;
}
?>

<div id="admin-medicina-panel" class="admin-medicina">
    <h2 class="admin-medicina-titulo">⚙️ PANEL DE EDICIÓN: <?php echo strtoupper($medicina_slug); ?></h2>

    <div class="admin-item-block">
        <h3 class="admin-sub">📝 Textos de Introducción</h3>
        <form action="<?php echo $medicina_script; ?>" method="post" class="admin-form-wide">
            <input type="hidden" name="<?php echo $medicina_post_field; ?>" value="1">
            <input type="hidden" name="medicina_action" value="save_texts">

            <label>Párrafo 1:</label>
            <textarea name="intro_0" rows="4"><?php echo htmlspecialchars($data['intro'][0] ?? ''); ?></textarea>

            <label>Párrafo 2:</label>
            <textarea name="intro_1" rows="4"><?php echo htmlspecialchars($data['intro'][1] ?? ''); ?></textarea>

            <h3 class="admin-sub">📢 Bloque de Acción (CTA)</h3>
            <label>Título:</label>
            <input type="text" name="cta_title" value="<?php echo htmlspecialchars($data['cta_title'] ?? ''); ?>">
            <label>Texto:</label>
            <textarea name="cta_text" rows="3"><?php echo htmlspecialchars($data['cta_text'] ?? ''); ?></textarea>

            <button type="submit">GUARDAR TEXTOS</button>
        </form>
    </div>

    <div class="admin-item-block">
        <h3 class="admin-sub">📷 Añadir a la Galería</h3>
        <form action="<?php echo $medicina_script; ?>" method="post" enctype="multipart/form-data" class="admin-form-wide">
            <input type="hidden" name="<?php echo $medicina_post_field; ?>" value="1">
            <input type="hidden" name="medicina_action" value="add_media">

            <input type="file" name="medicina_file" accept="image/*,video/*">
            <input type="text" name="caption_new" placeholder="Pie de foto o video (opcional)">
            <button type="submit">SUBIR A GALERÍA</button>
        </form>
    </div>

    <div class="admin-item-block">
        <h3 class="admin-sub">🖼️ Organizar Galería</h3>
        <form action="<?php echo $medicina_script; ?>" method="post">
            <input type="hidden" name="<?php echo $medicina_post_field; ?>" value="1">
            <input type="hidden" name="medicina_action" value="save_captions">

            <?php if (empty($data['items'])): ?>
                <p class="admin-hint">No hay elementos en la galería.</p>
            <?php else: ?>
                <?php foreach ($data['items'] as $row): ?>
                    <div class="admin-item-block" style="background: #2a2a2a !important;">
                        <div class="admin-item-preview">
                            <?php
                            $ext = strtolower(pathinfo($row['path'], PATHINFO_EXTENSION));
                            if (in_array($ext, ['mp4', 'webm'])): ?>
                                <video src="../<?php echo htmlspecialchars($row['path']); ?>" muted style="max-height:100px;"></video>
                            <?php else: ?>
                                <img src="../<?php echo htmlspecialchars($row['path']); ?>" style="max-height:100px;" loading="lazy">
                            <?php endif; ?>
                        </div>

                        <textarea name="caption[<?php echo $row['id']; ?>]" placeholder="Pie de foto..."><?php echo htmlspecialchars($row['caption']); ?></textarea>

                        <div class="admin-item-toolbar" style="margin-top:10px;">
                            <button type="submit" name="medicina_action" value="move_item" onclick="document.getElementsByName('move_id')[0].value='<?php echo $row['id']; ?>'; document.getElementsByName('move_dir')[0].value='up';">▲</button>
                            <button type="submit" name="medicina_action" value="move_item" onclick="document.getElementsByName('move_id')[0].value='<?php echo $row['id']; ?>'; document.getElementsByName('move_dir')[0].value='down';">▼</button>
                            <button type="submit" name="medicina_action" value="delete_item" class="btn-danger" onclick="if(!confirm('¿Borrar este archivo?')) return false; document.getElementsByName('delete_id')[0].value='<?php echo $row['id']; ?>';">BORRAR</button>
                        </div>
                    </div>
                <?php endforeach; ?>
                <input type="hidden" name="move_id" value="">
                <input type="hidden" name="move_dir" value="">
                <input type="hidden" name="delete_id" value="">
                <button type="submit">GUARDAR TODOS LOS PIES DE FOTO</button>
            <?php endif; ?>
        </form>

        <form action="<?php echo $medicina_script; ?>" method="post" style="margin-top:20px;">
            <input type="hidden" name="<?php echo $medicina_post_field; ?>" value="1">
            <input type="hidden" name="medicina_action" value="sync_disk">
            <button type="submit" style="background: #555 !important; color: white !important;">🔄 IMPORTAR ARCHIVOS NUEVOS DEL DISCO</button>
            <p class="admin-hint">Usa esto si subiste archivos por FTP o SCP y no aparecen aquí.</p>
        </form>
    </div>
</div>

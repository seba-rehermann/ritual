<?php
/**
 * Panel de edición de medicina — incluido desde editar_medicina.php.
 * La autenticación y las variables de contexto ($medicina_slug, $data,
 * $medicina_post_field, $medicina_script, $med_config) ya están disponibles.
 */
if (!isset($_SESSION['admin_logged'])) {
    exit;
}
?>

<!-- ══════════════════════════════════════════════════════════════
     SECCIÓN 1 — TEXTOS DE LA PÁGINA
══════════════════════════════════════════════════════════════ -->
<div class="editor-section-title">
    📝 Textos de la página
</div>

<div class="card">
    <form action="<?php echo $medicina_script; ?>" method="post">
        <input type="hidden" name="<?php echo $medicina_post_field; ?>" value="1">
        <input type="hidden" name="medicina_action" value="save_texts">

        <div class="sub-title">Introducción</div>

        <div class="form-group">
            <label>Párrafo 1</label>
            <textarea name="intro_0" rows="5"><?php echo htmlspecialchars($data['intro'][0] ?? ''); ?></textarea>
        </div>
        <div class="form-group">
            <label>Párrafo 2</label>
            <textarea name="intro_1" rows="5"><?php echo htmlspecialchars($data['intro'][1] ?? ''); ?></textarea>
        </div>

        <div class="sub-title">Bloque de llamada a la acción (CTA)</div>

        <div class="form-group">
            <label>Título del CTA</label>
            <input type="text" name="cta_title" value="<?php echo htmlspecialchars($data['cta_title'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label>Texto del CTA</label>
            <textarea name="cta_text" rows="3"><?php echo htmlspecialchars($data['cta_text'] ?? ''); ?></textarea>
        </div>

        <button type="submit" class="btn-save">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <polyline points="20 6 9 17 4 12"/>
            </svg>
            Guardar textos
        </button>
    </form>
</div>


<!-- ══════════════════════════════════════════════════════════════
     SECCIÓN 2 — AGREGAR A LA GALERÍA
══════════════════════════════════════════════════════════════ -->
<div class="editor-section-title" style="margin-top:32px;">
    📷 Añadir a la galería
</div>

<div class="card">
    <form action="<?php echo $medicina_script; ?>" method="post" enctype="multipart/form-data">
        <input type="hidden" name="<?php echo $medicina_post_field; ?>" value="1">
        <input type="hidden" name="medicina_action" value="add_media">

        <div class="form-group">
            <label>Imagen o video</label>
            <div class="file-drop" id="medFileDrop">
                <input type="file" name="medicina_file" id="medFileInput" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm">
                <span class="file-drop-icon">🎞️</span>
                <span class="file-drop-text">Arrastrá o hacé click — JPG · PNG · WEBP · MP4 · WEBM</span>
                <div class="file-drop-name" id="medFileName"></div>
            </div>
            <!-- Vista previa del archivo seleccionado antes de subir -->
            <div class="upload-preview-wrap" id="uploadPreviewWrap"></div>
        </div>
        <div class="form-group">
            <label>Pie de foto / video <span style="color:var(--text-dim)">(opcional)</span></label>
            <input type="text" name="caption_new" placeholder="Descripción breve...">
        </div>

        <button type="submit" class="btn-save">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
                <path d="M5 19h14"/>
            </svg>
            Subir a la galería
        </button>
    </form>
</div>


<!-- ══════════════════════════════════════════════════════════════
     SECCIÓN 3 — GALERÍA EXISTENTE
══════════════════════════════════════════════════════════════ -->
<div class="editor-section-title" style="margin-top:32px;">
    🖼️ Galería actual
    <span style="font-size:0.9em; color:var(--green); font-weight:600;">
        <?php echo count($data['items']); ?> elemento<?php echo count($data['items']) !== 1 ? 's' : ''; ?>
    </span>
</div>

<div class="card">
    <?php if (empty($data['items'])): ?>
        <p style="text-align:center; color:var(--text-dim); padding:24px 0; font-size:0.88em;">
            La galería está vacía. Subí una imagen o video desde la sección de arriba.
        </p>
    <?php else: ?>

        <form action="<?php echo $medicina_script; ?>" method="post">
            <input type="hidden" name="<?php echo $medicina_post_field; ?>" value="1">
            <input type="hidden" name="medicina_action" value="save_captions">
            <input type="hidden" name="move_id"   value="">
            <input type="hidden" name="move_dir"  value="">
            <input type="hidden" name="delete_id" value="">

            <div class="gallery-list">
                <?php foreach ($data['items'] as $row):
                    $ext  = strtolower(pathinfo($row['path'], PATHINFO_EXTENSION));
                    $safe = htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8');
                    $id   = htmlspecialchars($row['id'],   ENT_QUOTES, 'UTF-8');
                    $isVideo = in_array($ext, ['mp4', 'webm']);
                ?>
                <div class="gallery-item">

                    <!-- Preview -->
                    <div class="gallery-preview">
                        <?php if ($isVideo): ?>
                            <video src="<?php echo $safe; ?>" muted playsinline preload="metadata"
                                   onmouseenter="this.play()" onmouseleave="this.pause(); this.currentTime=0.5;">
                            </video>
                            <span class="media-badge media-badge-video">▶ Video</span>
                        <?php else: ?>
                            <img src="<?php echo $safe; ?>" alt="" loading="lazy">
                            <span class="media-badge media-badge-image">Foto</span>
                        <?php endif; ?>
                    </div>

                    <!-- Contenido editable -->
                    <div class="gallery-content">
                        <div class="gallery-path"><?php echo $safe; ?></div>

                        <textarea name="caption[<?php echo $id; ?>]"
                                  placeholder="Pie de foto o video..."
                                  rows="3"><?php echo htmlspecialchars($row['caption']); ?></textarea>

                        <div class="gallery-actions">
                            <!-- Mover arriba -->
                            <button type="submit" class="btn-order"
                                    name="medicina_action" value="move_item"
                                    onclick="document.getElementsByName('move_id')[0].value='<?php echo $id; ?>';
                                             document.getElementsByName('move_dir')[0].value='up';"
                                    title="Mover arriba">▲</button>
                            <!-- Mover abajo -->
                            <button type="submit" class="btn-order"
                                    name="medicina_action" value="move_item"
                                    onclick="document.getElementsByName('move_id')[0].value='<?php echo $id; ?>';
                                             document.getElementsByName('move_dir')[0].value='down';"
                                    title="Mover abajo">▼</button>
                            <!-- Eliminar -->
                            <button type="submit" class="btn btn-danger"
                                    name="medicina_action" value="delete_item"
                                    onclick="if(!confirm('¿Eliminar este archivo de la galería y del disco?')) return false;
                                             document.getElementsByName('delete_id')[0].value='<?php echo $id; ?>';">
                                🗑 Eliminar
                            </button>
                        </div>
                    </div>

                </div>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="btn-save" style="margin-top:18px;">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
                Guardar pies de foto
            </button>
        </form>

    <?php endif; ?>

    <!-- Sync disco -->
    <form action="<?php echo $medicina_script; ?>" method="post">
        <input type="hidden" name="<?php echo $medicina_post_field; ?>" value="1">
        <input type="hidden" name="medicina_action" value="sync_disk">
        <button type="submit" class="btn-sync">
            🔄 Importar archivos nuevos del disco
        </button>
    </form>
    <p class="hint" style="text-align:center;">
        Usalo si subiste archivos por FTP / SCP y no aparecen en la galería.
    </p>
</div>

<script>
(function () {
    'use strict';

    var drop        = document.getElementById('medFileDrop');
    var input       = document.getElementById('medFileInput');
    var nameEl      = document.getElementById('medFileName');
    var previewWrap = document.getElementById('uploadPreviewWrap');
    if (!drop || !input) return;

    var IMG_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    var VID_EXT = ['mp4', 'webm'];

    function showPreview(file) {
        if (!previewWrap) return;
        previewWrap.innerHTML = '';
        previewWrap.classList.remove('visible');
        if (!file) return;

        var ext = file.name.split('.').pop().toLowerCase();
        var url = URL.createObjectURL(file);

        if (IMG_EXT.includes(ext)) {
            var img = document.createElement('img');
            img.src = url;
            img.onload = function () { URL.revokeObjectURL(url); };
            previewWrap.appendChild(img);
            previewWrap.classList.add('visible');
        } else if (VID_EXT.includes(ext)) {
            var vid = document.createElement('video');
            vid.src          = url;
            vid.muted        = true;
            vid.playsInline  = true;
            vid.controls     = false;
            vid.preload      = 'metadata';
            vid.addEventListener('loadedmetadata', function () {
                if (vid.duration > 0.6) vid.currentTime = 0.5;
                URL.revokeObjectURL(url);
            });
            previewWrap.appendChild(vid);
            previewWrap.classList.add('visible');
        }
    }

    function handleFiles(files) {
        if (!files || !files.length) return;
        /* DataTransfer trick to assign dropped files to input */
        try {
            var dt = new DataTransfer();
            dt.items.add(files[0]);
            input.files = dt.files;
        } catch (e) { /* Safari fallback — no preview, name only */ }
        nameEl.textContent = files[0].name;
        showPreview(files[0]);
    }

    input.addEventListener('change', function () {
        if (input.files.length) {
            nameEl.textContent = input.files[0].name;
            showPreview(input.files[0]);
        }
    });

    drop.addEventListener('dragover',  function (e) { e.preventDefault(); drop.classList.add('drag-over'); });
    drop.addEventListener('dragleave', function ()  { drop.classList.remove('drag-over'); });
    drop.addEventListener('drop', function (e) {
        e.preventDefault();
        drop.classList.remove('drag-over');
        handleFiles(e.dataTransfer.files);
    });

    /* Seek gallery video thumbnails past the black first frame */
    document.querySelectorAll('.gallery-preview video').forEach(function (v) {
        v.addEventListener('loadedmetadata', function () {
            if (v.duration > 0.6) v.currentTime = 0.5;
        });
    });

}());
</script>

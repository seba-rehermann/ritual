<?php
/**
 * Motor de subida Ritual - Versión definitiva y ordenada
 */

/**
 * Procesa subidas generales (Panel Maestro -> Carpeta /home)
 */
function ritual_process_upload(array $filesField): array {
    if ($filesField['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => 'Error de subida.'];

    $ext = strtolower(pathinfo($filesField['name'], PATHINFO_EXTENSION));
    $baseDir = __DIR__;

    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
        $destDir = $baseDir . '/fotos/home';
    } elseif (in_array($ext, ['mp4', 'webm'])) {
        $destDir = $baseDir . '/videos/home';
    } elseif (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a'])) {
        $destDir = $baseDir . '/musica/home';
    } else {
        return ['ok' => false, 'error' => 'Extensión no permitida.'];
    }

    if (!is_dir($destDir)) @mkdir($destDir, 0755, true);

    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filesField['name']);
    $target = $destDir . '/' . $filename;

    if (move_uploaded_file($filesField['tmp_name'], $target)) {
        return ['ok' => true];
    }
    return ['ok' => false, 'error' => 'No se pudo guardar en el depósito (home).'];
}

/**
 * Procesa subidas específicas (Sección Medicina -> Carpeta /medicina)
 * SOLO IMÁGENES Y VIDEO (Filtro estricto)
 */
function ritual_process_upload_medicina(array $filesField, string $slug): array {
    if ($filesField['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => 'Error de subida.'];

    $ext = strtolower(pathinfo($filesField['name'], PATHINFO_EXTENSION));
    $baseDir = __DIR__;
    
    // Clasificación y filtro de extensiones
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
        $destDir = $baseDir . '/fotos/medicina';
        $relPrefix = 'fotos/medicina/';
    } elseif (in_array($ext, ['mp4', 'webm'])) {
        $destDir = $baseDir . '/videos/medicina';
        $relPrefix = 'videos/medicina/';
    } else {
        // Bloqueo de audio y otros archivos en esta sección
        return ['ok' => false, 'error' => 'En esta sección solo se permiten imágenes o videos. El audio debe subirse desde el panel general.'];
    }
    
    if (!is_dir($destDir)) @mkdir($destDir, 0755, true);

    $filename = $slug . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $filesField['name']);
    $target = $destDir . '/' . $filename;

    if (move_uploaded_file($filesField['tmp_name'], $target)) {
        return ['ok' => true, 'path' => $relPrefix . $filename];
    }
    return ['ok' => false, 'error' => 'No se pudo guardar en el depósito (medicina).'];
}

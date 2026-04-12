<?php

declare(strict_types=1);

/**
 * Páginas medicina (rape / ayahuasca): JSON en data/medicina_{slug}.json
 * — textos intro, bloque comercial, galería con pie por ítem.
 *
 * NOTA: este archivo existe en admin/ y publico/ con contenido idéntico.
 * Cualquier cambio debe aplicarse en ambas copias.
 */

function medicina_json_path(string $slug): string
{
    if (!in_array($slug, ['rape', 'ayahuasca'], true)) {
        throw new InvalidArgumentException('slug');
    }

    return __DIR__ . '/data/medicina_' . $slug . '.json';
}

function medicina_defaults(string $slug): array
{
    if ($slug === 'rape') {
        return [
            'intro' => [
                'El Rapé es una medicina sagrada ancestral, una alquimia de tabaco orgánico molido y cenizas de árboles sagrados. A diferencia del uso profano del tabaco, el Rapé se recibe como una oración soplada, un acto de humildad y presencia absoluta.',
                'Su función es silenciar el diálogo interno, descalcificar la glándula pineal y armonizar los hemisferios del cerebro. Es la medicina del equilibrio: nos enraíza en la tierra mientras nos abre la conexión con el cosmos, permitiendo una claridad mental y emocional inmediata.',
            ],
            'cta_title' => 'Rapé disponible',
            'cta_text' => 'Consulta variedades, procedencia y envíos escribiéndonos desde la página principal o por los canales que compartimos allí. Cada pedido se prepara con intención y respeto a la medicina.',
            'items' => [],
        ];
    }

    return [
        'intro' => [
            'La Ayahuasca es una medicina milenaria originaria de la cuenca amazónica. Es el resultado de la cocción lenta de dos plantas sagradas: la liana Banisteriopsis caapi y las hojas del arbusto Psychotria viridis (Chacruna).',
            'Conocida como «la soga de los muertos» o «la soga del alma», esta medicina no es una droga recreativa, sino una herramienta de sanación profunda. Permite al buscador navegar por los paisajes de su propio inconsciente, enfrentando sombras y encontrando la luz de la comprensión y el perdón.',
        ],
        'cta_title' => 'Información y acompañamiento',
        'cta_text' => 'Si buscas orientación seria sobre la medicina, círculos o integración, escríbenos desde la página principal. Respondemos con cuidado y sin promesas mágicas: el respeto al proceso es lo primero.',
        'items' => [],
    ];
}

function medicina_path_allowed_gallery(string $rel, string $slug): bool
{
    $rel = str_replace('\\', '/', $rel);
    if ($rel === '' || str_contains($rel, '..')) {
        return false;
    }
    if (!str_starts_with($rel, 'fotos/') && !str_starts_with($rel, 'videos/')) {
        return false;
    }
    $bn = basename($rel);
    $prefix = $slug . '_';
    if (!str_starts_with(strtolower($bn), strtolower($prefix))) {
        return false;
    }
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    $img = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $vid = ['mp4', 'webm'];

    return in_array($ext, array_merge($img, $vid), true);
}

function medicina_sanitize_items(array $raw, string $slug): array
{
    $out = [];
    foreach ($raw as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = isset($row['id']) ? preg_replace('/[^a-f0-9]/', '', (string) $row['id']) : '';
        $path = isset($row['path']) ? str_replace('\\', '/', (string) $row['path']) : '';

        // Normaliza rutas absolutas heredadas (ej: /var/www/html/fotos/...)
        if (str_starts_with($path, '/')) {
            $path = preg_replace('#^/[^/]+/[^/]+/[^/]+/#', '', $path);
        }
        $path = ltrim($path, '/');

        if ($id === '' || strlen($id) > 32) {
            continue;
        }
        if (!medicina_path_allowed_gallery($path, $slug)) {
            continue;
        }
        $out[] = [
            'id'      => $id,
            'path'    => $path,
            'caption' => isset($row['caption']) ? (string) $row['caption'] : '',
        ];
    }

    return $out;
}

function medicina_page_load(string $slug): array
{
    $def = medicina_defaults($slug);
    $p = medicina_json_path($slug);
    if (!is_readable($p)) {
        return $def;
    }
    $j = json_decode((string) file_get_contents($p), true);
    if (!is_array($j)) {
        return $def;
    }
    $out = $def;
    if (isset($j['intro']) && is_array($j['intro'])) {
        $intro = array_values(array_map('strval', $j['intro']));
        $out['intro'] = [
            $intro[0] ?? $def['intro'][0],
            $intro[1] ?? $def['intro'][1],
        ];
    }
    if (isset($j['cta_title'])) {
        $out['cta_title'] = (string) $j['cta_title'];
    }
    if (isset($j['cta_text'])) {
        $out['cta_text'] = (string) $j['cta_text'];
    }
    if (isset($j['items']) && is_array($j['items'])) {
        $out['items'] = medicina_sanitize_items($j['items'], $slug);
    }

    return $out;
}

function medicina_page_save(string $slug, array $data): void
{
    $dir = dirname(medicina_json_path($slug));
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $payload = [
        'intro'     => [$data['intro'][0] ?? '', $data['intro'][1] ?? ''],
        'cta_title' => $data['cta_title'] ?? '',
        'cta_text'  => $data['cta_text'] ?? '',
        'items'     => medicina_sanitize_items($data['items'] ?? [], $slug),
    ];
    file_put_contents(
        medicina_json_path($slug),
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function medicina_gallery_paths_on_disk(string $slug): array
{
    $g1 = glob(__DIR__ . "/fotos/medicina/{$slug}*.{jpg,jpeg,png,webp,gif}", GLOB_BRACE) ?: [];
    $g2 = glob(__DIR__ . "/videos/medicina/{$slug}*.{mp4,webm}", GLOB_BRACE) ?: [];
    $base = __DIR__ . '/';
    $rel = [];
    foreach (array_merge($g1, $g2) as $abs) {
        $rel[] = str_replace('\\', '/', substr($abs, strlen($base)));
    }

    return $rel;
}

function medicina_sync_items_from_disk(array $data, string $slug): array
{
    $known = array_column($data['items'], 'path');
    foreach (medicina_gallery_paths_on_disk($slug) as $p) {
        if (!medicina_path_allowed_gallery($p, $slug)) {
            continue;
        }
        if (!in_array($p, $known, true)) {
            $data['items'][] = [
                'id'      => bin2hex(random_bytes(8)),
                'path'    => $p,
                'caption' => '',
            ];
            $known[] = $p;
        }
    }

    return $data;
}

function medicina_delete_file_safe(string $rel, string $slug): void
{
    if (!medicina_path_allowed_gallery($rel, $slug)) {
        return;
    }
    $abs = __DIR__ . '/' . $rel;
    if (is_file($abs)) {
        @unlink($abs);
    }
}

/**
 * Procesa el POST del panel de edición de medicina.
 * Solo debe llamarse desde contextos ya autenticados (sesión admin verificada).
 *
 * @return array{redirect?: string, msg?: string, err?: string}
 */
function medicina_handle_post(string $slug, string $redirectBase): array
{
    $field = $slug === 'rape' ? 'medicina_post_rape' : 'medicina_post_ayahuasca';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST[$field])) {
        return [];
    }

    $action = isset($_POST['medicina_action']) ? (string) $_POST['medicina_action'] : '';
    $data = medicina_page_load($slug);

    if ($action === 'save_texts') {
        $data['intro'][0] = isset($_POST['intro_0']) ? (string) $_POST['intro_0'] : '';
        $data['intro'][1] = isset($_POST['intro_1']) ? (string) $_POST['intro_1'] : '';
        $data['cta_title'] = isset($_POST['cta_title']) ? (string) $_POST['cta_title'] : '';
        $data['cta_text'] = isset($_POST['cta_text']) ? (string) $_POST['cta_text'] : '';
        medicina_page_save($slug, $data);

        return ['redirect' => $redirectBase . '?ok=texts#admin-medicina-panel'];
    }

    if ($action === 'save_captions') {
        $posted = isset($_POST['caption']) && is_array($_POST['caption']) ? $_POST['caption'] : [];
        foreach ($data['items'] as $i => $row) {
            $id = $row['id'];
            if (isset($posted[$id])) {
                $data['items'][$i]['caption'] = (string) $posted[$id];
            }
        }
        medicina_page_save($slug, $data);

        return ['redirect' => $redirectBase . '?ok=captions#admin-medicina-panel'];
    }

    if ($action === 'delete_item') {
        $delId = isset($_POST['delete_id']) ? preg_replace('/[^a-f0-9]/', '', (string) $_POST['delete_id']) : '';
        if ($delId !== '') {
            $newItems = [];
            foreach ($data['items'] as $row) {
                if ($row['id'] === $delId) {
                    medicina_delete_file_safe($row['path'], $slug);
                } else {
                    $newItems[] = $row;
                }
            }
            $data['items'] = $newItems;
            medicina_page_save($slug, $data);
        }

        return ['redirect' => $redirectBase . '?ok=deleted#admin-medicina-panel'];
    }

    if ($action === 'sync_disk') {
        $data = medicina_sync_items_from_disk($data, $slug);
        medicina_page_save($slug, $data);

        return ['redirect' => $redirectBase . '?ok=sync#admin-medicina-panel'];
    }

    if ($action === 'move_item') {
        $mid = isset($_POST['move_id']) ? preg_replace('/[^a-f0-9]/', '', (string) $_POST['move_id']) : '';
        $dir = isset($_POST['move_dir']) ? (string) $_POST['move_dir'] : '';
        $idx = -1;
        foreach ($data['items'] as $i => $row) {
            if ($row['id'] === $mid) {
                $idx = $i;
                break;
            }
        }
        if ($idx >= 0) {
            if ($dir === 'up' && $idx > 0) {
                $t = $data['items'][$idx - 1];
                $data['items'][$idx - 1] = $data['items'][$idx];
                $data['items'][$idx] = $t;
            }
            if ($dir === 'down' && $idx < count($data['items']) - 1) {
                $t = $data['items'][$idx + 1];
                $data['items'][$idx + 1] = $data['items'][$idx];
                $data['items'][$idx] = $t;
            }
            medicina_page_save($slug, $data);
        }

        return ['redirect' => $redirectBase . '?ok=order#admin-medicina-panel'];
    }

    if ($action === 'add_media') {
        if (!isset($_FILES['medicina_file']) || (int) $_FILES['medicina_file']['error'] === UPLOAD_ERR_NO_FILE) {
            return ['err' => 'Selecciona un archivo.'];
        }
        require_once __DIR__ . '/ritual_upload.php';
        $r = ritual_process_upload_medicina($_FILES['medicina_file'], $slug);
        if (!$r['ok']) {
            return ['err' => $r['error'] ?? 'Error al subir.'];
        }
        $rel = $r['path'] ?? '';
        if ($rel === '' || !medicina_path_allowed_gallery($rel, $slug)) {
            return ['err' => 'Solo imágenes y vídeo en galería (no audio en esta sección).'];
        }
        $cap = isset($_POST['caption_new']) ? (string) $_POST['caption_new'] : '';
        $data['items'][] = [
            'id'      => bin2hex(random_bytes(8)),
            'path'    => $rel,
            'caption' => $cap,
        ];
        medicina_page_save($slug, $data);

        return ['redirect' => $redirectBase . '?ok=added#admin-medicina-panel'];
    }

    return ['err' => 'Acción no reconocida.'];
}

function medicina_ok_message(string $key): string
{
    return match ($key) {
        'texts'    => 'Textos de la página guardados.',
        'captions' => 'Textos bajo fotos y vídeos guardados.',
        'deleted'  => 'Elemento eliminado del disco y de la galería.',
        'sync'     => 'Archivos en carpetas añadidos a la galería (sin quitar los que ya estaban).',
        'order'    => 'Orden actualizado.',
        'added'    => 'Archivo añadido a la galería.',
        default    => 'Cambios guardados.',
    };
}

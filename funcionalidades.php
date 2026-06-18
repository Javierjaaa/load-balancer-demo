<?php
// ============================================================
//   WORKCONNECT - Funcionalidades: Logs, Reportes, Backup
//   Incluido desde index.php DESPUÉS de db.php y session_start()
// ============================================================

// ── Crear tabla logs si no existe ────────────────────────────
function crearTablaLogs(): void {
    static $done = false;
    if ($done) return;
    try {
        db()->exec("
            IF OBJECT_ID('logs_actividad','U') IS NULL
            CREATE TABLE logs_actividad (
                id             INT IDENTITY(1,1) PRIMARY KEY,
                fecha          DATETIME      DEFAULT GETDATE(),
                tipo_usuario   NVARCHAR(20)  NULL,
                usuario_id     INT           NULL,
                usuario_nombre NVARCHAR(200) NULL,
                accion         NVARCHAR(100) NOT NULL,
                detalle        NVARCHAR(500) NULL,
                ip             NVARCHAR(50)  NULL
            )
        ");
        $done = true;
    } catch (Exception $e) { $done = true; }
}

// ── Registrar log (2 parámetros: accion, detalle) ────────────
function registrarLog(string $accion, string $detalle = ''): void {
    try {
        crearTablaLogs();
        db()->prepare("
            INSERT INTO logs_actividad
                (tipo_usuario, usuario_id, usuario_nombre, accion, detalle, ip)
            VALUES (?,?,?,?,?,?)
        ")->execute([
            $_SESSION['tipo']      ?? 'anonimo',
            $_SESSION['auth_id']   ?? null,
            $_SESSION['auth_name'] ?? 'Desconocido',
            $accion,
            $detalle,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        ]);
    } catch (Exception $e) { /* silencioso */ }
}

// ── Cargar logs para la página de logs ───────────────────────
$logs = [];
if (($_GET['page'] ?? '') === 'logs' && isset($_SESSION['tipo'])) {
    try {
        crearTablaLogs();
        $stmt = db()->prepare("
            SELECT TOP 100 * FROM logs_actividad
            ORDER BY fecha DESC
        ");
        $stmt->execute();
        $logs = $stmt->fetchAll();
    } catch (Exception $e) { $logs = []; }
}

// ── Cargar empleo a editar ────────────────────────────────────
$empleoEditar = [];
if (($_GET['page'] ?? '') === 'editar_empleo'
    && isset($_SESSION['tipo']) && $_SESSION['tipo'] === 'empresa'
    && isset($_GET['id'])) {
    try {
        $stmt = db()->prepare("SELECT * FROM empleos WHERE id = ? AND empresa_id = ?");
        $stmt->execute([(int)$_GET['id'], $_SESSION['auth_id']]);
        $empleoEditar = $stmt->fetch() ?: [];
        if (!$empleoEditar) {
            $_SESSION['flash']['error'] = 'Empleo no encontrado.';
            header('Location: index.php?page=dashboard'); exit;
        }
    } catch (Exception $e) { $empleoEditar = []; }
}

// ── Acción: Editar empleo (POST) ─────────────────────────────
if (($_POST['action'] ?? '') === 'editar_empleo'
    && isset($_SESSION['tipo']) && $_SESSION['tipo'] === 'empresa') {

    $id    = (int)($_POST['empleo_id'] ?? 0);
    $titulo = trim($_POST['titulo']      ?? '');
    $desc   = trim($_POST['descripcion'] ?? '');
    $req    = trim($_POST['requisitos']  ?? '');
    $ubi    = trim($_POST['ubicacion']   ?? '');
    $sal    = trim($_POST['salario']     ?? '');
    $tipo   = $_POST['tipo'] ?? '';
    $tipos  = ['Tiempo completo','Medio tiempo','Remoto','Híbrido','Freelance'];

    $errores = [];
    if (strlen($titulo) < 5)          $errores[] = 'El título debe tener al menos 5 caracteres.';
    if (strlen($desc) < 10)           $errores[] = 'La descripción debe tener al menos 10 caracteres.';
    if (!in_array($tipo, $tipos))     $errores[] = 'Tipo de empleo no válido.';

    if ($errores) {
        $_SESSION['flash']['error'] = implode(' ', $errores);
        header("Location: index.php?page=editar_empleo&id=$id"); exit;
    }

    try {
        db()->prepare("
            UPDATE empleos
            SET titulo=?, descripcion=?, requisitos=?, ubicacion=?, salario=?, tipo=?
            WHERE id=? AND empresa_id=?
        ")->execute([$titulo, $desc, $req, $ubi, $sal, $tipo, $id, $_SESSION['auth_id']]);
        registrarLog('EDITAR_EMPLEO', "Empleo editado: $titulo");
        $_SESSION['flash']['success'] = 'Empleo actualizado correctamente.';
    } catch (Exception $e) {
        $_SESSION['flash']['error'] = 'Error al actualizar el empleo.';
    }
    header('Location: index.php?page=dashboard'); exit;
}

// ── Acción: Eliminar empleo (GET) ─────────────────────────────
if (($_GET['action'] ?? '') === 'eliminar_empleo'
    && isset($_SESSION['tipo']) && $_SESSION['tipo'] === 'empresa') {

    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        try {
            $chk = db()->prepare("SELECT titulo FROM empleos WHERE id=? AND empresa_id=?");
            $chk->execute([$id, $_SESSION['auth_id']]);
            $emp = $chk->fetch();
            if ($emp) {
                db()->prepare("DELETE FROM empleos WHERE id=? AND empresa_id=?")
                    ->execute([$id, $_SESSION['auth_id']]);
                registrarLog('ELIMINAR_EMPLEO', 'Eliminado: ' . $emp['titulo']);
                $_SESSION['flash']['success'] = 'Empleo eliminado correctamente.';
            } else {
                $_SESSION['flash']['error'] = 'No tienes permiso para eliminar ese empleo.';
            }
        } catch (Exception $e) {
            $_SESSION['flash']['error'] = 'Error al eliminar el empleo.';
        }
    }
    header('Location: index.php?page=dashboard'); exit;
}

// ── Acción: Reporte postulaciones CSV ────────────────────────
if (($_GET['action'] ?? '') === 'reporte_postulaciones'
    && isset($_SESSION['tipo']) && $_SESSION['tipo'] === 'empresa') {
    registrarLog('REPORTE', 'Descargó reporte de postulaciones CSV');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="postulaciones_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Candidato','Email','Empleo','Estado','Fecha','Mensaje'], ';');
    $stmt = db()->prepare("
        SELECT p.id, u.nombre+' '+u.apellido AS candidato, u.email,
               e.titulo AS empleo, p.estado, p.fecha_postulacion, p.mensaje
        FROM postulaciones p
        JOIN usuarios u ON p.usuario_id = u.id
        JOIN empleos e  ON p.empleo_id  = e.id
        WHERE e.empresa_id = ?
        ORDER BY p.fecha_postulacion DESC
    ");
    $stmt->execute([$_SESSION['auth_id']]);
    while ($row = $stmt->fetch()) {
        fputcsv($out, [
            $row['id'], $row['candidato'], $row['email'],
            $row['empleo'], $row['estado'],
            date('d/m/Y H:i', strtotime($row['fecha_postulacion'])),
            $row['mensaje'] ?? ''
        ], ';');
    }
    fclose($out); exit;
}

// ── Acción: Reporte empleos CSV ───────────────────────────────
if (($_GET['action'] ?? '') === 'reporte_empleos'
    && isset($_SESSION['tipo']) && $_SESSION['tipo'] === 'empresa') {
    registrarLog('REPORTE', 'Descargó reporte de empleos CSV');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="empleos_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Título','Tipo','Ubicación','Salario','Activo','Postulaciones','Fecha'], ';');
    $stmt = db()->prepare("
        SELECT e.id, e.titulo, e.tipo, e.ubicacion, e.salario, e.activo,
               e.fecha_publicacion,
               (SELECT COUNT(*) FROM postulaciones p WHERE p.empleo_id=e.id) AS total
        FROM empleos e WHERE e.empresa_id=?
        ORDER BY e.fecha_publicacion DESC
    ");
    $stmt->execute([$_SESSION['auth_id']]);
    while ($row = $stmt->fetch()) {
        fputcsv($out, [
            $row['id'], $row['titulo'], $row['tipo'], $row['ubicacion'] ?? '',
            $row['salario'] ?? '', $row['activo'] ? 'Sí' : 'No',
            $row['total'], date('d/m/Y', strtotime($row['fecha_publicacion']))
        ], ';');
    }
    fclose($out); exit;
}

// ── Acción: Backup SQL ────────────────────────────────────────
if (($_GET['action'] ?? '') === 'backup'
    && isset($_SESSION['tipo']) && $_SESSION['tipo'] === 'empresa') {
    registrarLog('BACKUP', 'Generó backup de la base de datos');
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="backup_web_trabajo_' . date('Ymd_His') . '.sql"');
    echo "-- BACKUP WorkConnect - web_trabajo\n";
    echo "-- Generado: " . date('d/m/Y H:i:s') . "\n";
    echo "USE web_trabajo;\nGO\n\n";
    foreach (['usuarios','empresas','empleos','postulaciones'] as $tabla) {
        echo "-- ---- $tabla ----\n";
        try {
            $rows = db()->query("SELECT * FROM $tabla")->fetchAll();
            if (empty($rows)) { echo "-- (sin datos)\n\n"; continue; }
            $cols = array_keys($rows[0]);
            $colsSinId = array_filter($cols, fn($c) => $c !== 'id');
            foreach ($rows as $row) {
                $vals = [];
                foreach ($cols as $col) {
                    if ($col === 'id') continue;
                    $v = $row[$col];
                    $vals[] = $v === null ? 'NULL' : "N'" . str_replace("'","''",$v) . "'";
                }
                echo "INSERT INTO $tabla (" . implode(',', $colsSinId) . ") VALUES (" . implode(',', $vals) . ");\n";
            }
        } catch (Exception $e) { echo "-- Error en $tabla\n"; }
        echo "GO\n\n";
    }
    exit;
}

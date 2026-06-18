<?php
// ============================================================
//   WORKCONNECT - Portal de Empleo
//   Conexión a SQL Server definida en db.php
// ============================================================
define('SITE_NAME', 'WorkConnect');
require_once __DIR__ . '/db.php';
 
 
// ============================================================
//   FUNCIONES DE VALIDACIÓN
// ============================================================
function validarTexto($texto, $minimo = 3, $maximo = 255) {
    $texto = trim($texto);
    return strlen($texto) >= $minimo && strlen($texto) <= $maximo;
}
 
function validarEmail($email) {
    $email = trim($email);
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}
 
function validarPassword($pass) {
    return strlen($pass) >= 6;
}
 
function validarUrl($url) {
    if (empty($url)) return true;
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}
 
// ============================================================
//   SESIÓN E HELPERS
// ============================================================
session_start();
require_once __DIR__ . '/funcionalidades.php';
 
function loggedIn(): bool   { return isset($_SESSION['tipo']); }
function isUser(): bool     { return ($_SESSION['tipo'] ?? '') === 'usuario'; }
function isCompany(): bool  { return ($_SESSION['tipo'] ?? '') === 'empresa'; }
function authId(): ?int     { return $_SESSION['auth_id'] ?? null; }
function authName(): string { return $_SESSION['auth_name'] ?? ''; }
 
function go(string $url): void { header("Location: $url"); exit; }
 
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
 
function flash(string $key): string {
    $msg = $_SESSION['flash'][$key] ?? '';
    unset($_SESSION['flash'][$key]);
    return $msg;
}
function setFlash(string $key, string $msg): void { $_SESSION['flash'][$key] = $msg; }
 
// ============================================================
//   MANEJO DE ACCIONES (POST / GET action)
// ============================================================
 
/* ---------- LOGOUT ---------- */
if (($_GET['action'] ?? '') === 'logout') {
    session_destroy();
    go('index.php');
}
 
/* ---------- LOGIN USUARIO ---------- */
if (($_POST['action'] ?? '') === 'login_usuario') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $stmt  = db()->prepare("SELECT id, nombre, password FROM usuarios WHERE email = ?");
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if ($row && password_verify($pass, $row['password'])) {
        $_SESSION = ['tipo' => 'usuario', 'auth_id' => $row['id'], 'auth_name' => $row['nombre']];
        registrarLog('LOGIN', 'Inicio de sesión candidato');
        go('index.php?page=dashboard');
    }
    setFlash('error', 'Correo o contraseña incorrectos.');
    go('index.php?page=login');
}
 
/* ---------- LOGIN EMPRESA ---------- */
if (($_POST['action'] ?? '') === 'login_empresa') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $stmt  = db()->prepare("SELECT id, nombre, password FROM empresas WHERE email = ?");
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if ($row && password_verify($pass, $row['password'])) {
        $_SESSION = ['tipo' => 'empresa', 'auth_id' => $row['id'], 'auth_name' => $row['nombre']];
        registrarLog('LOGIN', 'Inicio de sesión empresa');
        go('index.php?page=dashboard');
    }
    setFlash('error', 'Correo o contraseña incorrectos.');
    go('index.php?page=login_empresa');
}
 
/* ---------- REGISTRO USUARIO ---------- */
if (($_POST['action'] ?? '') === 'register_usuario') {
    $data = [
        trim($_POST['nombre']    ?? ''),
        trim($_POST['apellido']  ?? ''),
        trim($_POST['email']     ?? ''),
        password_hash($_POST['password'] ?? '', PASSWORD_DEFAULT),
        trim($_POST['titulo']    ?? ''),
        trim($_POST['ubicacion'] ?? ''),
    ];
    $errores = [];
    if (!validarTexto($data[0], 2, 100))           $errores[] = 'El nombre debe tener entre 2 y 100 caracteres.';
    if (!validarTexto($data[1], 2, 100))           $errores[] = 'El apellido debe tener entre 2 y 100 caracteres.';
    if (!validarEmail($data[2]))                    $errores[] = 'El correo electrónico no es válido.';
    if (!validarPassword($_POST['password'] ?? '')) $errores[] = 'La contraseña debe tener al menos 6 caracteres.';
    if ($errores) { setFlash('error', implode(' ', $errores)); go('index.php?page=register_usuario'); }
    try {
        db()->prepare("INSERT INTO usuarios (nombre,apellido,email,password,titulo,ubicacion) VALUES (?,?,?,?,?,?)")->execute($data);
        registrarLog('REGISTRO', 'Nuevo candidato registrado');
        setFlash('success', '¡Cuenta creada! Ya puedes iniciar sesión.');
        go('index.php?page=login');
    } catch (PDOException $e) {
        setFlash('error', 'El correo ya está registrado.');
        go('index.php?page=register_usuario');
    }
}
 
/* ---------- REGISTRO EMPRESA ---------- */
if (($_POST['action'] ?? '') === 'register_empresa') {
    $data = [
        trim($_POST['nombre']    ?? ''),
        trim($_POST['email']     ?? ''),
        password_hash($_POST['password'] ?? '', PASSWORD_DEFAULT),
        trim($_POST['sector']    ?? ''),
        trim($_POST['ubicacion'] ?? ''),
        trim($_POST['sitio_web'] ?? ''),
    ];
    $errores = [];
    if (!validarTexto($data[0], 2, 200))           $errores[] = 'El nombre debe tener entre 2 y 200 caracteres.';
    if (!validarEmail($data[1]))                    $errores[] = 'El correo electrónico no es válido.';
    if (!validarPassword($_POST['password'] ?? '')) $errores[] = 'La contraseña debe tener al menos 6 caracteres.';
    if (!validarUrl($data[5]))                      $errores[] = 'El sitio web no tiene formato válido (ej: https://miweb.com).';
    if ($errores) { setFlash('error', implode(' ', $errores)); go('index.php?page=register_empresa'); }
    try {
        db()->prepare("INSERT INTO empresas (nombre,email,password,sector,ubicacion,sitio_web) VALUES (?,?,?,?,?,?)")->execute($data);
        registrarLog('REGISTRO', 'Nueva empresa registrada');
        setFlash('success', '¡Empresa registrada! Ya puedes iniciar sesión.');
        go('index.php?page=login_empresa');
    } catch (PDOException $e) {
        setFlash('error', 'El correo ya está registrado.');
        go('index.php?page=register_empresa');
    }
}
 
/* ---------- PUBLICAR EMPLEO ---------- */
if (($_POST['action'] ?? '') === 'post_job' && isCompany()) {
    $data = [
        authId(),
        trim($_POST['titulo']      ?? ''),
        trim($_POST['descripcion'] ?? ''),
        trim($_POST['requisitos']  ?? ''),
        trim($_POST['ubicacion']   ?? ''),
        trim($_POST['salario']     ?? ''),
        $_POST['tipo'] ?? 'Tiempo completo',
    ];
    $errores = [];
    if (!validarTexto($data[1], 5, 200))    $errores[] = 'El título debe tener entre 5 y 200 caracteres.';
    if (!validarTexto($data[2], 10, 4000))  $errores[] = 'La descripción debe tener al menos 10 caracteres.';
    $tiposValidos = ['Tiempo completo','Medio tiempo','Remoto','Híbrido','Freelance'];
    if (!in_array($data[6], $tiposValidos)) $errores[] = 'Tipo de empleo no válido.';
    if ($errores) { setFlash('error', implode(' ', $errores)); go('index.php?page=post_job'); }
    db()->prepare("INSERT INTO empleos (empresa_id,titulo,descripcion,requisitos,ubicacion,salario,tipo) VALUES (?,?,?,?,?,?,?)")->execute($data);
    registrarLog('PUBLICAR_EMPLEO', 'Empleo publicado: ' . $data[1]);
    setFlash('success', '¡Empleo publicado correctamente!');
    go('index.php?page=dashboard');
}
 
/* ---------- POSTULAR A EMPLEO ---------- */
if (($_POST['action'] ?? '') === 'postular' && isUser()) {
    try {
        db()->prepare("INSERT INTO postulaciones (usuario_id,empleo_id,mensaje) VALUES (?,?,?)")
            ->execute([authId(), (int)$_POST['empleo_id'], trim($_POST['mensaje'] ?? '')]);
        setFlash('success', '¡Postulación enviada exitosamente!');
    } catch (PDOException $e) {
        setFlash('error', 'Ya te has postulado a este empleo.');
    }
    go('index.php?page=dashboard');
}
 
/* ---------- ACTUALIZAR ESTADO POSTULACIÓN (empresa) ---------- */
if (($_POST['action'] ?? '') === 'update_estado' && isCompany()) {
    $allowed = ['Pendiente','En revisión','Aceptado','Rechazado'];
    $estado  = in_array($_POST['estado'] ?? '', $allowed) ? $_POST['estado'] : 'Pendiente';
    db()->prepare("UPDATE postulaciones SET estado=? WHERE id=?")->execute([$estado, (int)$_POST['post_id']]);
    setFlash('success', 'Estado actualizado.');
    go('index.php?page=postulantes&empleo=' . (int)$_POST['empleo_id']);
}
 
// ============================================================
//   ROUTING
// ============================================================
$page = $_GET['page'] ?? (loggedIn() ? 'dashboard' : 'home');
 
$protectedPages = ['dashboard','post_job','postulantes','logs','editar_empleo'];
if (in_array($page, $protectedPages) && !loggedIn()) { go('index.php?page=login'); }
if ($page === 'post_job' && !isCompany())             { go('index.php?page=dashboard'); }
if ($page === 'postulantes' && !isCompany())          { go('index.php?page=dashboard'); }
 
// ============================================================
//   CARGAR DATOS SEGÚN LA PÁGINA
// ============================================================
$jobs = $myJobs = $myApps = $postulantes = $jobDetail = [];
$busqueda = trim($_GET['q'] ?? '');
 
if (loggedIn()) {
    /* Empleos (con búsqueda) */
    if ($busqueda) {
        $stmt = db()->prepare("
            SELECT e.*, emp.nombre AS empresa_nombre, emp.sector
            FROM empleos e
            JOIN empresas emp ON e.empresa_id = emp.id
            WHERE e.activo = 1 AND (
                e.titulo LIKE ? OR e.descripcion LIKE ? OR emp.nombre LIKE ? OR e.ubicacion LIKE ?
            )
            ORDER BY e.fecha_publicacion DESC");
        $q = "%$busqueda%";
        $stmt->execute([$q,$q,$q,$q]);
    } else {
        $stmt = db()->prepare("
            SELECT e.*, emp.nombre AS empresa_nombre, emp.sector
            FROM empleos e
            JOIN empresas emp ON e.empresa_id = emp.id
            WHERE e.activo = 1
            ORDER BY e.fecha_publicacion DESC");
        $stmt->execute();
    }
    $jobs = $stmt->fetchAll();
 
    /* Datos de usuario */
    if (isUser()) {
        $stmt = db()->prepare("
            SELECT p.id, p.estado, p.fecha_postulacion,
                   e.titulo AS empleo_titulo, e.tipo, e.ubicacion AS empleo_ubicacion,
                   emp.nombre AS empresa_nombre
            FROM postulaciones p
            JOIN empleos e  ON p.empleo_id  = e.id
            JOIN empresas emp ON e.empresa_id = emp.id
            WHERE p.usuario_id = ?
            ORDER BY p.fecha_postulacion DESC");
        $stmt->execute([authId()]);
        $myApps = $stmt->fetchAll();
    }
 
    /* Datos de empresa */
    if (isCompany()) {
        $stmt = db()->prepare("
            SELECT e.*,
                   (SELECT COUNT(*) FROM postulaciones p WHERE p.empleo_id = e.id) AS total_postulantes
            FROM empleos e
            WHERE e.empresa_id = ?
            ORDER BY e.fecha_publicacion DESC");
        $stmt->execute([authId()]);
        $myJobs = $stmt->fetchAll();
    }
}
 
/* Detalle de empleo */
if ($page === 'job_detail' && isset($_GET['id'])) {
    $stmt = db()->prepare("
        SELECT e.*, emp.nombre AS empresa_nombre, emp.sector, emp.ubicacion AS empresa_ub, emp.sitio_web
        FROM empleos e JOIN empresas emp ON e.empresa_id = emp.id WHERE e.id = ?");
    $stmt->execute([(int)$_GET['id']]);
    $jobDetail = $stmt->fetch() ?: [];
 
    $yaPostulo = false;
    if (isUser() && $jobDetail) {
        $chk = db()->prepare("SELECT id FROM postulaciones WHERE usuario_id=? AND empleo_id=?");
        $chk->execute([authId(), (int)$_GET['id']]);
        $yaPostulo = (bool)$chk->fetch();
    }
}
 
/* Lista de postulantes (empresa) */
if ($page === 'postulantes' && isCompany() && isset($_GET['empleo'])) {
    $empleoId = (int)$_GET['empleo'];
    $stmt = db()->prepare("
        SELECT p.id, p.estado, p.mensaje, p.fecha_postulacion,
               u.nombre, u.apellido, u.email, u.titulo, u.ubicacion
        FROM postulaciones p JOIN usuarios u ON p.usuario_id = u.id
        WHERE p.empleo_id = ?
        ORDER BY p.fecha_postulacion ASC");
    $stmt->execute([$empleoId]);
    $postulantes = $stmt->fetchAll();
 
    $empInfo = db()->prepare("SELECT titulo FROM empleos WHERE id=? AND empresa_id=?");
    $empInfo->execute([$empleoId, authId()]);
    $empInfo = $empInfo->fetch();
    if (!$empInfo) go('index.php?page=dashboard');
}
 
// ============================================================
//   HELPERS DE VISTA
// ============================================================
function badgeEstado(string $estado): string {
    $map = [
        'Pendiente'   => 'badge-pending',
        'En revisión' => 'badge-review',
        'Aceptado'    => 'badge-accepted',
        'Rechazado'   => 'badge-rejected',
    ];
    $cls = $map[$estado] ?? 'badge-pending';
    return "<span class='badge $cls'>" . h($estado) . "</span>";
}
 
function badgeTipo(string $tipo): string {
    $map = [
        'Remoto'        => '#10b981',
        'Híbrido'       => '#6366f1',
        'Tiempo completo' => '#0a66c2',
        'Medio tiempo'  => '#f59e0b',
        'Freelance'     => '#ec4899',
    ];
    $color = $map[$tipo] ?? '#64748b';
    return "<span class='tipo-badge' style='background:" . h($color) . "20;color:" . h($color) . ";border:1px solid " . h($color) . "40'>" . h($tipo) . "</span>";
}
 
function timeDiff(string $fecha): string {
    $diff = time() - strtotime($fecha);
    if ($diff < 3600)   return 'Hace ' . floor($diff/60) . ' min';
    if ($diff < 86400)  return 'Hace ' . floor($diff/3600) . ' horas';
    if ($diff < 604800) return 'Hace ' . floor($diff/86400) . ' días';
    return date('d/m/Y', strtotime($fecha));
}
 
// ============================================================
//   AVATAR (iniciales)
// ============================================================
function avatar(string $name, string $size = '40px'): string {
    $words = explode(' ', trim($name));
    $initials = strtoupper(substr($words[0], 0, 1) . (substr($words[1] ?? '', 0, 1)));
    $colors = ['#0a66c2','#10b981','#6366f1','#f59e0b','#ec4899','#14b8a6'];
    $color  = $colors[ord($name[0]) % count($colors)];
    return "<div class='avatar' style='width:$size;height:$size;min-width:$size;background:$color;font-size:calc($size * 0.4)'>" . h($initials) . "</div>";
}
 
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= SITE_NAME ?><?= $page === 'job_detail' && $jobDetail ? ' · ' . h($jobDetail['titulo']) : '' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
/* ============================================================
   RESET & BASE
============================================================ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --blue:#0a66c2;--blue-dark:#004d9f;--blue-light:#e8f1fb;
  --green:#10b981;--red:#ef4444;--amber:#f59e0b;
  --text:#1e293b;--muted:#64748b;--border:#e2e8f0;
  --bg:#f1f5f9;--white:#ffffff;
  --card-shadow:0 1px 3px rgba(0,0,0,.08),0 4px 16px rgba(0,0,0,.04);
  --card-shadow-hover:0 4px 12px rgba(0,0,0,.12),0 12px 32px rgba(0,0,0,.08);
  --radius:14px;--radius-sm:8px;
  --nav-h:64px;
}
html{scroll-behavior:smooth}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;line-height:1.6}
a{color:var(--blue);text-decoration:none}
a:hover{text-decoration:underline}
img{max-width:100%}
input,textarea,select{font-family:inherit}
 
/* ============================================================
   NAVBAR
============================================================ */
.navbar{
  position:sticky;top:0;z-index:100;
  background:var(--white);
  border-bottom:1px solid var(--border);
  height:var(--nav-h);
  display:flex;align-items:center;
  padding:0 1.5rem;
  gap:1.5rem;
}
.nav-logo{
  font-family:'Playfair Display',serif;
  font-size:1.5rem;font-weight:600;
  color:var(--blue);
  letter-spacing:-.5px;
  display:flex;align-items:center;gap:.4rem;
  text-decoration:none;flex-shrink:0;
}
.nav-logo svg{width:28px;height:28px}
.nav-search{
  flex:1;max-width:420px;
  display:flex;align-items:center;
  background:var(--bg);border:1.5px solid var(--border);
  border-radius:30px;padding:0 1rem;gap:.5rem;
  transition:border-color .2s,box-shadow .2s;
}
.nav-search:focus-within{border-color:var(--blue);box-shadow:0 0 0 3px var(--blue-light)}
.nav-search input{border:0;background:transparent;flex:1;padding:.5rem 0;outline:none;font-size:.9rem;color:var(--text)}
.nav-search svg{color:var(--muted);flex-shrink:0}
.nav-links{display:flex;align-items:center;gap:.25rem;margin-left:auto}
.nav-btn{
  display:flex;flex-direction:column;align-items:center;
  padding:.4rem .75rem;border-radius:var(--radius-sm);
  font-size:.75rem;color:var(--muted);font-weight:500;
  cursor:pointer;text-decoration:none;transition:color .2s,background .2s;
  border:none;background:none;
}
.nav-btn svg{width:22px;height:22px;margin-bottom:2px}
.nav-btn:hover,.nav-btn.active{color:var(--blue);background:var(--blue-light);text-decoration:none}
.nav-btn.active{color:var(--blue)}
.nav-divider{width:1px;height:30px;background:var(--border);margin:0 .25rem}
.nav-profile{display:flex;align-items:center;gap:.5rem;padding:.4rem .75rem;border-radius:var(--radius-sm);cursor:pointer;border:none;background:none;font-size:.85rem;font-weight:500;color:var(--text);transition:background .2s}
.nav-profile:hover{background:var(--bg)}
 
/* ============================================================
   LAYOUT
============================================================ */
.page-wrap{max-width:1200px;margin:0 auto;padding:2rem 1.5rem}
.grid-layout{display:grid;grid-template-columns:1fr 340px;gap:1.5rem;align-items:start}
.grid-layout-3{display:grid;grid-template-columns:280px 1fr 280px;gap:1.5rem;align-items:start}
@media(max-width:900px){.grid-layout,.grid-layout-3{grid-template-columns:1fr}}
 
/* ============================================================
   CARDS
============================================================ */
.card{
  background:var(--white);
  border-radius:var(--radius);
  box-shadow:var(--card-shadow);
  overflow:hidden;
  transition:box-shadow .25s,transform .25s;
}
.card:hover{box-shadow:var(--card-shadow-hover);transform:translateY(-1px)}
.card-header{padding:1.25rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:1rem}
.card-body{padding:1.5rem}
.card-title{font-size:1.05rem;font-weight:600;color:var(--text)}
 
/* ============================================================
   AVATAR
============================================================ */
.avatar{
  border-radius:50%;display:flex;align-items:center;justify-content:center;
  color:#fff;font-weight:700;letter-spacing:-.5px;flex-shrink:0;
}
 
/* ============================================================
   JOB CARD
============================================================ */
.job-card{
  background:var(--white);border-radius:var(--radius);
  box-shadow:var(--card-shadow);padding:1.25rem 1.5rem;
  display:flex;flex-direction:column;gap:.75rem;
  transition:box-shadow .25s,transform .25s;
  cursor:pointer;
}
.job-card:hover{box-shadow:var(--card-shadow-hover);transform:translateY(-2px)}
.job-card-header{display:flex;align-items:flex-start;gap:1rem}
.job-card-info{flex:1;min-width:0}
.job-title{font-size:1.05rem;font-weight:700;color:var(--text);line-height:1.3}
.job-title:hover{color:var(--blue)}
.job-company{font-size:.88rem;color:var(--muted);margin-top:.15rem}
.job-meta{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}
.job-meta span{font-size:.8rem;color:var(--muted);display:flex;align-items:center;gap:.25rem}
.job-desc{font-size:.88rem;color:var(--muted);line-height:1.6;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.job-footer{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap}
.job-salary{font-size:.9rem;font-weight:600;color:var(--green)}
.job-time{font-size:.78rem;color:var(--muted)}
.jobs-list{display:flex;flex-direction:column;gap:1rem}
 
/* ============================================================
   TIPO BADGE
============================================================ */
.tipo-badge{padding:.2rem .65rem;border-radius:30px;font-size:.75rem;font-weight:600}
 
/* ============================================================
   ESTADO BADGE
============================================================ */
.badge{padding:.2rem .7rem;border-radius:30px;font-size:.75rem;font-weight:600}
.badge-pending {background:#fef3c7;color:#92400e}
.badge-review  {background:#e0e7ff;color:#3730a3}
.badge-accepted{background:#d1fae5;color:#065f46}
.badge-rejected{background:#fee2e2;color:#991b1b}
 
/* ============================================================
   BOTONES
============================================================ */
.btn{
  display:inline-flex;align-items:center;justify-content:center;gap:.45rem;
  padding:.6rem 1.4rem;border-radius:30px;
  font-size:.9rem;font-weight:600;cursor:pointer;
  border:none;transition:all .2s;white-space:nowrap;
  font-family:inherit;text-decoration:none;
}
.btn-primary{background:var(--blue);color:#fff}
.btn-primary:hover{background:var(--blue-dark);color:#fff;text-decoration:none}
.btn-outline{background:transparent;color:var(--blue);border:2px solid var(--blue)}
.btn-outline:hover{background:var(--blue-light);text-decoration:none}
.btn-sm{padding:.4rem 1rem;font-size:.82rem}
.btn-danger{background:var(--red);color:#fff}
.btn-danger:hover{background:#dc2626;text-decoration:none}
.btn-success{background:var(--green);color:#fff}
.btn-success:hover{background:#059669;text-decoration:none}
.btn-full{width:100%}
.btn-ghost{background:transparent;color:var(--muted);padding:.4rem .75rem}
.btn-ghost:hover{background:var(--bg);color:var(--text);text-decoration:none}
 
/* ============================================================
   FORMS
============================================================ */
.form-group{margin-bottom:1.1rem}
.form-group label{display:block;font-size:.85rem;font-weight:600;color:var(--text);margin-bottom:.4rem}
.form-control{
  width:100%;padding:.65rem .9rem;
  border:1.5px solid var(--border);border-radius:var(--radius-sm);
  font-size:.9rem;color:var(--text);background:var(--white);
  transition:border-color .2s,box-shadow .2s;outline:none;
  font-family:inherit;
}
.form-control:focus{border-color:var(--blue);box-shadow:0 0 0 3px var(--blue-light)}
.form-control::placeholder{color:#94a3b8}
textarea.form-control{resize:vertical;min-height:100px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
@media(max-width:600px){.form-grid{grid-template-columns:1fr}}
.form-hint{font-size:.78rem;color:var(--muted);margin-top:.3rem}
 
/* ============================================================
   AUTH PAGES
============================================================ */
.auth-wrapper{
  min-height:100vh;
  background:linear-gradient(135deg,#0a1628 0%,#0a3d6b 50%,#0a66c2 100%);
  display:flex;align-items:center;justify-content:center;
  padding:2rem;position:relative;overflow:hidden;
}
.auth-bg-shape{
  position:absolute;width:600px;height:600px;
  border-radius:50%;opacity:.07;background:#fff;
}
.auth-bg-shape-1{top:-200px;left:-200px;width:700px;height:700px}
.auth-bg-shape-2{bottom:-250px;right:-200px;width:800px;height:800px}
.auth-container{
  position:relative;z-index:1;
  display:grid;grid-template-columns:1fr 420px;gap:4rem;
  align-items:center;max-width:900px;width:100%;
}
@media(max-width:750px){.auth-container{grid-template-columns:1fr}}
.auth-hero h1{
  font-family:'Playfair Display',serif;
  font-size:3rem;color:#fff;line-height:1.2;margin-bottom:1rem;
}
.auth-hero p{color:rgba(255,255,255,.75);font-size:1.05rem;max-width:380px}
.auth-hero-stats{display:flex;gap:2rem;margin-top:2rem}
.auth-stat{color:rgba(255,255,255,.9)}
.auth-stat strong{display:block;font-size:1.8rem;font-weight:700;font-family:'Playfair Display',serif}
.auth-stat span{font-size:.85rem;color:rgba(255,255,255,.6)}
.auth-card{background:var(--white);border-radius:20px;padding:2.5rem;box-shadow:0 25px 60px rgba(0,0,0,.3)}
.auth-card h2{font-size:1.5rem;font-weight:700;margin-bottom:.25rem}
.auth-card .subtitle{color:var(--muted);font-size:.9rem;margin-bottom:1.75rem}
.auth-tabs{display:flex;gap:.5rem;margin-bottom:1.75rem;background:var(--bg);border-radius:var(--radius-sm);padding:.3rem}
.auth-tab{
  flex:1;text-align:center;padding:.5rem;border-radius:6px;
  font-size:.85rem;font-weight:600;cursor:pointer;
  color:var(--muted);text-decoration:none;transition:all .2s;
}
.auth-tab.active,.auth-tab:hover{background:#fff;color:var(--blue);box-shadow:0 1px 4px rgba(0,0,0,.12);text-decoration:none}
.auth-divider{display:flex;align-items:center;gap:.75rem;margin:1.25rem 0;color:var(--muted);font-size:.82rem}
.auth-divider::before,.auth-divider::after{content:'';flex:1;height:1px;background:var(--border)}
.auth-footer{text-align:center;margin-top:1.25rem;font-size:.85rem;color:var(--muted)}
 
/* ============================================================
   ALERTS
============================================================ */
.alert{
  padding:.85rem 1.1rem;border-radius:var(--radius-sm);
  font-size:.88rem;font-weight:500;margin-bottom:1rem;
  display:flex;align-items:flex-start;gap:.6rem;
}
.alert-error  {background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
.alert-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0}
.alert-info   {background:#e0e7ff;color:#3730a3;border:1px solid #c7d2fe}
 
/* ============================================================
   HOME (landing sin login)
============================================================ */
.home-hero{
  text-align:center;padding:5rem 1.5rem 4rem;
  background:linear-gradient(180deg,#0a1628 0%,#0a3d6b 60%,var(--bg) 100%);
}
.home-hero h1{
  font-family:'Playfair Display',serif;
  font-size:clamp(2.2rem,5vw,3.5rem);color:#fff;
  line-height:1.15;margin-bottom:1rem;
}
.home-hero p{color:rgba(255,255,255,.75);font-size:1.1rem;max-width:480px;margin:0 auto 2.5rem}
.home-search{
  display:flex;max-width:560px;margin:0 auto 3rem;
  background:#fff;border-radius:40px;overflow:hidden;
  box-shadow:0 8px 32px rgba(0,0,0,.25);
}
.home-search input{flex:1;padding:.85rem 1.5rem;border:none;outline:none;font-size:.95rem;color:var(--text)}
.home-cta-btns{display:flex;gap:1rem;justify-content:center;flex-wrap:wrap}
.home-cta-btns .btn{padding:.75rem 2rem;font-size:1rem}
 
/* ============================================================
   PROFILE SIDEBAR CARD
============================================================ */
.profile-card .cover{
  height:70px;
  background:linear-gradient(135deg,#0a66c2,#10b981);
}
.profile-card .profile-info{padding:0 1.25rem 1.25rem;text-align:center}
.profile-card .avatar-wrap{margin:-28px auto .5rem;display:flex;justify-content:center}
.profile-card .avatar{width:56px;height:56px;font-size:1.3rem}
.profile-card .p-name{font-weight:700;font-size:1rem}
.profile-card .p-title{font-size:.82rem;color:var(--muted);margin-top:.15rem}
.profile-card .p-stats{border-top:1px solid var(--border);margin-top:1rem;padding-top:1rem;display:flex;justify-content:space-around;gap:.5rem}
.profile-card .p-stat{text-align:center}
.profile-card .p-stat strong{display:block;font-size:1.1rem;font-weight:700;color:var(--blue)}
.profile-card .p-stat span{font-size:.75rem;color:var(--muted)}
 
/* ============================================================
   POSTULANTES TABLE
============================================================ */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:.88rem}
th{background:var(--bg);padding:.75rem 1rem;text-align:left;font-weight:600;font-size:.8rem;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
td{padding:.9rem 1rem;border-bottom:1px solid var(--border);vertical-align:top}
tr:last-child td{border-bottom:none}
tr:hover td{background:#f8fafc}
 
/* ============================================================
   STAT BOXES
============================================================ */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:1rem;margin-bottom:1.5rem}
.stat-box{background:#fff;border-radius:var(--radius);box-shadow:var(--card-shadow);padding:1.25rem;text-align:center}
.stat-box .num{font-size:2rem;font-weight:800;color:var(--blue);font-family:'Playfair Display',serif}
.stat-box .lbl{font-size:.8rem;color:var(--muted);margin-top:.2rem;font-weight:500}
 
/* ============================================================
   SECTION HEADERS
============================================================ */
.section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;gap:1rem}
.section-title{font-size:1.15rem;font-weight:700}
 
/* ============================================================
   EMPTY STATE
============================================================ */
.empty-state{text-align:center;padding:3rem 1.5rem;color:var(--muted)}
.empty-state svg{width:64px;height:64px;margin-bottom:1rem;opacity:.35}
.empty-state p{font-size:.95rem}
 
/* ============================================================
   MISC
============================================================ */
.chip{display:inline-block;background:var(--blue-light);color:var(--blue);padding:.25rem .75rem;border-radius:30px;font-size:.78rem;font-weight:600}
.text-muted{color:var(--muted)}
.text-sm{font-size:.85rem}
.mt-1{margin-top:.5rem}.mt-2{margin-top:1rem}.mt-3{margin-top:1.5rem}
.mb-1{margin-bottom:.5rem}.mb-2{margin-bottom:1rem}.mb-3{margin-bottom:1.5rem}
.flex{display:flex}.items-center{align-items:center}.gap-1{gap:.5rem}.gap-2{gap:1rem}
.ml-auto{margin-left:auto}
hr.sep{border:none;border-top:1px solid var(--border);margin:1.25rem 0}
 
/* ============================================================
   RESPONSIVE NAV
============================================================ */
@media(max-width:640px){
  .nav-links .nav-btn span{display:none}
  .nav-search{max-width:180px}
}
 
/* ============================================================
   SCROLL SUAVE
============================================================ */
.fade-in{animation:fadeIn .35s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
</style>
</head>
<body>
 
<?php
// ============================================================
//   NAVBAR (solo si está logueado o en páginas que no son auth)
// ============================================================
$authPages = ['login','login_empresa','register_usuario','register_empresa','home'];
$showNav   = loggedIn() && !in_array($page, $authPages);
?>
 
<?php if ($showNav): ?>
<nav class="navbar">
  <a href="index.php?page=dashboard" class="nav-logo">
    <svg viewBox="0 0 24 24" fill="var(--blue)"><path d="M20 6h-2.18c.07-.44.18-.88.18-1.36C18 2.52 15.5 1 13 1c-1.45 0-2.78.56-3.77 1.46L8 3.66 6.77 2.46C5.78 1.56 4.45 1 3 1 .5 1-2 2.52-2 4.64c0 .48.11.92.18 1.36H-4A2 2 0 0 0-6 8v12a2 2 0 0 0 2 2h24a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2zM13 3c1.28 0 3 .81 3 1.64 0 .75-1.42 2.36-4 3.73C9.42 7 8 5.39 8 4.64 8 3.81 9.72 3 11 3h2z"/></svg>
    <?= SITE_NAME ?>
  </a>
 
  <form method="GET" action="index.php" class="nav-search">
    <input type="hidden" name="page" value="dashboard">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
    <input type="text" name="q" placeholder="Buscar empleos, empresas…" value="<?= h($busqueda) ?>">
  </form>
 
  <div class="nav-links">
    <a href="index.php?page=dashboard" class="nav-btn <?= $page==='dashboard'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      <span>Empleos</span>
    </a>
    <?php if (isCompany()): ?>
    <a href="index.php?page=post_job" class="nav-btn <?= $page==='post_job'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
      <span>Publicar</span>
    </a>
    <?php endif; ?>
    <div class="nav-divider"></div>
    <button class="nav-profile" onclick="window.location='index.php?action=logout'">
      <?= avatar(authName(), '32px') ?>
      <span style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h(authName()) ?></span>
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--muted)"><polyline points="6 9 12 15 18 9"/></svg>
    </button>
    <a href="index.php?action=logout" class="nav-btn" title="Salir">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      <span>Salir</span>
    </a>
  </div>
</nav>
<?php endif; ?>
 
<!-- ============================================================
     PÁGINAS
============================================================ -->
 
<?php /* ===== HOME (landing) ===== */ if ($page === 'home' && !loggedIn()): ?>
<div class="home-hero">
  <h1>Tu próxima oportunidad<br>te está esperando</h1>
  <p>Conectamos profesionales con las mejores empresas. Miles de empleos disponibles.</p>
  <div class="home-search">
    <input type="text" id="heroSearch" placeholder="¿Qué empleo buscas? (ej: Python, Diseñador…)">
    <button class="btn btn-primary" style="border-radius:0 40px 40px 0;padding:.85rem 1.75rem"
      onclick="location.href='index.php?page=login'">Buscar</button>
  </div>
  <div class="home-cta-btns">
    <a href="index.php?page=login" class="btn btn-primary">Soy candidato</a>
    <a href="index.php?page=login_empresa" class="btn" style="background:rgba(255,255,255,.15);color:#fff;border:2px solid rgba(255,255,255,.4)">Soy empresa</a>
  </div>
</div>
<div class="page-wrap" style="max-width:900px">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.5rem;margin-bottom:3rem">
    <?php
    $features = [
      ['🎯','Ofertas verificadas','Todos los empleos son revisados y provienen de empresas reales.'],
      ['⚡','Aplicación rápida','Postúlate en segundos con tu perfil guardado.'],
      ['🔔','Alertas en tiempo real','Recibe notificaciones cuando una empresa revise tu perfil.'],
      ['🏢','Red de empresas','Más de cientos de empresas confían en nuestra plataforma.'],
    ];
    foreach ($features as [$icon,$title,$desc]):
    ?>
    <div class="card fade-in" style="padding:1.5rem;text-align:center">
      <div style="font-size:2.5rem;margin-bottom:.75rem"><?= $icon ?></div>
      <h3 style="font-size:1rem;margin-bottom:.5rem"><?= $title ?></h3>
      <p style="font-size:.85rem;color:var(--muted)"><?= $desc ?></p>
    </div>
    <?php endforeach; ?>
  </div>
  <div style="text-align:center;padding:2rem 0">
    <h2 style="font-family:'Playfair Display',serif;font-size:1.8rem;margin-bottom:.75rem">¿Listo para empezar?</h2>
    <div class="flex gap-2" style="justify-content:center;flex-wrap:wrap">
      <a href="index.php?page=register_usuario" class="btn btn-primary">Crear cuenta gratis</a>
      <a href="index.php?page=register_empresa" class="btn btn-outline">Registrar empresa</a>
    </div>
  </div>
</div>
 
<?php /* ===== LOGIN USUARIO ===== */ elseif ($page === 'login'): ?>
<div class="auth-wrapper">
  <div class="auth-bg-shape auth-bg-shape-1"></div>
  <div class="auth-bg-shape auth-bg-shape-2"></div>
  <div class="auth-container">
    <div class="auth-hero">
      <div style="font-family:'Playfair Display',serif;font-size:2rem;color:#fff;margin-bottom:.5rem"><?= SITE_NAME ?></div>
      <h1>Encuentra el empleo que mereces</h1>
      <p>Miles de oportunidades laborales esperan por ti. Da el siguiente paso en tu carrera.</p>
      <div class="auth-hero-stats">
        <div class="auth-stat"><strong>500+</strong><span>Empresas activas</span></div>
        <div class="auth-stat"><strong>2K+</strong><span>Empleos publicados</span></div>
        <div class="auth-stat"><strong>10K+</strong><span>Candidatos</span></div>
      </div>
    </div>
    <div class="auth-card fade-in">
      <div class="auth-tabs">
        <a href="index.php?page=login" class="auth-tab active">Candidato</a>
        <a href="index.php?page=login_empresa" class="auth-tab">Empresa</a>
      </div>
      <h2>Iniciar sesión</h2>
      <p class="subtitle">Bienvenido de vuelta</p>
      <?php $err=flash('error');$ok=flash('success'); ?>
      <?php if ($err): ?><div class="alert alert-error">⚠ <?= h($err) ?></div><?php endif; ?>
      <?php if ($ok):  ?><div class="alert alert-success">✓ <?= h($ok) ?></div><?php endif; ?>
      <form method="POST">
        <input type="hidden" name="action" value="login_usuario">
        <div class="form-group">
          <label>Correo electrónico</label>
          <input type="email" name="email" class="form-control" placeholder="tu@correo.com" required>
        </div>
        <div class="form-group">
          <label>Contraseña</label>
          <input type="password" name="password" class="form-control" placeholder="••••••••" required>
        </div>
        <button type="submit" class="btn btn-primary btn-full" style="margin-top:.5rem">Entrar</button>
      </form>
      <div class="auth-footer">
        ¿No tienes cuenta? <a href="index.php?page=register_usuario">Regístrate gratis</a>
      </div>
    </div>
  </div>
</div>
 
<?php /* ===== LOGIN EMPRESA ===== */ elseif ($page === 'login_empresa'): ?>
<div class="auth-wrapper">
  <div class="auth-bg-shape auth-bg-shape-1"></div>
  <div class="auth-bg-shape auth-bg-shape-2"></div>
  <div class="auth-container">
    <div class="auth-hero">
      <div style="font-family:'Playfair Display',serif;font-size:2rem;color:#fff;margin-bottom:.5rem"><?= SITE_NAME ?></div>
      <h1>Contrata al mejor talento</h1>
      <p>Publica tus vacantes y conecta con miles de candidatos calificados de forma rápida y sencilla.</p>
    </div>
    <div class="auth-card fade-in">
      <div class="auth-tabs">
        <a href="index.php?page=login" class="auth-tab">Candidato</a>
        <a href="index.php?page=login_empresa" class="auth-tab active">Empresa</a>
      </div>
      <h2>Acceso empresarial</h2>
      <p class="subtitle">Gestiona tus vacantes</p>
      <?php $err=flash('error');$ok=flash('success'); ?>
      <?php if ($err): ?><div class="alert alert-error">⚠ <?= h($err) ?></div><?php endif; ?>
      <?php if ($ok):  ?><div class="alert alert-success">✓ <?= h($ok) ?></div><?php endif; ?>
      <form method="POST">
        <input type="hidden" name="action" value="login_empresa">
        <div class="form-group">
          <label>Correo empresarial</label>
          <input type="email" name="email" class="form-control" placeholder="rrhh@empresa.com" required>
        </div>
        <div class="form-group">
          <label>Contraseña</label>
          <input type="password" name="password" class="form-control" placeholder="••••••••" required>
        </div>
        <button type="submit" class="btn btn-primary btn-full" style="margin-top:.5rem">Entrar</button>
      </form>
      <div class="auth-footer">
        ¿Tu empresa no está registrada? <a href="index.php?page=register_empresa">Regístrala aquí</a>
      </div>
    </div>
  </div>
</div>
 
<?php /* ===== REGISTRO USUARIO ===== */ elseif ($page === 'register_usuario'): ?>
<div class="auth-wrapper">
  <div class="auth-bg-shape auth-bg-shape-1"></div>
  <div class="auth-bg-shape auth-bg-shape-2"></div>
  <div class="auth-container">
    <div class="auth-hero">
      <div style="font-family:'Playfair Display',serif;font-size:2rem;color:#fff;margin-bottom:.5rem"><?= SITE_NAME ?></div>
      <h1>Comienza tu búsqueda de empleo hoy</h1>
      <p>Crea tu perfil, explora cientos de vacantes y aplica con un clic.</p>
    </div>
    <div class="auth-card fade-in">
      <h2>Crear cuenta</h2>
      <p class="subtitle">Candidato / Profesional</p>
      <?php $err=flash('error'); ?>
      <?php if ($err): ?><div class="alert alert-error">⚠ <?= h($err) ?></div><?php endif; ?>
      <form method="POST">
        <input type="hidden" name="action" value="register_usuario">
        <div class="form-grid">
          <div class="form-group">
            <label>Nombre *</label>
            <input type="text" name="nombre" class="form-control" placeholder="Carlos" required>
          </div>
          <div class="form-group">
            <label>Apellido *</label>
            <input type="text" name="apellido" class="form-control" placeholder="García" required>
          </div>
        </div>
        <div class="form-group">
          <label>Correo electrónico *</label>
          <input type="email" name="email" class="form-control" placeholder="carlos@correo.com" required>
        </div>
        <div class="form-group">
          <label>Contraseña *</label>
          <input type="password" name="password" class="form-control" placeholder="Mínimo 8 caracteres" required minlength="8">
        </div>
        <div class="form-group">
          <label>Título profesional</label>
          <input type="text" name="titulo" class="form-control" placeholder="Ej: Desarrollador Web Senior">
        </div>
        <div class="form-group">
          <label>Ubicación</label>
          <input type="text" name="ubicacion" class="form-control" placeholder="La Paz">
        </div>
        <button type="submit" class="btn btn-primary btn-full">Crear cuenta gratuita</button>
      </form>
      <div class="auth-footer">
        ¿Ya tienes cuenta? <a href="index.php?page=login">Inicia sesión</a>
        &nbsp;·&nbsp; <a href="index.php?page=register_empresa">Soy empresa</a>
      </div>
    </div>
  </div>
</div>
 
<?php /* ===== REGISTRO EMPRESA ===== */ elseif ($page === 'register_empresa'): ?>
<div class="auth-wrapper">
  <div class="auth-bg-shape auth-bg-shape-1"></div>
  <div class="auth-bg-shape auth-bg-shape-2"></div>
  <div class="auth-container">
    <div class="auth-hero">
      <div style="font-family:'Playfair Display',serif;font-size:2rem;color:#fff;margin-bottom:.5rem"><?= SITE_NAME ?></div>
      <h1>Publica tus vacantes y contrata mejor</h1>
      <p>Regístra tu empresa, publica empleos ilimitados y gestiona candidatos desde un solo lugar.</p>
    </div>
    <div class="auth-card fade-in">
      <h2>Registrar empresa</h2>
      <p class="subtitle">Área de recursos humanos</p>
      <?php $err=flash('error'); ?>
      <?php if ($err): ?><div class="alert alert-error">⚠ <?= h($err) ?></div><?php endif; ?>
      <form method="POST">
        <input type="hidden" name="action" value="register_empresa">
        <div class="form-group">
          <label>Nombre de la empresa *</label>
          <input type="text" name="nombre" class="form-control" placeholder="TechCorp Solutions S.A." required>
        </div>
        <div class="form-group">
          <label>Correo de RRHH *</label>
          <input type="email" name="email" class="form-control" placeholder="rrhh@empresa.com" required>
        </div>
        <div class="form-group">
          <label>Contraseña *</label>
          <input type="password" name="password" class="form-control" placeholder="Mínimo 8 caracteres" required minlength="8">
        </div>
        <div class="form-grid">
          <div class="form-group">
            <label>Sector / Industria</label>
            <input type="text" name="sector" class="form-control" placeholder="Tecnología">
          </div>
          <div class="form-group">
            <label>Ubicación</label>
            <input type="text" name="ubicacion" class="form-control" placeholder="La Paz">
          </div>
        </div>
        <div class="form-group">
          <label>Sitio web</label>
          <input type="url" name="sitio_web" class="form-control" placeholder="https://tuempresa.com">
        </div>
        <button type="submit" class="btn btn-primary btn-full">Registrar empresa</button>
      </form>
      <div class="auth-footer">
        ¿Ya tienes cuenta de empresa? <a href="index.php?page=login_empresa">Inicia sesión</a>
        &nbsp;·&nbsp; <a href="index.php?page=register_usuario">Soy candidato</a>
      </div>
    </div>
  </div>
</div>
 
<?php /* ===== DASHBOARD ===== */ elseif ($page === 'dashboard'): ?>
<div class="page-wrap fade-in">
 
  <?php $err=flash('error');$ok=flash('success'); ?>
  <?php if ($err): ?><div class="alert alert-error">⚠ <?= h($err) ?></div><?php endif; ?>
  <?php if ($ok):  ?><div class="alert alert-success">✓ <?= h($ok) ?></div><?php endif; ?>
 
  <?php if (isUser()): ?>
  <!-- ======= DASHBOARD CANDIDATO ======= -->
  <div class="grid-layout">
    <!-- Columna izquierda: perfil -->
    <div style="display:flex;flex-direction:column;gap:1.25rem">
 
      <!-- Mis postulaciones -->
      <div class="card">
        <div class="card-header">
          <span class="card-title">Mis postulaciones</span>
          <span class="chip"><?= count($myApps) ?></span>
        </div>
        <?php if (empty($myApps)): ?>
        <div class="empty-state">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
          <p>Aún no te has postulado a ningún empleo.<br>¡Explora las oportunidades disponibles!</p>
        </div>
        <?php else: ?>
        <div style="padding:.75rem 0">
          <?php foreach ($myApps as $app): ?>
          <div style="display:flex;align-items:flex-start;gap:1rem;padding:.85rem 1.5rem;border-bottom:1px solid var(--border)">
            <?= avatar($app['empresa_nombre']) ?>
            <div style="flex:1;min-width:0">
              <div style="font-weight:600;font-size:.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($app['empleo_titulo']) ?></div>
              <div style="font-size:.8rem;color:var(--muted)"><?= h($app['empresa_nombre']) ?></div>
              <div style="margin-top:.3rem;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
                <?= badgeEstado($app['estado']) ?>
                <span style="font-size:.75rem;color:var(--muted)"><?= timeDiff($app['fecha_postulacion']) ?></span>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
 
      <!-- Lista de empleos -->
      <div class="section-header">
        <span class="section-title">
          <?= $busqueda ? 'Resultados para "' . h($busqueda) . '" (' . count($jobs) . ')' : 'Empleos disponibles' ?>
        </span>
      </div>
 
      <?php if (empty($jobs)): ?>
      <div class="card"><div class="empty-state">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <p>No se encontraron empleos<?= $busqueda ? " para \"$busqueda\"" : '' ?>.</p>
        <?php if ($busqueda): ?><a href="index.php?page=dashboard" class="btn btn-outline btn-sm mt-2">Ver todos</a><?php endif; ?>
      </div></div>
      <?php else: ?>
      <div class="jobs-list">
        <?php foreach ($jobs as $job): ?>
        <div class="job-card" onclick="location.href='index.php?page=job_detail&id=<?= $job['id'] ?>'">
          <div class="job-card-header">
            <?= avatar($job['empresa_nombre']) ?>
            <div class="job-card-info">
              <div class="job-title"><?= h($job['titulo']) ?></div>
              <div class="job-company"><?= h($job['empresa_nombre']) ?><?= $job['sector'] ? ' · ' . h($job['sector']) : '' ?></div>
            </div>
          </div>
          <div class="job-meta">
            <?php if ($job['ubicacion']): ?>
            <span>
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
              <?= h($job['ubicacion']) ?>
            </span>
            <?php endif; ?>
            <?php if ($job['tipo']): echo badgeTipo($job['tipo']); endif; ?>
          </div>
          <p class="job-desc"><?= h($job['descripcion']) ?></p>
          <div class="job-footer">
            <span class="job-salary"><?= $job['salario'] ? h($job['salario']) : 'Salario a negociar' ?></span>
            <span class="job-time"><?= timeDiff($job['fecha_publicacion']) ?></span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
 
    <!-- Columna derecha: perfil -->
    <div style="display:flex;flex-direction:column;gap:1.25rem">
      <?php
      $uStmt = db()->prepare("SELECT * FROM usuarios WHERE id=?");
      $uStmt->execute([authId()]);
      $uInfo = $uStmt->fetch();
      ?>
      <div class="card profile-card">
        <div class="cover"></div>
        <div class="profile-info">
          <div class="avatar-wrap"><?= avatar(authName(), '56px') ?></div>
          <div class="p-name"><?= h($uInfo['nombre'] . ' ' . $uInfo['apellido']) ?></div>
          <div class="p-title"><?= h($uInfo['titulo'] ?: 'Sin título profesional') ?></div>
          <?php if ($uInfo['ubicacion']): ?>
          <div style="font-size:.78rem;color:var(--muted);margin-top:.3rem">📍 <?= h($uInfo['ubicacion']) ?></div>
          <?php endif; ?>
          <div class="p-stats">
            <div class="p-stat"><strong><?= count($jobs) ?></strong><span>Empleos</span></div>
            <div class="p-stat"><strong><?= count($myApps) ?></strong><span>Aplicaciones</span></div>
            <div class="p-stat">
              <strong><?= count(array_filter($myApps, fn($a) => $a['estado']==='Aceptado')) ?></strong>
              <span>Aceptadas</span>
            </div>
          </div>
        </div>
      </div>
 
      <div class="card">
        <div class="card-header"><span class="card-title">🔍 Sugerencias</span></div>
        <div class="card-body" style="font-size:.88rem;color:var(--muted);display:flex;flex-direction:column;gap:.75rem">
          <p>✅ Completa tu perfil para aparecer en más búsquedas.</p>
          <p>📌 Aplica temprano — las vacantes reciben más atención en las primeras horas.</p>
          <p>✉ Escribe una carta de presentación personalizada para destacar.</p>
        </div>
      </div>
    </div>
  </div>
 
  <?php elseif (isCompany()): ?>
  <!-- ======= DASHBOARD EMPRESA ======= -->
  <?php
  $totalPostulantes = array_sum(array_column($myJobs, 'total_postulantes'));
  $activos = count(array_filter($myJobs, fn($j) => $j['activo']));
  ?>
  <div class="stats-grid">
    <div class="stat-box"><div class="num"><?= count($myJobs) ?></div><div class="lbl">Empleos publicados</div></div>
    <div class="stat-box"><div class="num"><?= $activos ?></div><div class="lbl">Vacantes activas</div></div>
    <div class="stat-box"><div class="num"><?= $totalPostulantes ?></div><div class="lbl">Total postulantes</div></div>
    <div class="stat-box"><div class="num"><?= count($jobs) ?></div><div class="lbl">Empleos en plataforma</div></div>
  </div>
 
  <div class="grid-layout">
    <div style="display:flex;flex-direction:column;gap:1.25rem">
      <div class="card">
        <div class="card-header">
          <span class="card-title">Mis vacantes publicadas</span>
          <a href="index.php?page=post_job" class="btn btn-primary btn-sm">+ Nueva vacante</a>
        </div>
        <?php if (empty($myJobs)): ?>
        <div class="empty-state">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 5v14M5 12h14"/></svg>
          <p>Aún no has publicado ninguna vacante.</p>
          <a href="index.php?page=post_job" class="btn btn-primary btn-sm mt-2">Publicar primer empleo</a>
        </div>
        <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead><tr>
              <th>Vacante</th><th>Tipo</th><th>Postulantes</th><th>Fecha</th><th>Acción</th>
            </tr></thead>
            <tbody>
            <?php foreach ($myJobs as $job): ?>
            <tr>
              <td>
                <div style="font-weight:600"><?= h($job['titulo']) ?></div>
                <?php if ($job['ubicacion']): ?><div style="font-size:.78rem;color:var(--muted)"><?= h($job['ubicacion']) ?></div><?php endif; ?>
              </td>
              <td><?= $job['tipo'] ? badgeTipo($job['tipo']) : '—' ?></td>
              <td><span class="chip"><?= $job['total_postulantes'] ?></span></td>
              <td style="font-size:.8rem;color:var(--muted)"><?= timeDiff($job['fecha_publicacion']) ?></td>
              <td>
                <a href="index.php?page=postulantes&empleo=<?= $job['id'] ?>" class="btn btn-outline btn-sm">Ver candidatos</a>
                <a href="index.php?page=editar_empleo&id=<?= $job['id'] ?>" class="btn btn-outline btn-sm" style="color:#f59e0b">✏️ Editar</a>
                <a href="index.php?action=eliminar_empleo&id=<?= $job['id'] ?>" class="btn btn-outline btn-sm" style="color:#ef4444" onclick="return confirm('¿Eliminar este empleo? No se puede deshacer.')">🗑️ Eliminar</a>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
 
    <div style="display:flex;flex-direction:column;gap:1.25rem">
      <?php
      $eStmt = db()->prepare("SELECT * FROM empresas WHERE id=?");
      $eStmt->execute([authId()]);
      $eInfo = $eStmt->fetch();
      ?>
      <div class="card profile-card">
        <div class="cover"></div>
        <div class="profile-info">
          <div class="avatar-wrap"><?= avatar(authName(), '56px') ?></div>
          <div class="p-name"><?= h($eInfo['nombre']) ?></div>
          <div class="p-title"><?= h($eInfo['sector'] ?: 'Sin sector definido') ?></div>
          <?php if ($eInfo['ubicacion']): ?>
          <div style="font-size:.78rem;color:var(--muted);margin-top:.3rem">📍 <?= h($eInfo['ubicacion']) ?></div>
          <?php endif; ?>
          <?php if ($eInfo['sitio_web']): ?>
          <div style="font-size:.78rem;margin-top:.25rem"><a href="<?= h($eInfo['sitio_web']) ?>" target="_blank">🌐 Visitar sitio</a></div>
          <?php endif; ?>
        </div>
      </div>
 
      <div class="card">
        <div class="card-header"><span class="card-title">🛠️ Herramientas</span></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:.6rem">
          <a href="index.php?action=reporte_postulaciones" class="btn btn-outline btn-sm" style="justify-content:flex-start">📥 Exportar postulaciones CSV</a>
          <a href="index.php?action=reporte_empleos" class="btn btn-outline btn-sm" style="justify-content:flex-start">📥 Exportar empleos CSV</a>
          <a href="index.php?action=backup" class="btn btn-outline btn-sm" style="justify-content:flex-start">💾 Descargar Backup BD</a>
          <a href="index.php?page=logs" class="btn btn-outline btn-sm" style="justify-content:flex-start">📋 Ver Log de actividad</a>
        </div>
      </div>
 

      <div class="card">
        <div class="card-header"><span class="card-title">💡 Consejos</span></div>
        <div class="card-body" style="font-size:.88rem;color:var(--muted);display:flex;flex-direction:column;gap:.75rem">
          <p>✅ Agrega el salario para recibir 3x más postulaciones.</p>
          <p>📝 Describe claramente los requisitos mínimos.</p>
          <p>⚡ Responde rápido — los candidatos valoran la velocidad.</p>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
 
<?php /* ===== PUBLICAR EMPLEO ===== */ elseif ($page === 'post_job' && isCompany()): ?>
<div class="page-wrap fade-in" style="max-width:760px">
  <div class="section-header">
    <span class="section-title">Publicar nueva vacante</span>
    <a href="index.php?page=dashboard" class="btn btn-ghost btn-sm">← Volver</a>
  </div>
  <div class="card">
    <div class="card-body">
      <?php $err=flash('error'); ?>
      <?php if ($err): ?><div class="alert alert-error">⚠ <?= h($err) ?></div><?php endif; ?>
      <form method="POST">
        <input type="hidden" name="action" value="post_job">
        <div class="form-group">
          <label>Título del puesto *</label>
          <input type="text" name="titulo" class="form-control" placeholder="Ej: Desarrollador PHP Senior" required>
        </div>
        <div class="form-grid">
          <div class="form-group">
            <label>Tipo de empleo</label>
            <select name="tipo" class="form-control">
              <option>Tiempo completo</option>
              <option>Medio tiempo</option>
              <option>Remoto</option>
              <option>Híbrido</option>
              <option>Freelance</option>
            </select>
          </div>
          <div class="form-group">
            <label>Ubicación</label>
            <input type="text" name="ubicacion" class="form-control" placeholder="La Paz">
          </div>
        </div>
        <div class="form-group">
          <label>Rango salarial</label>
          <input type="text" name="salario" class="form-control" placeholder="Ej: $20,000 - $30,000 MXN">
          <div class="form-hint">Agregar el salario aumenta la tasa de postulación significativamente.</div>
        </div>
        <div class="form-group">
          <label>Descripción del puesto *</label>
          <textarea name="descripcion" class="form-control" rows="5" placeholder="Describe las responsabilidades y el ambiente de trabajo…" required></textarea>
        </div>
        <div class="form-group">
          <label>Requisitos</label>
          <textarea name="requisitos" class="form-control" rows="4" placeholder="- 3+ años de experiencia en PHP&#10;- Inglés intermedio&#10;- Disponibilidad inmediata"></textarea>
        </div>
        <div style="display:flex;gap:1rem;justify-content:flex-end;margin-top:.5rem">
          <a href="index.php?page=dashboard" class="btn btn-ghost">Cancelar</a>
          <button type="submit" class="btn btn-primary">Publicar vacante</button>
        </div>
      </form>
    </div>
  </div>
</div>
 
<?php /* ===== DETALLE DE EMPLEO ===== */ elseif ($page === 'job_detail' && $jobDetail): ?>
<div class="page-wrap fade-in" style="max-width:860px">
  <a href="index.php?page=dashboard" class="btn btn-ghost btn-sm mb-2" style="display:inline-flex">← Volver a empleos</a>
 
  <div class="card mb-2">
    <div class="card-body">
      <div style="display:flex;gap:1.25rem;align-items:flex-start;flex-wrap:wrap">
        <?= avatar($jobDetail['empresa_nombre'], '60px') ?>
        <div style="flex:1;min-width:200px">
          <h1 style="font-size:1.5rem;font-weight:800;line-height:1.2;margin-bottom:.3rem"><?= h($jobDetail['titulo']) ?></h1>
          <div style="font-size:1rem;color:var(--muted);margin-bottom:.75rem">
            <?= h($jobDetail['empresa_nombre']) ?>
            <?= $jobDetail['sector'] ? ' · ' . h($jobDetail['sector']) : '' ?>
          </div>
          <div style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:center">
            <?php if ($jobDetail['tipo']): echo badgeTipo($jobDetail['tipo']); endif; ?>
            <?php if ($jobDetail['ubicacion']): ?>
            <span style="font-size:.85rem;color:var(--muted)">📍 <?= h($jobDetail['ubicacion']) ?></span>
            <?php endif; ?>
            <?php if ($jobDetail['salario']): ?>
            <span style="font-size:.9rem;font-weight:700;color:var(--green)"><?= h($jobDetail['salario']) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <?php if (isUser()): ?>
        <div>
          <?php if ($yaPostulo): ?>
          <span class="badge badge-accepted" style="padding:.5rem 1.25rem;font-size:.9rem">✓ Ya aplicaste</span>
          <?php else: ?>
          <button class="btn btn-primary" onclick="document.getElementById('applyModal').style.display='flex'">
            Postularme ahora
          </button>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
 
      <hr class="sep">
 
      <div style="font-size:.85rem;color:var(--muted);margin-bottom:1.25rem">
        Publicado <?= timeDiff($jobDetail['fecha_publicacion']) ?>
        <?php if ($jobDetail['sitio_web']): ?>
        · <a href="<?= h($jobDetail['sitio_web']) ?>" target="_blank">Ver empresa</a>
        <?php endif; ?>
      </div>
 
      <h3 style="font-size:1rem;font-weight:700;margin-bottom:.75rem">📋 Descripción del puesto</h3>
      <div style="font-size:.92rem;line-height:1.8;white-space:pre-line;color:var(--text)"><?= h($jobDetail['descripcion']) ?></div>
 
      <?php if ($jobDetail['requisitos']): ?>
      <h3 style="font-size:1rem;font-weight:700;margin-top:1.5rem;margin-bottom:.75rem">✅ Requisitos</h3>
      <div style="font-size:.92rem;line-height:1.8;white-space:pre-line;color:var(--text)"><?= h($jobDetail['requisitos']) ?></div>
      <?php endif; ?>
    </div>
  </div>
 
  <?php if (isUser() && !$yaPostulo): ?>
  <!-- MODAL POSTULACIÓN -->
  <div id="applyModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;padding:1rem">
    <div class="card fade-in" style="max-width:520px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.3)">
      <div class="card-header">
        <span class="card-title">Postular a: <?= h($jobDetail['titulo']) ?></span>
        <button onclick="document.getElementById('applyModal').style.display='none'" class="btn btn-ghost btn-sm">✕</button>
      </div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="action" value="postular">
          <input type="hidden" name="empleo_id" value="<?= $jobDetail['id'] ?>">
          <div class="form-group">
            <label>Carta de presentación (opcional)</label>
            <textarea name="mensaje" class="form-control" rows="5"
              placeholder="Cuéntanos por qué eres el candidato ideal para este puesto…"></textarea>
            <div class="form-hint">Una buena carta de presentación aumenta tus posibilidades de ser seleccionado.</div>
          </div>
          <div style="display:flex;gap:1rem;justify-content:flex-end">
            <button type="button" onclick="document.getElementById('applyModal').style.display='none'" class="btn btn-ghost">Cancelar</button>
            <button type="submit" class="btn btn-primary">Enviar postulación</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
 
<?php /* ===== POSTULANTES (empresa) ===== */ elseif ($page === 'postulantes' && isCompany()): ?>
<div class="page-wrap fade-in">
  <div class="section-header">
    <div>
      <span class="section-title">Candidatos: <?= h($empInfo['titulo']) ?></span>
      <div style="font-size:.85rem;color:var(--muted)"><?= count($postulantes) ?> postulante(s)</div>
    </div>
    <a href="index.php?page=dashboard" class="btn btn-ghost btn-sm">← Volver</a>
  </div>
 
  <?php $ok=flash('success'); ?>
  <?php if ($ok): ?><div class="alert alert-success">✓ <?= h($ok) ?></div><?php endif; ?>
 
  <?php if (empty($postulantes)): ?>
  <div class="card"><div class="empty-state">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    <p>Aún no hay candidatos para esta vacante.</p>
  </div></div>
  <?php else: ?>
  <div style="display:flex;flex-direction:column;gap:1rem">
    <?php foreach ($postulantes as $p): ?>
    <div class="card">
      <div class="card-body" style="display:flex;gap:1.25rem;align-items:flex-start;flex-wrap:wrap">
        <?= avatar($p['nombre'] . ' ' . $p['apellido']) ?>
        <div style="flex:1;min-width:200px">
          <div style="font-weight:700;font-size:1rem"><?= h($p['nombre'] . ' ' . $p['apellido']) ?></div>
          <?php if ($p['titulo']): ?>
          <div style="font-size:.85rem;color:var(--muted)"><?= h($p['titulo']) ?></div>
          <?php endif; ?>
          <div style="font-size:.82rem;color:var(--muted);margin-top:.15rem">
            ✉ <?= h($p['email']) ?>
            <?= $p['ubicacion'] ? ' · 📍 ' . h($p['ubicacion']) : '' ?>
          </div>
          <?php if ($p['mensaje']): ?>
          <div style="margin-top:.75rem;padding:.75rem 1rem;background:var(--bg);border-radius:var(--radius-sm);font-size:.85rem;line-height:1.6;color:var(--text)">
            <?= nl2br(h(substr($p['mensaje'], 0, 300))) ?><?= strlen($p['mensaje']) > 300 ? '…' : '' ?>
          </div>
          <?php endif; ?>
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.75rem;flex-shrink:0">
          <?= badgeEstado($p['estado']) ?>
          <span style="font-size:.78rem;color:var(--muted)"><?= timeDiff($p['fecha_postulacion']) ?></span>
          <form method="POST" style="display:flex;gap:.5rem;align-items:center">
            <input type="hidden" name="action" value="update_estado">
            <input type="hidden" name="post_id" value="<?= $p['id'] ?>">
            <input type="hidden" name="empleo_id" value="<?= (int)$_GET['empleo'] ?>">
            <select name="estado" class="form-control" style="padding:.35rem .6rem;font-size:.82rem;width:130px">
              <?php foreach (['Pendiente','En revisión','Aceptado','Rechazado'] as $opt): ?>
              <option <?= $p['estado']===$opt?'selected':'' ?>><?= $opt ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Guardar</button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
 
<?php /* ===== EDITAR EMPLEO ===== */ elseif ($page === 'editar_empleo' && isCompany() && $empleoEditar): ?>
<div class="page-wrap fade-in" style="max-width:700px">
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">✏️ Editar Empleo</h2>
      <a href="index.php?page=dashboard" class="btn btn-outline btn-sm">← Volver</a>
    </div>
    <div class="card-body">
      <?php $err=flash('error');$ok=flash('success');
        if($err) echo "<div class='alert alert-error'>$err</div>";
        if($ok)  echo "<div class='alert alert-success'>$ok</div>"; ?>
      <form method="POST">
        <input type="hidden" name="action" value="editar_empleo">
        <input type="hidden" name="empleo_id" value="<?= h((string)$empleoEditar['id']) ?>">
        <div class="form-group">
          <label class="form-label">Título del puesto *</label>
          <input type="text" name="titulo" class="form-control" required minlength="5" maxlength="200" value="<?= h($empleoEditar['titulo']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Descripción *</label>
          <textarea name="descripcion" class="form-control" rows="4" required minlength="10"><?= h($empleoEditar['descripcion']) ?></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Requisitos</label>
          <textarea name="requisitos" class="form-control" rows="3"><?= h($empleoEditar['requisitos'] ?? '') ?></textarea>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
          <div class="form-group">
            <label class="form-label">Ubicación</label>
            <input type="text" name="ubicacion" class="form-control" value="<?= h($empleoEditar['ubicacion'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Salario</label>
            <input type="text" name="salario" class="form-control" value="<?= h($empleoEditar['salario'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Tipo de empleo *</label>
          <select name="tipo" class="form-control" required>
            <?php foreach(['Tiempo completo','Medio tiempo','Remoto','Híbrido','Freelance'] as $t): ?>
            <option value="<?= h($t) ?>" <?= $empleoEditar['tipo']===$t?'selected':'' ?>><?= h($t) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%">💾 Guardar Cambios</button>
      </form>
    </div>
  </div>
</div>
 
<?php /* ===== LOGS DE ACTIVIDAD ===== */ elseif ($page === 'logs' && isCompany()): ?>
<div class="page-wrap fade-in">
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">📋 Registro de Actividad</h2>
      <a href="index.php?page=dashboard" class="btn btn-outline btn-sm">← Volver</a>
    </div>
    <div class="card-body" style="padding:0">
      <?php if(empty($logs)): ?>
        <p style="padding:2rem;text-align:center;color:var(--muted)">No hay actividad registrada aún.</p>
      <?php else: ?>
      <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.88rem">
          <thead>
            <tr style="background:var(--bg);border-bottom:2px solid var(--border)">
              <th style="padding:.75rem 1rem;text-align:left">Fecha</th>
              <th style="padding:.75rem 1rem;text-align:left">Acción</th>
              <th style="padding:.75rem 1rem;text-align:left">Detalle</th>
              <th style="padding:.75rem 1rem;text-align:left">IP</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($logs as $log): ?>
            <tr style="border-bottom:1px solid var(--border)">
              <td style="padding:.65rem 1rem;white-space:nowrap;color:var(--muted)"><?= h(date('d/m/Y H:i', strtotime($log['fecha']))) ?></td>
              <td style="padding:.65rem 1rem"><strong><?= h($log['accion']) ?></strong></td>
              <td style="padding:.65rem 1rem"><?= h($log['detalle']) ?></td>
              <td style="padding:.65rem 1rem;color:var(--muted)"><?= h($log['ip']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
 
<?php else: ?>
<!-- Página no encontrada -->
<div class="page-wrap" style="text-align:center;padding:5rem 1.5rem">
  <div style="font-size:5rem">🔍</div>
  <h2 style="font-size:1.5rem;margin:.75rem 0 .5rem">Página no encontrada</h2>
  <p style="color:var(--muted)">La página que buscas no existe.</p>
  <a href="index.php" class="btn btn-primary mt-2">Volver al inicio</a>
</div>
<?php endif; ?>
 
<script>
// Cerrar modal al hacer clic fuera
document.addEventListener('click', function(e) {
  const modal = document.getElementById('applyModal');
  if (modal && e.target === modal) modal.style.display = 'none';
});
// Escape cierra modal
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    const modal = document.getElementById('applyModal');
    if (modal) modal.style.display = 'none';
  }
});
</script>
</body>
</html>
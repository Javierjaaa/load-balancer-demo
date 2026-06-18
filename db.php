<?php
// ============================================================
//   WORKCONNECT - Conexión a SQL Server
//   Autenticación de Windows (sin usuario ni contraseña)
// ============================================================

$serverName = "DESKTOP-D320NCI\\SQLEXPRESS";
$connectionOptions = [
    "Database"             => "web_trabajo",
    "TrustServerCertificate" => true,
    "CharacterSet"         => "UTF-8"
];

$conn = sqlsrv_connect($serverName, $connectionOptions);

if ($conn === false) {
    die("<div style='font-family:sans-serif;padding:2rem;color:#c0392b;background:#fdf2f2;
         border:1px solid #e74c3c;border-radius:8px;max-width:600px;margin:4rem auto'>
         <h2>⚠️ Error de Conexión a SQL Server</h2>
         <pre>" . print_r(sqlsrv_errors(), true) . "</pre>
         <p>Verifica que SQL Server esté activo y los drivers PHP sqlsrv estén instalados.</p>
         </div>");
}

// ============================================================
//   Función helper PDO (usada por index.php)
// ============================================================
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $dsn = "sqlsrv:Server=DESKTOP-D320NCI\\SQLEXPRESS;Database=web_trabajo;TrustServerCertificate=1";
        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::SQLSRV_ATTR_ENCODING    => PDO::SQLSRV_ENCODING_UTF8,
        ]);
    } catch (PDOException $e) {
        die("<div style='font-family:sans-serif;padding:2rem;color:#c0392b;background:#fdf2f2;
             border:1px solid #e74c3c;border-radius:8px;max-width:600px;margin:4rem auto'>
             <h2>⚠️ Error de Conexión a SQL Server</h2>
             <p><strong>Mensaje:</strong> " . htmlspecialchars($e->getMessage()) . "</p>
             <p>Verifica que SQL Server esté activo y los drivers PHP sqlsrv estén instalados.</p></div>");
    }
    return $pdo;
}
?>
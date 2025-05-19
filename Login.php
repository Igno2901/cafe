<?php
session_start();

$mensaje_error = '';
$usuarios = [
    'admin' => ['clave' => 'admin123', 'rol' => 'admin'],
    'si18suba' => ['clave' => 'suba123', 'rol' => 'patio', 'company_id' => 1],
    'si18norte' => ['clave' => 'norte123', 'rol' => 'patio', 'company_id' => 2],
    'si18calle80' => ['clave' => 'calle80123', 'rol' => 'patio', 'company_id' => 3],
    'si18Bachue' => ['clave' => 'bachue123', 'rol' => 'patio', 'company_id' => 4]
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clave_ingresada = trim($_POST['clave'] ?? '');

    if ($clave_ingresada === '') {
        $mensaje_error = 'Por favor, ingresa una contraseña.';
    } else {
        $usuario_valido = null;

        foreach ($usuarios as $usuario => $datos) {
            if (password_verify($clave_ingresada, password_hash($datos['clave'], PASSWORD_DEFAULT))) {
                $usuario_valido = $usuario;
                $_SESSION['autenticado'] = true;
                $_SESSION['rol'] = $datos['rol'];
                if (isset($datos['company_id'])) {
                    $_SESSION['company_id'] = $datos['company_id'];
                }
                header('Location: reporte.php');
                exit;
            }
        }

        if (!$usuario_valido) {
            $mensaje_error = 'Contraseña incorrecta. Inténtalo de nuevo.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso</title>
    <link rel="stylesheet" href="Style.css">
    <style>
        .login-container { 
            max-width: 400px;
            margin: 80px auto;
            padding: 30px;
            border-radius: 20px;
            background-color: #ffffff;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .login-container h2 { color: #111; font-weight: 800; margin-bottom: 20px; }
        .login-container label { display: block; font-weight: bold; margin-bottom: 10px; color: #333; }
        .login-container input[type="password"] { width: 90%; padding: 12px; font-size: 16px; border-radius: 10px; border: 2px solid #ccc; transition: border-color 0.3s ease, box-shadow 0.3s ease; }
        .login-container input[type="password"]:focus { border-color: #80d627; box-shadow: 0 0 8px rgba(128, 214, 39, 0.5); outline: none; }
        .login-container input[type="submit"] { margin-top: 20px; padding: 12px 20px; border: none; background-color: #1f4f58; color: white; font-weight: bold; font-size: 16px; border-radius: 12px; cursor: pointer; transition: background-color 0.3s ease, transform 0.3s ease; }
        .login-container input[type="submit"]:hover { background-color: #32cd7b; transform: scale(1.05); }
        .error { color: red; margin-top: 15px; font-weight: bold; }
    </style>
</head>
<body>
<div class="login-container">
    <h2>Ingresar al sistema</h2>
    <form method="post">
        <label for="clave">Contraseña:</label>
        <input type="password" name="clave" id="clave" placeholder="Ingresa tu contraseña">
        <input type="submit" value="Entrar">
        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($mensaje_error)): ?>
            <p class="error"><?= htmlspecialchars($mensaje_error) ?></p>
        <?php endif; ?>
    </form>
</div>
</body>
</html>

<?php
session_start();
include("conexion.php");

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

$empresa_seleccionada = $_GET['company_id'] ?? $_SESSION['company_id'] ?? null;


$empresa_guardada = $empresa_seleccionada == 4 ? 2 : $empresa_seleccionada;

if (isset($_GET['company_id'])) {
    $_SESSION['company_id'] = intval($_GET['company_id']);
    $empresa_seleccionada = $_SESSION['company_id'];
}

if (isset($_POST['select_empresa']) && !empty($_POST['company_id'])) {
    $empresa_id = intval($_POST['company_id']);
    $empresa_id_guardado = $empresa_id == 4 ? 2 : $empresa_id; 
    $_SESSION['company_id'] = $empresa_id_guardado;
    header("Location: index.php?company_id=$empresa_id");
    exit;
}

if (isset($_POST['cerrar_sesion'])) {
    session_unset();
    session_destroy();
    header("Location: index.php");
    exit;
}

if (isset($_POST['cancelar_registro'])) {
    unset($_SESSION['nuevo_usuario'], $_SESSION['id_nuevo']);
    exit;
}

$nuevo_usuario = $_SESSION['nuevo_usuario'] ?? false;
$id_nuevo = $_SESSION['id_nuevo'] ?? '';
$num_attendance = 0; 

if ($nuevo_usuario) {
    $stmt = $conex_coffe->prepare("SELECT num_attendance FROM visitors WHERE id_visitor = ?");
    $stmt->bind_param("i", $id_nuevo);
    $stmt->execute();
    $stmt->bind_result($num_attendance);
    $stmt->fetch();
    $stmt->close();
}

$patios = [
    1 => "Si18 Suba",
    2 => "Si18 Norte",
    3 => "Si18 Calle 80",
    4 => "Si18 Bachue"
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ingresar Cédula</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="Style.css">
</head>
<body>
<div class="main-container">

    <?php if ($empresa_seleccionada): ?>
        <div class="dropdown-container" style="position: absolute; bottom: 20px; left: 20px;">
            <button class="dropdown-button" onclick="toggleDropdown()">☰</button>
            <div class="dropdown-menu">
                <form method="post" action="index.php?company_id=<?= htmlspecialchars($empresa_seleccionada) ?>" class="dropdown-item-form">
                    <button type="submit" name="cerrar_sesion" class="dropdown-item">Cerrar sesión</button>
                </form>
                <a href="reporte.php?company_id=<?= htmlspecialchars($empresa_seleccionada) ?>" class="dropdown-item">Informe</a>
                <a href="entregas.php?company_id=<?= htmlspecialchars($empresa_seleccionada) ?>" class="dropdown-item">Órdenes</a>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($message)): ?>
        <?php
            $isError = strpos($message, 'Error') !== false;
            $isWarning = (
                strpos($message, 'Te quedan') !== false ||
                strpos($message, 'Solo puedes solicitar') !== false ||
                strpos($message, 'límite de 10 cafés') !== false ||
                strpos($message, 'Ya has alcanzado el límite') !== false
            );
            $modalClass = $isError ? 'error' : ($isWarning ? 'warning' : 'success');
        ?>
        <div class="modal" id="confirmation-modal">
            <div class="modal-content <?= $modalClass ?>">
                <p><?= htmlspecialchars($message) ?></p>
                <button class="continue-button" onclick="closeModal()">Continuar</button>
            </div>
        </div>
    <?php endif; ?>

    <div class="container">

        <?php if (!$empresa_seleccionada): ?>
            <form method="post" action="index.php" class="reporte-container">
                <div class="logo-container" style="position: absolute; top: 10px; left: 10px; width: 50px; height: 50px;">
                    <img src="img/logosi18.png" alt="Logo SI18" style="width: 100%; height: 100%; object-fit: contain;">
                </div>
                <h2>Selecciona tu patio</h2>
                <select name="company_id" class="input-casilla" required>
                    <option value="" disabled selected>-- Selecciona una empresa --</option>
                    <?php foreach ($patios as $id => $nombre): ?>
                        <option value="<?= htmlspecialchars($id) ?>" <?= $empresa_seleccionada == $id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($nombre) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" name="select_empresa" class="boton">Ingresar</button>
            </form>

        <?php elseif ($nuevo_usuario): ?>
            <form method="post" action="registrar.php" class="reporte-container" id="registroForm">
                <div class="logo-container">
                    <img src="img/logosi18.png" alt="Logo SI18">
                </div>
                <h2>Registrar nuevo usuario</h2>
                <?php if ($num_attendance < 3): ?>
                     <div class="mensaje-cafe">
                        Si visitas los patios ocasionalmente, utiliza el botón de "Visitante" para registrarte rápidamente.
                    </div>
                <?php endif; ?>
                <input type="hidden" name="idusers_ext" value="<?= htmlspecialchars($id_nuevo) ?>">

                <div class="input-casilla">
                    <input type="text" name="name_ext" placeholder="Nombres">
                </div>
                <div class="input-casilla">
                    <input type="text" name="surname_ext" placeholder="Apellidos">
                </div>

                <div class="row" style="display: flex; justify-content: center; gap: 10px; margin-top: 15px;">
                    <button class="boton-entregar" type="submit" name="register_ext">Registrar</button>
                    <button class="boton-cancelar" type="button" onclick="cancelarRegistro()">Cancelar</button>

                    <?php if ($num_attendance < 3): ?>
                        <button class="boton-entregar" type="submit" name="register_ext_visitors" formnovalidate>Visitantes</button>
                    <?php endif; ?>
                </div>
            </form>

        <?php else: ?>
            <form method="post" action="registrar.php" class="reporte-container">
                <div class="logo-container">
                    <img src="img/logosi18.png" alt="Logo SI18">
                </div>
                <h2>Bienvenido</h2>
                <div class="mensaje-cafe">
                    ☕ ¡Disfruta de un delicioso café cortesía de nuestra organización!
                </div>

                <div class="input-casilla" style="display: flex; align-items: center; gap: 10px;">
                    <input type="number" name="name" placeholder="Cédula" style="flex: 4;" required>
                    <input type="number" name="cantidad_cafes" placeholder="Cantidad de cafés" min="1" max="10" value="1" style="flex: 0.5;" required>
                </div>

                <div style="text-align: center; margin-top: 15px;">
                    <button class="boton" type="submit" name="register">Enviar</button>
                </div>

                <div class="empresa-actual">
                    <p><strong>Patio actual</strong></p>
                    <div class="empresa-detalle">
                        <span><?= htmlspecialchars($patios[$empresa_seleccionada] ?? "Patio desconocido") ?></span>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleDropdown() {
    const dropdown = document.querySelector('.dropdown-container');
    dropdown.classList.toggle('active');
}

document.addEventListener('click', function(event) {
    const dropdown = document.querySelector('.dropdown-container');
    if (!dropdown.contains(event.target)) {
        dropdown.classList.remove('active');
    }
});

function closeModal() {
    const modal = document.getElementById('confirmation-modal');
    modal.style.opacity = '0';
    setTimeout(() => modal.remove(), 500);
}

document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('confirmation-modal');
    if (modal) {
        setTimeout(closeModal, 4000);
    }
});

function cancelarRegistro() {
    fetch('index.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'cancelar_registro=1'
    }).then(() => {
        window.location.href = 'index.php';
    });
}
</script>
</body>
</html>
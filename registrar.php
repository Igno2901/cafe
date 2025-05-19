<?php
session_start();
include("conexion.php"); 

function limpiar($dato) {
    return htmlspecialchars(trim($dato));
}

$empresa_seleccionada = $_SESSION['company_id'] ?? null;

// Cambiar empresa
if (isset($_POST['cambiar_empresa'])) {
    unset($_SESSION['company_id']);
    $_SESSION['message'] = "Selecciona un nuevo patio para continuar.";
    header("Location: index.php");
    exit;
}

$patios = [
    1 => "Si18 Suba",
    2 => "Si18 Norte",
    3 => "Si18 Calle 80",
    4 => "Si18 Bachue"
];

if (isset($_POST['select_empresa'])) {
    $sel = limpiar($_POST['company_id'] ?? '');
    if ($sel !== '' && intval($sel) >= 1 && intval($sel) <= 4) { 
        $_SESSION['company_id'] = intval($sel);
        
       
        $nombre_empresa = $patios[$_SESSION['company_id']] ?? "Patio desconocido";

        $_SESSION['message'] = "Empresa seleccionada: " . htmlspecialchars($nombre_empresa);
    } else {
        $_SESSION['message'] = "Debes seleccionar un patio válido (solo los primeros 4).";
    }
    header("Location: index.php");
    exit;
}


if (isset($_POST['register']) && $empresa_seleccionada) {
    $idusers = limpiar($_POST['name'] ?? '');
    $cantidad_cafes = limpiar($_POST['cantidad_cafes'] ?? '');

    if ($idusers === '' || $cantidad_cafes === '' || !is_numeric($cantidad_cafes) || intval($cantidad_cafes) <= 0) {
        $_SESSION['message'] = "Por favor, ingresa un ID válido y una cantidad de cafés mayor a 0.";
    } else {
        $id = null;
        $name = '';
        $surname = '';

        $stmt = $conex_users->prepare("SELECT idusers, name, surname FROM users WHERE idusers = ?");
        $stmt->bind_param("i", $idusers);
        $stmt->execute();
        $stmt->bind_result($id, $name, $surname);

        if ($stmt->fetch()) {
            $stmt->close();
        } else {
            $stmt->close();

            $stmt2 = $conex_coffe->prepare("SELECT id_user, name, surname FROM users_ext WHERE id_user = ?");
            $stmt2->bind_param("i", $idusers);
            $stmt2->execute();
            $stmt2->bind_result($id, $name, $surname);

            if ($stmt2->fetch()) {
                $stmt2->close();
            } else {
                $stmt2->close();
                $_SESSION['nuevo_usuario'] = true;
                $_SESSION['id_nuevo'] = $idusers;
                header("Location: index.php");
                exit;
            }
        }

        $fecha_hoy = date('Y-m-d');
        $stmt_datos = $conex_coffe->prepare("SELECT COALESCE(SUM(cantidad_cafes),0) FROM datos WHERE cedula = ? AND DATE(fecha) = ?");
        $stmt_datos->bind_param("is", $id, $fecha_hoy);
        $stmt_datos->execute();
        $stmt_datos->bind_result($cafes_datos);
        $stmt_datos->fetch();
        $stmt_datos->close();

        $limite_diario = 10;
        $cafes_consumidos = intval($cafes_datos);
        $cantidad_cafes_int = intval($cantidad_cafes);
        $cafes_restantes = $limite_diario - $cafes_consumidos;

        if ($cafes_consumidos + $cantidad_cafes_int > $limite_diario) {
            if ($cafes_restantes > 0) {
                $_SESSION['message'] = "Solo puedes solicitar $cafes_restantes café(s) más hoy ($cafes_consumidos/$limite_diario usados).";
            } else {
                $_SESSION['message'] = "Ya has alcanzado el límite de 10 cafés para hoy.";
            }
            header("Location: index.php");
            exit;
        }

        $cafes_restantes_despues = $limite_diario - ($cafes_consumidos + $cantidad_cafes_int);
        if ($cafes_consumidos + $cantidad_cafes_int == $limite_diario) {
            $_SESSION['message'] = "Ya has alcanzado el límite de 10 cafés para hoy";
        } elseif ($cafes_restantes_despues <= 5 && $cafes_restantes_despues > 0) {
            $_SESSION['message'] = "Te quedan $cafes_restantes_despues cafés el día de hoy (" . ($cafes_consumidos + $cantidad_cafes_int) . "/$limite_diario usados).";
        }

        $ins = $conex_coffe->prepare("INSERT INTO solicitudes_pendientes (idusers, nombre, surname, empresa_id, cantidad_cafes) VALUES (?, ?, ?, ?, ?)");
        $ins->bind_param("issii", $id, $name, $surname, $empresa_seleccionada, $cantidad_cafes);

        if ($ins->execute()) {
            if (empty($_SESSION['message'])) {
                $_SESSION['message'] = "Solicitud de café registrada. Espera la entrega.";
            }
        } else {
            $_SESSION['message'] = "Error al registrar la solicitud: " . $ins->error;
        }
        $ins->close();
    }
    header("Location: index.php");
    exit;
}

// Registrar usuario externo
if (isset($_POST['register_ext'])) {
    $cedula_ext  = limpiar($_POST['idusers_ext'] ?? '');
    $name_ext    = limpiar($_POST['name_ext'] ?? '');
    $surname_ext = limpiar($_POST['surname_ext'] ?? '');
    $company_ext = 84;
    $empresa_id  = $empresa_seleccionada ?? 84;

    if ($cedula_ext !== '' && $name_ext !== '' && $surname_ext !== '' && $empresa_id) {
        $stmt = $conex_coffe->prepare("SELECT id_user FROM users_ext WHERE id_user = ?");
        $stmt->bind_param("i", $cedula_ext);
        $stmt->execute();
        $stmt->bind_result($existing_user);
        $stmt->fetch();
        $stmt->close();

        if (!$existing_user) {
            
            $stmt_attendance = $conex_coffe->prepare("SELECT num_attendance FROM visitors WHERE id_visitor = ?");
            $stmt_attendance->bind_param("i", $cedula_ext);
            $stmt_attendance->execute();
            $stmt_attendance->bind_result($num_attendance);
            $stmt_attendance->fetch();
            $stmt_attendance->close();

            if ($num_attendance === 3) {
                $company_ext = 83; 
            }

            $stmt2 = $conex_coffe->prepare("INSERT INTO users_ext (id_user, name, surname, company) VALUES (?, ?, ?, ?)");
            $stmt2->bind_param("issi", $cedula_ext, $name_ext, $surname_ext, $company_ext);

            if ($stmt2->execute()) {
                $ins3 = $conex_coffe->prepare("INSERT INTO solicitudes_pendientes (idusers, nombre, surname, empresa_id, cantidad_cafes) VALUES (?, ?, ?, ?, 1)");
                $ins3->bind_param("issi", $cedula_ext, $name_ext, $surname_ext, $empresa_id);

                if ($ins3->execute()) {
                    $_SESSION['message'] = "¡Registrado y solicitud enviada correctamente!";
                } else {
                    $_SESSION['message'] = "Error al registrar la solicitud: " . $ins3->error;
                }
                $ins3->close();

                unset($_SESSION['nuevo_usuario'], $_SESSION['id_nuevo']);
            } else {
                $_SESSION['message'] = "Error al registrar usuario externo: " . $stmt2->error;
            }
            $stmt2->close();
        } else {
            $_SESSION['message'] = "El usuario ya está registrado.";
        }
    } else {
        $_SESSION['message'] = "Todos los campos son obligatorios para el registro externo.";
    }
    header("Location: index.php");
    exit;
}

// Registrar visitante 
if (isset($_POST['register_ext_visitors'])) {
    $id_visitor = limpiar($_POST['idusers_ext'] ?? '');
    $empresa_id = $empresa_seleccionada ?? 84;

    if ($id_visitor !== '') {
        $stmt = $conex_coffe->prepare("SELECT num_attendance FROM visitors WHERE id_visitor = ?");
        $stmt->bind_param("i", $id_visitor);
        $stmt->execute();
        $stmt->bind_result($num_attendance);

        if ($stmt->fetch()) {
            $stmt->close();
            $num_attendance++;

            $update_stmt = $conex_coffe->prepare("UPDATE visitors SET num_attendance = ? WHERE id_visitor = ?");
            $update_stmt->bind_param("ii", $num_attendance, $id_visitor);
            $update_stmt->execute();
            $update_stmt->close();
        } else {
            $stmt->close();
            $insert_stmt = $conex_coffe->prepare("INSERT INTO visitors (id_visitor, num_attendance) VALUES (?, 1)");
            $insert_stmt->bind_param("i", $id_visitor);
            $insert_stmt->execute();
            $insert_stmt->close();
        }

        $surname = "Visitante";
        $name = "";

        $ins = $conex_coffe->prepare("INSERT INTO solicitudes_pendientes (idusers, nombre, surname, empresa_id, cantidad_cafes) VALUES (?, ?, ?, ?, 1)");
        $ins->bind_param("issi", $id_visitor, $name, $surname, $empresa_id);

        if ($ins->execute()) {
            $_SESSION['message'] = "¡Visitante registrado y solicitud enviada correctamente!";
        } else {
            $_SESSION['message'] = "Error al registrar la solicitud: " . $ins->error;
        }
        $ins->close();

        unset($_SESSION['nuevo_usuario'], $_SESSION['id_nuevo']);
    } else {
        $_SESSION['message'] = "Error: ID de visitante no válido.";
    }
    header("Location: index.php");
    exit;
}


$_SESSION['message'] = "Acción no válida.";
header("Location: index.php");
exit;
?>

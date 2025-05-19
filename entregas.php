<?php
session_start();
include("conexion.php");

$conex    = $conex_coffe;
$conexReg = $conex_users;


function responderJson($success, $message, $data = []) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        "success" => $success,
        "message" => $message
    ], $data));
    exit;
}


$empresa_id = $_REQUEST['company_id'] ?? null;
if (!$empresa_id) {
    responderJson(false, "ID de empresa no especificado.");
}
$empresa_id = intval($empresa_id);


if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'listar') {
    listarSolicitudes($empresa_id);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    procesarSolicitud(intval($_POST['id']), ($_POST['cancelar'] ?? null) === 'true', $empresa_id);
}


function listarSolicitudes($empresa_id) {
    global $conex;

    $stmt = $conex->prepare("
        SELECT id, idusers, nombre AS name, surname, cantidad_cafes
        FROM solicitudes_pendientes
        WHERE empresa_id = ?
        ORDER BY id ASC
    ");

    if (!$stmt) {
        responderJson(false, "Error en la consulta: " . $conex->error);
    }

    $stmt->bind_param("i", $empresa_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result) {
        responderJson(false, "Error al obtener resultados: " . $stmt->error);
    }

    $data = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    responderJson(true, "OK", ["data" => $data]);
}


function procesarSolicitud($id, $cancelar, $empresa_id) {
    global $conex, $conexReg;

    if ($cancelar) {
        
        $stmt = $conex->prepare("
            SELECT idusers, empresa_id
            FROM solicitudes_pendientes
            WHERE id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->bind_result($idusers, $empresa_id_origen);
        if (!$stmt->fetch()) {
            $stmt->close();
            responderJson(false, "Solicitud no encontrada.");
        }
        $stmt->close();

        $stmt_check = $conex->prepare("
            SELECT total_canceladas
            FROM solicitudes_canceladas
            WHERE idusers = ?
        ");
        $stmt_check->bind_param("i", $idusers);
        $stmt_check->execute();
        $stmt_check->bind_result($total_canceladas);

        if ($stmt_check->fetch()) {
            $stmt_check->close();
           
            $stmt_update = $conex->prepare("
                UPDATE solicitudes_canceladas
                SET total_canceladas = total_canceladas + 1,
                    ultima_empresa_id = ?
                WHERE idusers = ?
            ");
            $stmt_update->bind_param("ii", $empresa_id_origen, $idusers);
            $stmt_update->execute();
            $stmt_update->close();
        } else {
            $stmt_check->close();
           
            $stmt_insert = $conex->prepare("
                INSERT INTO solicitudes_canceladas (idusers, total_canceladas, ultima_empresa_id)
                VALUES (?, 1, ?)
            ");
            $stmt_insert->bind_param("ii", $idusers, $empresa_id_origen);
            $stmt_insert->execute();
            $stmt_insert->close();
        }

        $del = $conex->prepare("DELETE FROM solicitudes_pendientes WHERE id = ?");
        $del->bind_param("i", $id);
        $ok = $del->execute();
        $del->close();

        responderJson($ok, $ok ? "Solicitud cancelada." : "Error al cancelar.");
    }

    $stmt = $conex->prepare("
        SELECT idusers, nombre, surname, empresa_id, cantidad_cafes
        FROM solicitudes_pendientes
        WHERE id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->bind_result($idusers, $name, $surname, $empresa_id_real, $cantidad_cafes);

    if (!$stmt->fetch()) {
        $stmt->close();
        responderJson(false, "Solicitud no encontrada.");
    }
    $stmt->close();

    $empresa_id_real = ($empresa_id_real == 4) ? 2 : $empresa_id_real;
    $hoy = date('Y-m-d');

    $check = $conex->prepare("
        SELECT COALESCE(SUM(cantidad_cafes), 0)
        FROM datos
        WHERE cedula = ? AND DATE(fecha) = ?
    ");
    $check->bind_param("is", $idusers, $hoy);
    $check->execute();
    $check->bind_result($totalHoy);
    $check->fetch();
    $check->close();

    if (($totalHoy + $cantidad_cafes) > 10) {
        responderJson(false, "Límite diario de 10 cafés superado (actual: $totalHoy, nuevo: $cantidad_cafes).");
    }

    $ins = $conex->prepare("
        INSERT INTO datos (cedula, nombre, surname, fecha, company, cantidad_cafes)
        VALUES (?, ?, ?, NOW(), ?, ?)
    ");
    $ins->bind_param("issii", $idusers, $name, $surname, $empresa_id_real, $cantidad_cafes);

    if (!$ins->execute()) {
        responderJson(false, "Error al insertar en datos: " . $ins->error);
    }
    $ins->close();

    $del2 = $conex->prepare("DELETE FROM solicitudes_pendientes WHERE id = ?");
    $del2->bind_param("i", $id);
    $del2->execute();
    $del2->close();

    responderJson(true, "Entrega registrada correctamente en DATOS.");
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Entregas Café</title>
  <link rel="stylesheet" href="Style.css">
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <audio id="notification-sound" src="notification.mp3" preload="auto"></audio>
</head>
<body>
<?php $empresa_id = intval($_GET['company_id'] ?? 0); ?>
<div class="cafe-theme" style="background-image: url('img/fondo_orden.png'); background-size: cover; background-position: center;">
  <h2 class="titulo-cafe">Solicitudes Pendientes 🍵</h2>

  <div class="dropdown-container">
    <a href="index.php" class="dropdown-item">Volver</a>
  </div>

  <div id="solicitudes-container" class="solicitudes-list">
    <div class="loader"></div>
  </div>
</div>

<script>
$(function(){
  let prevIds = [];
  const empresaId = <?= $empresa_id ?>;
  const audio = document.getElementById('notification-sound');

  function playSound() {
    audio.pause(); audio.currentTime = 0;
    audio.play().catch(() => {});
  }

  function showToast(message, success) {
    const toast = document.createElement('div');
    toast.className = success ? 'toast success' : 'toast error';
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => { toast.remove(); }, 3000);
  }

  function render(list) {
    let html = `<table><thead>
      <tr><th>Nombres</th><th>Apellidos</th><th>ID</th><th>Cant.</th><th></th></tr>
    </thead><tbody>`;

    if (!list.length) {
      html += `<tr><td colspan="5">No hay solicitudes pendientes.</td></tr>`;
    } else {
      list.forEach(i => {
        html += `<tr id="sol-${i.id}">
          <td>${i.name}</td>
          <td>${i.surname}</td>
          <td>${i.idusers}</td>
          <td>${i.cantidad_cafes ?? ''}</td>
          <td>
            <button class="boton-entregar" onclick="entregar(${i.id}, this)">Entregar</button>
            <button class="boton-cancelar" onclick="cancelar(${i.id}, this)">Cancelar</button>
          </td>
        </tr>`;
      });
    }

    html += `</tbody></table>`;
    $("#solicitudes-container").html(html);
  }

  function load() {
    $.getJSON(`entregas.php?action=listar&company_id=${empresaId}`, resp => {
      if (!resp.success) {
        $("#solicitudes-container").html("<p>Error cargando solicitudes.</p>");
        return;
      }

      const ids = resp.data.map(x => x.id);
      const nuevos = ids.filter(id => !prevIds.includes(id));
      if (nuevos.length > 0) {
        playSound();
        showToast("¡Nueva solicitud!", true);
      }

      render(resp.data);
      prevIds = ids;
    }).fail(() => {
      $("#solicitudes-container").html("<p>Error al cargar solicitudes (AJAX).</p>");
    });
  }

  window.entregar = (id, btn) => {
    $(btn).prop("disabled", true);
    $.post("entregas.php", { id, cancelar: false, company_id: empresaId }, r => {
      showToast(r.message, r.success);
      if (r.success) $(`#sol-${id}`).fadeOut(300);
    }, 'json').fail(() => showToast("Error al entregar", false));
  };

  window.cancelar = (id, btn) => {
    if (!confirm("¿Cancelar solicitud?")) return;
    $(btn).prop("disabled", true);
    $.post("entregas.php", { id, cancelar: true, company_id: empresaId }, r => {
      showToast(r.message, r.success);
      if (r.success) $(`#sol-${id}`).fadeOut(300);
    }, 'json').fail(() => showToast("Error al cancelar", false));
  };

  load();
  setInterval(load, 2000);
});
</script>
</body>
</html>

<?php
require_once 'reporte_logica.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Solicitudes</title>
    <link rel="stylesheet" href="Style.css">
    <script>
        function mostrarFiltros() {
            const filtro = document.getElementById('filtro').value;
            document.getElementById('filtro-usuario').style.display = (filtro === 'usuario') ? 'block' : 'none';
            document.getElementById('filtro-empresa').style.display = (filtro === 'empresa') ? 'block' : 'none';
        }
        window.onload = mostrarFiltros;
        function exportarExcel() {
            const data = <?= json_encode(isset($export_data_full) && !empty($export_data_full) ? $export_data_full : $export_data) ?>;
            if (data.length === 0) {
                alert("No hay datos para exportar.");
                return;
            }
            function formatearFecha(fecha) {
                if (!fecha) return '';
                const meses = [
                    'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
                    'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'
                ];
                const partes = fecha.split('-');
                if (partes.length < 3) return fecha;
                const dia = parseInt(partes[2], 10);
                const mes = meses[parseInt(partes[1], 10) - 1];
                return `${dia} de ${mes}`;
            }
            const fechaInicio = "<?= htmlspecialchars($fecha_inicio) ?>";
            const fechaFin = "<?= htmlspecialchars($fecha_fin) ?>";
            const textoRango = `Del ${formatearFecha(fechaInicio)} al ${formatearFecha(fechaFin)}`;

            let headers = [];
            <?php
            $filtro_actual = $filtro ?? '';
            if (isset($export_data_full) && !empty($export_data_full)) {
                $first = $export_data_full[0];
            } elseif (isset($export_data) && !empty($export_data)) {
                $first = $export_data[0];
            } else {
                $first = [];
            }
            ?>
            <?php if (!empty($first)): ?>
                headers = <?= json_encode(array_keys($first)) ?>;
            <?php else: ?>
                headers = [];
            <?php endif; ?>
            
            const cantidadKeys = ['Cantidad de Cafés', 'Total', 'cantidad_cafes'];
            let cantidadIdx = -1;
            for (let i = 0; i < headers.length; i++) {
                if (cantidadKeys.includes(headers[i])) {
                    cantidadIdx = i;
                    break;
                }
            }
            const dataOrdenada = data.map(row => headers.map((h, idx) => {
                if (idx === cantidadIdx && row[h] !== undefined && row[h] !== null && row[h] !== '') {
                    return Number(row[h]);
                }
                return row[h];
            }));

            const encabezado = [
                [textoRango],
                headers
            ];
            const ws = XLSX.utils.aoa_to_sheet([
                ...encabezado,
                ...dataOrdenada
            ]);

            // Forzar tipo numérico en la columna de cantidad de cafés
            if (cantidadIdx !== -1) {
                for (let i = 0; i < dataOrdenada.length; i++) {
                    const cellRef = XLSX.utils.encode_cell({c: cantidadIdx, r: i + 2}); // +2 por encabezados
                    if (ws[cellRef] && typeof ws[cellRef].v === 'number') {
                        ws[cellRef].t = 'n';
                    }
                }
            }

            const columnWidths = headers.map((key, idx) => ({
                wch: idx === 0 ? Math.max(25, key.length) : Math.max(
                    key.length,
                    ...data.map(row => String(row[key]).length)
                )
            }));
            ws['!cols'] = columnWidths;
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Reporte de Solicitudes");
            XLSX.writeFile(wb, "reporte_solicitudes.xlsx");
        }
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.17.0/xlsx.full.min.js"></script>
</head>
<body>
<button onclick="location.href='Login.php'" class="cerrar-sesion">Cerrar Sesión</button>
<div class="container">
    <div class="reporte-container">
        <img src="img/cafe-icono.png" alt="Café Icono" class="logo-reporte">
        <h4 class="titulo-reporte">Consulta de Solicitudes</h4>
        <form method="post" action="reporte.php" class="form-reporte">
            <label for="fecha_inicio" class="label-reporte">Fecha inicial:</label>
            <input type="date" name="fecha_inicio" value="<?= htmlspecialchars($fecha_inicio) ?>" required class="input-fecha">
            <label for="fecha_fin" class="label-reporte">Fecha final:</label>
            <input type="date" name="fecha_fin" value="<?= htmlspecialchars($fecha_fin) ?>" required class="input-fecha">
            <label for="filtro" class="label-reporte">Filtro:</label>
            <select name="filtro" id="filtro" onchange="mostrarFiltros()" required class="select-reporte">
                <option value="">-- Selecciona --</option>
                <option value="usuario" <?= ($filtro === 'usuario') ? 'selected' : '' ?>>Usuario</option>
                <option value="empresa" <?= ($filtro === 'empresa') ? 'selected' : '' ?>>Empresa</option>
                <option value="general" <?= ($filtro === 'general') ? 'selected' : '' ?>>General</option>
                <option value="total" <?= ($filtro === 'total') ? 'selected' : '' ?>>Total</option>
            </select>
            <div id="filtro-usuario" style="display:none;">
                <label for="cedula" class="empresa-actual">Cédula:</label>
                <input type="number" name="cedula" value="<?= htmlspecialchars($cedula) ?>" class="input-casilla">
            </div>
            <div id="filtro-empresa" style="display:none;">
                <label for="company_id">Empresa:</label>
                <select name="company_id" class="select-reporte">
                    <option value="">-- Selecciona --</option>
                    <?php
                    $empresas = $conex_coffe->query("SELECT idcompany, description FROM company1");
                    while ($row = $empresas->fetch_assoc()) {
                        $selected = ($row['idcompany'] == $company_id) ? 'selected' : '';
                        echo "<option value='{$row['idcompany']}' $selected>{$row['description']}</option>";
                    }
                    ?>
                </select>
            </div>
            <input type="submit" value="Consultar" class="boton boton-reporte">
        </form>
    </div>
    <form action="index.php" method="get">
        <input type="submit" value="Volver al Inicio" class="boton boton-volver">
    </form>
    <?php if (!empty($resultado) || $mostrar_tabla): ?>
        <div class="resultado">
            <?php if (!empty($resultado)): ?>
                <p><?= $resultado ?></p>
            <?php endif; ?>
            <?php if ($mostrar_tabla): ?>
                <h4 class="titulo-tabla">Resultados</h4>
                <table class="tabla-resultado">
                    <thead>
                        <tr>
                            <th>Filtro</th>
                            <th>Identificador</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($datos_tabla as $index => $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['Filtro']) ?></td>
                                <td><?= htmlspecialchars($row['Identificador']) ?></td>
                                <td><?= htmlspecialchars($row['Total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <button onclick="exportarExcel()" class="boton">Exportar a Excel</button>
                <div class="paginacion paginacion-estilizada" style="margin-top:10px;">
                    <?php if ($total_paginas > 1): ?>
                        <?php
                        $rango = 2;
                        $inicio = max(1, $pagina - $rango);
                        $fin = min($total_paginas, $pagina + $rango);
                        ?>
                        <?php if ($pagina > 1): ?>
                            <form method="post" action="reporte.php?pagina=<?= $pagina - 1 ?>" style="display:inline;">
                                <input type="hidden" name="fecha_inicio" value="<?= htmlspecialchars($fecha_inicio) ?>">
                                <input type="hidden" name="fecha_fin" value="<?= htmlspecialchars($fecha_fin) ?>">
                                <input type="hidden" name="filtro" value="<?= htmlspecialchars($filtro) ?>">
                                <input type="hidden" name="cedula" value="<?= htmlspecialchars($cedula) ?>">
                                <input type="hidden" name="company_id" value="<?= htmlspecialchars($company_id) ?>">
                                <button type="submit" class="boton-pagina boton-pagina-nav">&lsaquo; Anterior</button>
                            </form>
                        <?php endif; ?>
                        <?php for ($i = $inicio; $i <= $fin; $i++): ?>
                            <?php if ($i == $pagina): ?>
                                <span class="pagina-actual"><?= $i ?></span>
                            <?php else: ?>
                                <form method="post" action="reporte.php?pagina=<?= $i ?>" style="display:inline;">
                                    <input type="hidden" name="fecha_inicio" value="<?= htmlspecialchars($fecha_inicio) ?>">
                                    <input type="hidden" name="fecha_fin" value="<?= htmlspecialchars($fecha_fin) ?>">
                                    <input type="hidden" name="filtro" value="<?= htmlspecialchars($filtro) ?>">
                                    <input type="hidden" name="cedula" value="<?= htmlspecialchars($cedula) ?>">
                                    <input type="hidden" name="company_id" value="<?= htmlspecialchars($company_id) ?>">
                                    <button type="submit" class="boton-pagina"><?= $i ?></button>
                                </form>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($pagina < $total_paginas): ?>
                            <form method="post" action="reporte.php?pagina=<?= $pagina + 1 ?>" style="display:inline;">
                                <input type="hidden" name="fecha_inicio" value="<?= htmlspecialchars($fecha_inicio) ?>">
                                <input type="hidden" name="fecha_fin" value="<?= htmlspecialchars($fecha_fin) ?>">
                                <input type="hidden" name="filtro" value="<?= htmlspecialchars($filtro) ?>">
                                <input type="hidden" name="cedula" value="<?= htmlspecialchars($cedula) ?>">
                                <input type="hidden" name="company_id" value="<?= htmlspecialchars($company_id) ?>">
                                <button type="submit" class="boton-pagina boton-pagina-nav">Siguiente &rsaquo;</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>

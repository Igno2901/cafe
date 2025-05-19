<?php
session_start();
if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
    header('Location: Login.php');
    exit;
}
$rol = $_SESSION['rol'] ?? '';
$company_id_restriccion = ($_SESSION['company_id'] == 4) ? 2 : $_SESSION['company_id'];
include("conexion.php");

function limpiar($dato) {
    return htmlspecialchars(trim($dato));
}

function obtenerResultado($conex, $query, $params = [], $types = '') {
    $stmt = $conex->prepare($query);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $resultado = $stmt->get_result();
    $stmt->close();
    return $resultado;
}


function obtenerUsuarioPorCedula($cedula, $conex_users, $conex_coffe) {
    $usuario = obtenerResultado(
        $conex_users,
        "SELECT name, '' as surname, company as company_id FROM users WHERE idusers = ?",
        [$cedula],
        "s"
    )->fetch_assoc();
    if (!$usuario) {
        $usuario = obtenerResultado(
            $conex_coffe,
            "SELECT name, surname, company as company_id FROM users_ext WHERE id_user = ?",
            [$cedula],
            "s"
        )->fetch_assoc();
    }
    return $usuario;
}

function obtenerNombreEmpresa($company_id, $conex_coffe) {
    return obtenerResultado(
        $conex_coffe,
        "SELECT description FROM company1 WHERE idcompany = ?",
        [$company_id],
        "i"
    )->fetch_assoc()['description'] ?? '';
}

function obtenerEmpresasNombres($conex_coffe) {
    $empresas_nombres = [];
    $empresas_query = $conex_coffe->query("SELECT idcompany, description FROM company1");
    while ($row = $empresas_query->fetch_assoc()) {
        $empresas_nombres[$row['idcompany']] = $row['description'];
    }
    return $empresas_nombres;
}

$filtro = limpiar($_POST['filtro'] ?? '');
$cedula = limpiar($_POST['cedula'] ?? '');
$company_id = limpiar($_POST['company_id'] ?? '');
$fecha_inicio = limpiar($_POST['fecha_inicio'] ?? '');
$fecha_fin = limpiar($_POST['fecha_fin'] ?? '');
$fecha_inicio_sql = $fecha_inicio ? $fecha_inicio . ' 00:00:01' : '';
$fecha_fin_sql = $fecha_fin ? $fecha_fin . ' 23:59:59' : '';
$pagina = limpiar($_GET['pagina'] ?? 1);
$limit = 10; 
$offset = ($pagina - 1) * $limit;
$total_registros = 0;
$total_paginas = 1;
$resultado = '';
$mostrar_tabla = false;
$datos_tabla = [];
$export_data = [];
$export_data_full = []; 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $fecha_inicio && $fecha_fin) {
    switch ($filtro) {
        case 'usuario':
            if (!empty($cedula)) {
                $usuario = obtenerUsuarioPorCedula($cedula, $conex_users, $conex_coffe);
                if ($usuario) {
                    $usuario['surname'] = $usuario['surname'] ?? '';
                    $company_id_usuario = $usuario['company_id'] ?? 0;
                    $tipo_usuario = ($company_id_usuario >= 1 && $company_id_usuario <= 4) ? '(Interno)' : '(Externo)';
                    $query_count = "SELECT COUNT(*) as total
                        FROM (
                            SELECT c.description
                            FROM coffe_nn.datos d
                            INNER JOIN coffe_nn.company1 c ON d.company = c.idcompany
                            WHERE d.cedula = ? AND d.fecha >= ? AND d.fecha <= ?"
                            . ($rol === 'patio' ? " AND d.company = ?" : "") . "
                            GROUP BY c.description
                        ) as sub";
                    $params_count = $rol === 'patio' ? [$cedula, $fecha_inicio_sql, $fecha_fin_sql, $company_id_restriccion] : [$cedula, $fecha_inicio_sql, $fecha_fin_sql];
                    $types_count = $rol === 'patio' ? "sssi" : "sss";
                    $res_count = obtenerResultado(
                        $conex_users,
                        $query_count,
                        $params_count,
                        $types_count
                    );
                    $total_registros = $res_count->fetch_assoc()['total'] ?? 0;
                    $total_paginas = max(1, ceil($total_registros / $limit));
                    $por_patio = obtenerResultado(
                        $conex_users, 
                        "SELECT c.description AS patio, SUM(d.cantidad_cafes) AS total
                         FROM coffe_nn.datos d
                         INNER JOIN coffe_nn.company1 c ON d.company = c.idcompany
                         WHERE d.cedula = ? AND d.fecha >= ? AND d.fecha <= ?"
                         . ($rol === 'patio' ? " AND d.company = ?" : "") . "
                         GROUP BY c.description
                         LIMIT $limit OFFSET $offset",
                        $rol === 'patio' ? [$cedula, $fecha_inicio_sql, $fecha_fin_sql, $company_id_restriccion] : [$cedula, $fecha_inicio_sql, $fecha_fin_sql],
                        $rol === 'patio' ? "sssi" : "sss"
                    );
                    if ($rol === 'patio' && !$por_patio->num_rows) {
                        $resultado = "No tienes permiso para consultar datos de este usuario en otro patio.";
                        break;
                    }
                    $suma_total = 0;
                    $detalle_por_patios = [];
                    while ($row = $por_patio->fetch_assoc()) {
                        $detalle_por_patios[] = [
                            'Filtro' => 'Usuario',
                            'Identificador' => "{$usuario['name']} {$usuario['surname']} $tipo_usuario -- {$row['patio']}",
                            'Total' => $row['total']
                        ];
                        $suma_total += $row['total'];
                    }
                    if ($suma_total > 0) {
                        $datos_tabla = $detalle_por_patios;
                        $datos_tabla[] = [
                            'Filtro' => 'Usuario',
                            'Identificador' => 'Total',
                            'Total' => $suma_total
                        ];
                        $mostrar_tabla = true;
                    } else {
                        $resultado = "El usuario con cédula <strong>$cedula</strong> no solicitó cafés entre <strong>$fecha_inicio</strong> y <strong>$fecha_fin</strong>.";
                    }
                } else {
                    $resultado = "No se encontró un usuario con la cédula <strong>$cedula</strong>.";
                }
            }
            break;
        case 'empresa':
            if (!empty($company_id)) {
                $empresa_nombre = obtenerNombreEmpresa($company_id, $conex_coffe);
                if ($empresa_nombre) {
                    $usuarios = [];
                    if ($rol === 'patio') {
                        $query_count_users = "SELECT COUNT(*) as total
                            FROM (
                                SELECT d.cedula
                                FROM datos d
                                INNER JOIN sigo_tu_salud.users u ON d.cedula = u.idusers
                                WHERE d.company = ? AND u.company = ? AND d.fecha >= ? AND d.fecha <= ?
                                GROUP BY d.cedula
                            ) as sub";
                        $params_count_users = [$company_id_restriccion, $company_id, $fecha_inicio_sql, $fecha_fin_sql];
                        $types_count_users = "iiss";
                        $res_count_users = obtenerResultado(
                            $conex_coffe,
                            $query_count_users,
                            $params_count_users,
                            $types_count_users
                        );
                        $total_users = $res_count_users->fetch_assoc()['total'] ?? 0;

                        $query_count_ext = "SELECT COUNT(*) as total
                            FROM (
                                SELECT d.cedula
                                FROM datos d
                                INNER JOIN users_ext ue ON d.cedula = ue.id_user
                                WHERE d.company = ? AND ue.company = ? AND d.fecha >= ? AND d.fecha <= ?
                                GROUP BY d.cedula
                            ) as sub";
                        $params_count_ext = [$company_id_restriccion, $company_id, $fecha_inicio_sql, $fecha_fin_sql];
                        $types_count_ext = "iiss";
                        $res_count_ext = obtenerResultado(
                            $conex_coffe,
                            $query_count_ext,
                            $params_count_ext,
                            $types_count_ext
                        );
                        $total_ext = $res_count_ext->fetch_assoc()['total'] ?? 0;

                        $total_registros = $total_users + $total_ext;
                        $total_paginas = max(1, ceil($total_registros / $limit));

                        $consulta_users_sql = "SELECT 
                                d.cedula AS idusers,
                                SUM(d.cantidad_cafes) AS cantidad_cafes,
                                u.company as company_id
                             FROM datos d
                             INNER JOIN sigo_tu_salud.users u ON d.cedula = u.idusers
                             WHERE d.company = ? AND u.company = ? AND d.fecha >= ? AND d.fecha <= ?
                             GROUP BY d.cedula
                             ORDER BY cantidad_cafes DESC
                             LIMIT $limit OFFSET $offset";
                        $consulta_users_params = [$company_id_restriccion, $company_id, $fecha_inicio_sql, $fecha_fin_sql];
                        $consulta_users_types = "iiss";
                        $consulta_users = obtenerResultado(
                            $conex_coffe,
                            $consulta_users_sql,
                            $consulta_users_params,
                            $consulta_users_types
                        );
                        $usuarios_count = 0;
                        while ($row = $consulta_users->fetch_assoc()) {
                            $cedula = $row['idusers'];
                            $cantidad = $row['cantidad_cafes'];
                            $company_id_usuario = $row['company_id'];
                            $user_data = obtenerResultado(
                                $conex_users,
                                "SELECT name, surname FROM users WHERE idusers = ?",
                                [$cedula],
                                "s"
                            )->fetch_assoc();
                            $name = $user_data['name'] ?? 'Desconocido';
                            $surname = $user_data['surname'] ?? '';
                            $usuarios[] = [
                                'Nombre' => $name,
                                'Apellido' => $surname,
                                'Cédula' => $cedula,
                                'Cantidad de Cafés' => $cantidad,
                                'company_id' => $company_id_usuario
                            ];
                            $usuarios_count++;
                        }
                        if ($usuarios_count < $limit) {
                            $limit_ext = $limit - $usuarios_count;
                            $offset_ext = max(0, $offset - $total_users);
                            $consulta_ext_sql = "SELECT 
                                    d.cedula AS idusers,
                                    SUM(d.cantidad_cafes) AS cantidad_cafes,
                                    ue.company as company_id
                                 FROM datos d
                                 INNER JOIN users_ext ue ON d.cedula = ue.id_user
                                 WHERE d.company = ? AND ue.company = ? AND d.fecha >= ? AND d.fecha <= ?
                                 GROUP BY d.cedula
                                 ORDER BY cantidad_cafes DESC
                                 LIMIT $limit_ext OFFSET $offset_ext";
                            $consulta_ext_params = [$company_id_restriccion, $company_id, $fecha_inicio_sql, $fecha_fin_sql];
                            $consulta_ext_types = "iiss";
                            $consulta_ext = obtenerResultado(
                                $conex_coffe,
                                $consulta_ext_sql,
                                $consulta_ext_params,
                                $consulta_ext_types
                            );
                            while ($row = $consulta_ext->fetch_assoc()) {
                                $cedula = $row['idusers'];
                                $cantidad = $row['cantidad_cafes'];
                                $company_id_usuario = $row['company_id'];
                                $user_ext = obtenerResultado(
                                    $conex_coffe,
                                    "SELECT name, surname FROM users_ext WHERE id_user = ?",
                                    [$cedula],
                                    "s"
                                )->fetch_assoc();
                                $name = $user_ext['name'] ?? 'Desconocido';
                                $surname = $user_ext['surname'] ?? '';
                                $usuarios[] = [
                                    'Nombre' => $name,
                                    'Apellido' => $surname,
                                    'Cédula' => $cedula,
                                    'Cantidad de Cafés' => $cantidad,
                                    'company_id' => $company_id_usuario
                                ];
                            }
                        }
                    } else {
                        $query_count = "SELECT COUNT(*) as total
                            FROM (
                                SELECT d.cedula
                                FROM datos d
                                LEFT JOIN sigo_tu_salud.users u ON d.cedula = u.idusers
                                LEFT JOIN users_ext ue ON d.cedula = ue.id_user
                                WHERE d.company = ? AND d.fecha >= ? AND d.fecha <= ?";
                        $params_count = [$company_id, $fecha_inicio_sql, $fecha_fin_sql];
                        $types_count = "iss";
                        if ($rol === 'patio') {
                            $query_count .= " AND d.company = ?";
                            $params_count[] = $company_id_restriccion;
                            $types_count .= "i";
                        }
                        $query_count .= " GROUP BY d.cedula ) as sub";
                        $res_count = obtenerResultado(
                            $conex_coffe,
                            $query_count,
                            $params_count,
                            $types_count
                        );
                        $total_registros = $res_count->fetch_assoc()['total'] ?? 0;
                        $total_paginas = max(1, ceil($total_registros / $limit));
                        $consulta_sql = "SELECT 
                                d.cedula AS idusers,
                                SUM(d.cantidad_cafes) AS cantidad_cafes,
                                COALESCE(u.company, ue.company) as company_id
                             FROM datos d
                             LEFT JOIN sigo_tu_salud.users u ON d.cedula = u.idusers
                             LEFT JOIN users_ext ue ON d.cedula = ue.id_user
                             WHERE d.company = ? AND d.fecha >= ? AND d.fecha <= ?";
                        $consulta_params = [$company_id, $fecha_inicio_sql, $fecha_fin_sql];
                        $consulta_types = "iss";
                        if ($rol === 'patio') {
                            $consulta_sql .= " AND d.company = ?";
                            $consulta_params[] = $company_id_restriccion;
                            $consulta_types .= "i";
                        }
                        $consulta_sql .= " GROUP BY d.cedula
                             ORDER BY cantidad_cafes DESC
                             LIMIT $limit OFFSET $offset";
                        $consulta = obtenerResultado(
                            $conex_coffe,
                            $consulta_sql,
                            $consulta_params,
                            $consulta_types
                        );
                        while ($row = $consulta->fetch_assoc()) {
                            $cedula = $row['idusers'];
                            $cantidad = $row['cantidad_cafes'];
                            $company_id_usuario = $row['company_id'];
                            $user_data = obtenerResultado(
                                $conex_users,
                                "SELECT name, surname FROM users WHERE idusers = ?",
                                [$cedula],
                                "s"
                            )->fetch_assoc();
                            if ($user_data) {
                                $name = $user_data['name'];
                                $surname = $user_data['surname'] ?? '';
                            } else {
                                $user_ext = obtenerResultado(
                                    $conex_coffe,
                                    "SELECT name, surname FROM users_ext WHERE id_user = ?",
                                    [$cedula],
                                    "s"
                                )->fetch_assoc();
                                $name = $user_ext['name'] ?? 'Desconocido';
                                $surname = $user_ext['surname'] ?? '';
                            }
                            $usuarios[] = [
                                'Nombre' => $name,
                                'Apellido' => $surname,
                                'Cédula' => $cedula,
                                'Cantidad de Cafés' => $cantidad,
                                'company_id' => $company_id_usuario
                            ];
                        }
                    }
                    if (!empty($usuarios)) {
                        foreach ($usuarios as $u) {
                            $tipo_usuario = ($u['company_id'] >= 1 && $u['company_id'] <= 4) ? '(Interno)' : '(Externo)';
                            $datos_tabla[] = [
                                'Filtro' => 'Empresa',
                                'Identificador' => "{$u['Nombre']} {$u['Apellido']} (ID: {$u['Cédula']}) $tipo_usuario -- $empresa_nombre",
                                'Total' => $u['Cantidad de Cafés']
                            ];
                        }
                        $export_data = $usuarios;
                        
                        $usuarios_full = [];
                        $empresas_nombres_full = obtenerEmpresasNombres($conex_coffe);
                        if ($rol === 'patio') {
                            $consulta_users_full = obtenerResultado(
                                $conex_coffe,
                                "SELECT d.cedula AS idusers, SUM(d.cantidad_cafes) AS cantidad_cafes, u.company as company_id
                                 FROM datos d
                                 INNER JOIN sigo_tu_salud.users u ON d.cedula = u.idusers
                                 WHERE d.company = ? AND u.company = ? AND d.fecha >= ? AND d.fecha <= ?
                                 GROUP BY d.cedula
                                 ORDER BY cantidad_cafes DESC",
                                [$company_id_restriccion, $company_id, $fecha_inicio_sql, $fecha_fin_sql],
                                "iiss"
                            );
                            while ($row = $consulta_users_full->fetch_assoc()) {
                                $cedula = $row['idusers'];
                                $cantidad = $row['cantidad_cafes'];
                                $company_id_usuario = $row['company_id'];
                                $user_data = obtenerResultado(
                                    $conex_users,
                                    "SELECT name, surname FROM users WHERE idusers = ?",
                                    [$cedula],
                                    "s"
                                )->fetch_assoc();
                                $name = $user_data['name'] ?? 'Desconocido';
                                $surname = $user_data['surname'] ?? '';
                                $usuarios_full[] = [
                                    'Cédula' => $cedula,
                                    'Nombre' => $name,
                                    'Apellido' => $surname,
                                    'Cantidad de Cafés' => $cantidad,
                                    'Empresa' => $empresas_nombres_full[$company_id_usuario] ?? $company_id_usuario
                                ];
                            }
                            $consulta_ext_full = obtenerResultado(
                                $conex_coffe,
                                "SELECT d.cedula AS idusers, SUM(d.cantidad_cafes) AS cantidad_cafes, ue.company as company_id
                                 FROM datos d
                                 INNER JOIN users_ext ue ON d.cedula = ue.id_user
                                 WHERE d.company = ? AND ue.company = ? AND d.fecha >= ? AND d.fecha <= ?
                                 GROUP BY d.cedula
                                 ORDER BY cantidad_cafes DESC",
                                [$company_id_restriccion, $company_id, $fecha_inicio_sql, $fecha_fin_sql],
                                "iiss"
                            );
                            while ($row = $consulta_ext_full->fetch_assoc()) {
                                $cedula = $row['idusers'];
                                $cantidad = $row['cantidad_cafes'];
                                $company_id_usuario = $row['company_id'];
                                $user_ext = obtenerResultado(
                                    $conex_coffe,
                                    "SELECT name, surname FROM users_ext WHERE id_user = ?",
                                    [$cedula],
                                    "s"
                                )->fetch_assoc();
                                $name = $user_ext['name'] ?? 'Desconocido';
                                $surname = $user_ext['surname'] ?? '';
                                $usuarios_full[] = [
                                    'Cédula' => $cedula,
                                    'Nombre' => $name,
                                    'Apellido' => $surname,
                                    'Cantidad de Cafés' => $cantidad,
                                    'Empresa' => $empresas_nombres_full[$company_id_usuario] ?? $company_id_usuario
                                ];
                            }
                        } else {
                            $consulta_full = obtenerResultado(
                                $conex_coffe,
                                "SELECT d.cedula AS idusers, SUM(d.cantidad_cafes) AS cantidad_cafes, COALESCE(u.company, ue.company) as company_id
                                 FROM datos d
                                 LEFT JOIN sigo_tu_salud.users u ON d.cedula = u.idusers
                                 LEFT JOIN users_ext ue ON d.cedula = ue.id_user
                                 WHERE d.company = ? AND d.fecha >= ? AND d.fecha <= ?
                                 GROUP BY d.cedula
                                 ORDER BY cantidad_cafes DESC",
                                [$company_id, $fecha_inicio_sql, $fecha_fin_sql],
                                "iss"
                            );
                            while ($row = $consulta_full->fetch_assoc()) {
                                $cedula = $row['idusers'];
                                $cantidad = $row['cantidad_cafes'];
                                $company_id_usuario = $row['company_id'];
                                $user_data = obtenerResultado(
                                    $conex_users,
                                    "SELECT name, surname FROM users WHERE idusers = ?",
                                    [$cedula],
                                    "s"
                                )->fetch_assoc();
                                if ($user_data) {
                                    $name = $user_data['name'];
                                    $surname = $user_data['surname'] ?? '';
                                } else {
                                    $user_ext = obtenerResultado(
                                        $conex_coffe,
                                        "SELECT name, surname FROM users_ext WHERE id_user = ?",
                                        [$cedula],
                                        "s"
                                    )->fetch_assoc();
                                    $name = $user_ext['name'] ?? 'Desconocido';
                                    $surname = $user_ext['surname'] ?? '';
                                }
                                $usuarios_full[] = [
                                    'Cédula' => $cedula,
                                    'Nombre' => $name,
                                    'Apellido' => $surname,
                                    'Cantidad de Cafés' => $cantidad,
                                    'Empresa' => $empresas_nombres_full[$company_id_usuario] ?? $company_id_usuario
                                ];
                            }
                        }
                        $export_data_full = $usuarios_full;
                        $mostrar_tabla = true;
                    } else {
                        $resultado = "No se encontraron solicitudes en <strong>$empresa_nombre</strong> entre <strong>$fecha_inicio</strong> y <strong>$fecha_fin</strong> en este patio.";
                    }
                } else {
                    $resultado = "No se encontró la empresa.";
                }
            }
            break;
        case 'total':
            $export_data_full = [];
            $datos_empresa_full = null;
            if ($rol === 'patio') {
                $datos_empresa_full = obtenerResultado(
                    $conex_coffe,
                    "SELECT d.cedula, d.company, SUM(d.cantidad_cafes) AS cantidad_cafes
                     FROM datos d
                     WHERE d.fecha >= ? AND d.fecha <= ? AND d.company = ?
                     GROUP BY d.cedula, d.company",
                    [$fecha_inicio_sql, $fecha_fin_sql, $company_id_restriccion],
                    "ssi"
                );
            } else {
                $datos_empresa_full = obtenerResultado(
                    $conex_coffe,
                    "SELECT d.cedula, d.company, SUM(d.cantidad_cafes) AS cantidad_cafes
                     FROM datos d
                     WHERE d.fecha >= ? AND d.fecha <= ?
                     GROUP BY d.cedula, d.company",
                    [$fecha_inicio_sql, $fecha_fin_sql],
                    "ss"
                );
            }
            $empresas_nombres_full = obtenerEmpresasNombres($conex_coffe);
            $resumen = [];
            $total_general = 0;
            while ($row = $datos_empresa_full->fetch_assoc()) {
                $cedula = $row['cedula'];
                $empresa_id_solicitud = $row['company'];
                $cantidad = $row['cantidad_cafes'];
                $es_interno = in_array($empresa_id_solicitud, [1,2,3,4]);

                $user_data = obtenerResultado(
                    $conex_users,
                    "SELECT name, surname, company FROM users WHERE idusers = ?",
                    [$cedula],
                    "s"
                )->fetch_assoc();
                if ($user_data) {
                    $name = $user_data['name'];
                    $surname = $user_data['surname'] ?? '';
                    $empresa_id_real = $user_data['company'] ?? null;
                } else {
                    $user_ext = obtenerResultado(
                        $conex_coffe,
                        "SELECT name, surname, company FROM users_ext WHERE id_user = ?",
                        [$cedula],
                        "s"
                    )->fetch_assoc();
                    $name = $user_ext['name'] ?? 'Desconocido';
                    $surname = $user_ext['surname'] ?? '';
                    $empresa_id_real = $user_ext['company'] ?? null;
                }
                $empresa_nombre_real = $empresa_id_real && isset($empresas_nombres_full[$empresa_id_real]) ? $empresas_nombres_full[$empresa_id_real] : 'Empresa desconocida';
                $empresa_nombre_solicitud = $empresas_nombres_full[$empresa_id_solicitud] ?? 'Empresa Desconocida';

                $export_data_full[] = [
                    'Cédula' => $cedula,
                    'Nombre' => $name,
                    'Apellido' => $surname,
                    'Empresa Usuario' => $empresa_nombre_real,
                    'Empresa Solicitud' => $empresa_nombre_solicitud,
                    'Cantidad de Cafés' => $cantidad,
                    'Tipo' => $es_interno ? 'Interno' : 'Externo'
                ];
                if (!isset($resumen[$empresa_id_solicitud])) {
                    $resumen[$empresa_id_solicitud] = [
                        'nombre_empresa' => $empresa_nombre_solicitud,
                        'internos' => 0,
                        'externos' => 0
                    ];
                }
                if ($es_interno) {
                    $resumen[$empresa_id_solicitud]['internos'] += $cantidad;
                } else {
                    $resumen[$empresa_id_solicitud]['externos'] += $cantidad;
                }
                $total_general += $cantidad;
            }
            usort($export_data_full, function($a, $b) {
                return $b['Cantidad de Cafés'] <=> $a['Cantidad de Cafés'];
            });
            $datos_tabla = [];
            foreach ($resumen as $empresa) {
                if ($empresa['internos'] > 0) {
                    $datos_tabla[] = [
                        'Filtro' => 'Total por empresa (Internos)',
                        'Identificador' => "{$empresa['nombre_empresa']} (Internos)",
                        'Total' => $empresa['internos']
                    ];
                }
                if ($empresa['externos'] > 0) {
                    $datos_tabla[] = [
                        'Filtro' => 'Total por empresa (Externos)',
                        'Identificador' => "{$empresa['nombre_empresa']} (Externos)",
                        'Total' => $empresa['externos']
                    ];
                }
            }
            if ($total_general > 0) {
                $datos_tabla[] = [
                    'Filtro' => 'Total Entregas',
                    'Identificador' => 'Total de cafés solicitados',
                    'Total' => $total_general
                ];
                $export_data = $export_data_full;
                $mostrar_tabla = true;
                $total_paginas = 1;
                $pagina = 1;
            } else {
                $resultado = "No se encontraron solicitudes de café entre <strong>$fecha_inicio</strong> y <strong>$fecha_fin</strong>.";
            }
            break;
        case 'general':
            $query_count = "SELECT COUNT(*) as total
                FROM (
                    SELECT d.cedula
                    FROM datos d
                    WHERE d.company = ? AND d.fecha >= ? AND d.fecha <= ?
                    GROUP BY d.cedula
                ) as sub";
            $params_count = [$company_id_restriccion, $fecha_inicio_sql, $fecha_fin_sql];
            $res_count = obtenerResultado(
                $conex_coffe,
                $query_count,
                $params_count,
                "iss"
            );
            $total_registros = $res_count->fetch_assoc()['total'] ?? 0;
            $total_paginas = max(1, ceil($total_registros / $limit));

            $consulta_general = obtenerResultado(
                $conex_coffe,
                "SELECT 
                    d.cedula AS idusers,
                    SUM(d.cantidad_cafes) AS cantidad_cafes,
                    d.company AS patio_id
                 FROM datos d
                 WHERE d.company = ? AND d.fecha >= ? AND d.fecha <= ?
                 GROUP BY d.cedula, d.company
                 ORDER BY cantidad_cafes DESC
                 LIMIT $limit OFFSET $offset",
                [$company_id_restriccion, $fecha_inicio_sql, $fecha_fin_sql],
                "iss"
            );

            $empresas_nombres = obtenerEmpresasNombres($conex_coffe);
            $usuarios_general = [];
            while ($row = $consulta_general->fetch_assoc()) {
                $cedula = $row['idusers'];
                $cantidad = $row['cantidad_cafes'];

                $user_data = obtenerResultado(
                    $conex_users,
                    "SELECT name, surname, company FROM users WHERE idusers = ?",
                    [$cedula],
                    "s"
                )->fetch_assoc();
                if ($user_data) {
                    $name = $user_data['name'];
                    $surname = $user_data['surname'] ?? '';
                    $empresa_id_real = $user_data['company'] ?? null;
                } else {
                    $user_ext = obtenerResultado(
                        $conex_coffe,
                        "SELECT name, surname, company FROM users_ext WHERE id_user = ?",
                        [$cedula],
                        "s"
                    )->fetch_assoc();
                    $name = $user_ext['name'] ?? 'Desconocido';
                    $surname = $user_ext['surname'] ?? '';
                    $empresa_id_real = $user_ext['company'] ?? null;
                }
                $empresa_nombre_real = $empresa_id_real && isset($empresas_nombres[$empresa_id_real]) ? $empresas_nombres[$empresa_id_real] : 'Empresa desconocida';

                $usuarios_general[] = [
                    'Cédula' => $cedula,
                    'Empresa' => $empresa_nombre_real,
                    'Nombre' => $name,
                    'Apellido' => $surname,
                    'Cantidad de Cafés' => $cantidad
                ];
            }

            $export_data_full = [];
            $consulta_general_full = obtenerResultado(
                $conex_coffe,
                "SELECT 
                    d.cedula AS idusers,
                    SUM(d.cantidad_cafes) AS cantidad_cafes,
                    d.company AS patio_id
                 FROM datos d
                 WHERE d.company = ? AND d.fecha >= ? AND d.fecha <= ?
                 GROUP BY d.cedula, d.company
                 ORDER BY cantidad_cafes DESC",
                [$company_id_restriccion, $fecha_inicio_sql, $fecha_fin_sql],
                "iss"
            );
            while ($row = $consulta_general_full->fetch_assoc()) {
                $cedula = $row['idusers'];
                $cantidad = $row['cantidad_cafes'];

                $user_data = obtenerResultado(
                    $conex_users,
                    "SELECT name, surname, company FROM users WHERE idusers = ?",
                    [$cedula],
                    "s"
                )->fetch_assoc();
                if ($user_data) {
                    $name = $user_data['name'];
                    $surname = $user_data['surname'] ?? '';
                    $empresa_id_real = $user_data['company'] ?? null;
                } else {
                    $user_ext = obtenerResultado(
                        $conex_coffe,
                        "SELECT name, surname, company FROM users_ext WHERE id_user = ?",
                        [$cedula],
                        "s"
                    )->fetch_assoc();
                    $name = $user_ext['name'] ?? 'Desconocido';
                    $surname = $user_ext['surname'] ?? '';
                    $empresa_id_real = $user_ext['company'] ?? null;
                }
                $empresa_nombre_real = $empresa_id_real && isset($empresas_nombres[$empresa_id_real]) ? $empresas_nombres[$empresa_id_real] : 'Empresa desconocida';

                $export_data_full[] = [
                    'Cédula' => $cedula,
                    'Empresa' => $empresa_nombre_real,
                    'Nombre' => $name,
                    'Apellido' => $surname,
                    'Cantidad de Cafés' => $cantidad
                ];
            }

            if (!empty($usuarios_general)) {
                foreach ($usuarios_general as $u) {
                    $datos_tabla[] = [
                        'Filtro' => 'General',
                        'Identificador' => "{$u['Empresa']} - {$u['Nombre']} {$u['Apellido']}",
                        'Total' => $u['Cantidad de Cafés']
                    ];
                }
                $export_data = $usuarios_general;
                $mostrar_tabla = true;
            } else {
                $resultado = "No se encontraron solicitudes de café en la empresa seleccionada entre <strong>$fecha_inicio</strong> y <strong>$fecha_fin</strong>.";
            }
            break;
    }
}
?>
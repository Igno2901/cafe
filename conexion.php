<?php
$host = "srv-dbmysql.si18.com.co";
$usuario = "practicante";
$contrasena = "KMo7c5t3wH3hUfp7";
$baseDeDatos = "coffe_nn";
$db_sigo = "sigo_tu_salud";

try {
   
    $conex_coffe = new mysqli($host, $usuario, $contrasena, $baseDeDatos);
    $conex_coffe->set_charset("utf8mb4");

    
    $conex_users = new mysqli($host, $usuario, $contrasena, $db_sigo);
    $conex_users->set_charset("utf8mb4");

} catch (Exception $e) {    
    die("Error de conexión: " . $e->getMessage());
}
?>

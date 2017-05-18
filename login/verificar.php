<?php 
require_once '../vendor/autoload.php';
$whoops = new \Whoops\Run;
$whoops->pushHandler(new \Whoops\Handler\PrettyPageHandler);
$whoops->register();
include('../db/config.php');
session_start();
extract($_POST);
$password = sha1($password);
$result = $db->query("SELECT * FROM usuarios WHERE usuario LIKE '".$usuario."' AND password LIKE '".$password."'");
$usuario  = $result->fetchAll();

if($usuario)
{
	echo "si hay usuario";
}
else
{
	echo "no hay usuario";
	echo "<hr>";
	echo $password;
}

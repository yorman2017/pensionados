<?php
if($_SESSION['rol'])
{
	header("Location: ".$_SESSION['rol']."/"); /* Redirect browser */
	exit();
}
else
{
	header("Location: login/form.php"); /* Redirect browser */
	exit();
}


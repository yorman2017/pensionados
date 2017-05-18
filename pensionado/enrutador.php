<?php
session_start();

if($_SESSION['rol'] != "pensionado")
{
	header("Location: ../"); /* Redirect browser */
	exit();
}
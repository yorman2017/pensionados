<?php
session_start();

if($_SESSION['rol'] != "admin")
{
	header("Location: ../"); /* Redirect browser */
	exit();
}
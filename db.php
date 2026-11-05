<?php

$conn = mysqli_connect("localhost", "root", "", "agri_market", 3306);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8mb4");

?>
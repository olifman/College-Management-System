<?php
// includes/config.php
$servername = "localhost"; // Port 3307 balleessineerra, WampServer ofumaan 3306 fayyadama
$username = "root";
$password = "";
$database = "eplms_db";

// Create connection
$conn = mysqli_connect($servername, $username, $password, $database);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
    // includes/config.php keessa dabali
mysqli_query($conn, "SET time_zone = '+03:00'");
}
?>
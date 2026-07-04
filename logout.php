<?php
session_start();      // Anzisha session ili uweze kuifuta
session_unset();      // Futa taarifa zote za session
session_destroy();    // Haribu session yenyewe

// Hapa ndipo tunapobadilisha kuelekea kwenye index.php
header("Location: index.php"); 
exit();
?>
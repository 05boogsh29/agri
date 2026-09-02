<?php

$conn = mysqli_connect("localhost", "root", "", "agri_markettt", "3306");
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
} else {
    echo "Connected to database successfully!";
}

// Query to fetch active farmers from the 'farmers' table
$farmers_query = mysqli_query(
    $conn,
    "SELECT farmer_id, full_name FROM farmers WHERE status = 'active' ORDER BY full_name ASC"
);

// Initialize an array to hold farmer data
$farmers_data = [];
while ($row = mysqli_fetch_assoc($farmers_query)) {
    // Add each farmer's farmer_id and full_name to the array
    $farmers_data[] = $row;
}

// Return the farmer data as a JSON response
echo json_encode($farmers_data);
?>
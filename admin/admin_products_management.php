<?php

session_start();



if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {

    header("Location: index.php?page=login");

    exit();

}



require_once dirname(__DIR__) . '/db.php';

if (!$conn) {

    die("Database connection failed: " . mysqli_connect_error());

}



$message = "";



/* ================= HELPERS ================= */

function get_count($conn, $sql) {

    $result = mysqli_query($conn, $sql);

    if ($result) {

        $row = mysqli_fetch_assoc($result);

        return (int)($row['total'] ?? 0);

    }

    return 0;

}



function buildPageUrl($overrides = []) {

    $params = $_GET;



    foreach ($overrides as $key => $value) {

        $params[$key] = $value;

    }



    return '?' . http_build_query($params);

}



function renderPagination($current_page, $total_pages, $page_key) {

    if ($total_pages <= 1) {

        return '';

    }



    $html = '<div class="pagination-wrapper">';

    $html .= '<div class="pagination-info">Page ' . $current_page . ' of ' . $total_pages . '</div>';

    $html .= '<div class="pagination">';



    if ($current_page > 1) {

        $html .= '<a class="page-link" href="' . htmlspecialchars(buildPageUrl([$page_key => $current_page - 1])) . '">Previous</a>';

    }



    for ($i = 1; $i <= $total_pages; $i++) {

        $active = ($i === $current_page) ? ' active' : '';

        $html .= '<a class="page-link' . $active . '" href="' . htmlspecialchars(buildPageUrl([$page_key => $i])) . '">' . $i . '</a>';

    }



    if ($current_page < $total_pages) {

        $html .= '<a class="page-link" href="' . htmlspecialchars(buildPageUrl([$page_key => $current_page + 1])) . '">Next</a>';

    }



    $html .= '</div>';

    $html .= '</div>';



    return $html;

}



/* ================= ADD PRODUCT ================= */

if (isset($_POST['add_product'])) {

    $farmer_id = (int)$_POST['farmer_id'];

    $category_id = (int)$_POST['category_id'];

    $subcategory_id = (int)$_POST['subcategory_id'];

    $product_name = trim($_POST['product_name']);

    $quantity = (float)$_POST['quantity'];

    $unit = trim($_POST['unit']);

    $base_price = (float)$_POST['base_price'];

    $status = trim($_POST['status']);



    if (

        $farmer_id <= 0 ||

        $category_id <= 0 ||

        $subcategory_id <= 0 ||

        $product_name === "" ||

        $unit === "" ||

        $quantity < 0 ||

        $base_price < 0

    ) {

        $message = "Please fill in all required product fields correctly.";

    } else {

        $stmt = mysqli_prepare(

            $conn,

            "INSERT INTO products

            (farmer_id, category_id, subcategory_id, product_name, quantity, unit, base_price, status)

            VALUES (?, ?, ?, ?, ?, ?, ?, ?)"

        );



        mysqli_stmt_bind_param(

            $stmt,

            "iiisdsds",

            $farmer_id,

            $category_id,

            $subcategory_id,

            $product_name,

            $quantity,

            $unit,

            $base_price,

            $status

        );



        if (mysqli_stmt_execute($stmt)) {

            $message = "Product added successfully.";

        } else {

            $message = "Failed to add product.";

        }

    }

}



/* ================= UPDATE PRODUCT STATUS ================= */

if (isset($_POST['update_product_status'])) {

    $product_id = (int)$_POST['product_id'];

    $new_status = trim($_POST['new_status']);



    if (in_array($new_status, ['available', 'inactive', 'sold_out'])) {

        $stmt = mysqli_prepare($conn, "UPDATE products SET status = ? WHERE product_id = ?");

        mysqli_stmt_bind_param($stmt, "si", $new_status, $product_id);



        if (mysqli_stmt_execute($stmt)) {

            $message = "Product status updated successfully.";

        } else {

            $message = "Failed to update product status.";

        }

    }

}



/* ================= COUNTS ================= */

$total_products = get_count($conn, "SELECT COUNT(*) AS total FROM products");

$available_products = get_count($conn, "SELECT COUNT(*) AS total FROM products WHERE status = 'available'");

$inactive_products = get_count($conn, "SELECT COUNT(*) AS total FROM products WHERE status = 'inactive'");

$sold_out_products = get_count($conn, "SELECT COUNT(*) AS total FROM products WHERE status = 'sold_out'");



/* ================= FETCH FARMERS ================= */

$farmers_query = mysqli_query(

    $conn,

    "SELECT farmer_id, full_name

     FROM farmers

     WHERE status = 'active'

     ORDER BY full_name ASC"

);

if ($farmers_query) {

    while ($row = mysqli_fetch_assoc($farmers_query)) {

        $farmers_data[] = $row;

    }

}



/* ================= FETCH CATEGORIES ================= */

$categories_data = [];

$categories_query = mysqli_query(

    $conn,

    "SELECT category_id, category_name

     FROM product_categories

     ORDER BY category_name ASC"

);

if ($categories_query) {

    while ($row = mysqli_fetch_assoc($categories_query)) {

        $categories_data[] = $row;

    }

}



/* ================= FETCH SUBCATEGORIES ================= */

$subcategories_data = [];

$subcategories_query = mysqli_query(

    $conn,

    "SELECT subcategory_id, category_id, subcategory_name

     FROM product_subcategories

     ORDER BY subcategory_name ASC"

);

if ($subcategories_query) {

    while ($row = mysqli_fetch_assoc($subcategories_query)) {

        $subcategories_data[] = $row;

    }

}



/* ================= PAGINATION ================= */

$rows_per_page = 5;

$products_page = isset($_GET['products_page']) ? max(1, (int)$_GET['products_page']) : 1;

$total_products_pages = max(1, (int)ceil($total_products / $rows_per_page));



if ($products_page > $total_products_pages) {

    $products_page = $total_products_pages;

}



$products_offset = ($products_page - 1) * $rows_per_page;



/* ================= FETCH PRODUCT LIST ================= */

$product_list = mysqli_query(

    $conn,

    "SELECT

        p.product_id,

        p.product_name,

        p.quantity,

        p.unit,

        p.base_price,

        p.status,

        p.created_at,

        u.full_name AS farmer_name,

        c.category_name,

        s.subcategory_name

     FROM products p

     INNER JOIN farmers u ON p.farmer_id = u.farmer_id

     INNER JOIN product_categories c ON p.category_id = c.category_id

     INNER JOIN product_subcategories s ON p.subcategory_id = s.subcategory_id

     ORDER BY p.product_id DESC

     LIMIT $products_offset, $rows_per_page"

);

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Agri-Market Products Management</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>

        *{

            margin:0;

            padding:0;

            box-sizing:border-box;

            font-family:Arial, sans-serif;

        }



        body{

            min-height:100vh;

            background-image:url('bg.png');

            background-size:cover;

            background-position:center;

            background-attachment:fixed;

            padding:20px;

        }



        .dashboard-shell{

            width:100%;

            max-width:1450px;

            height:92vh;

            margin:0 auto;

            display:flex;

            gap:18px;

        }



        .sidebar{

            width:285px;

            background:rgba(7,16,3,0.96);

            border:1px solid rgba(169,179,73,0.55);

            border-radius:18px;

            box-shadow:0 0 35px rgba(132,170,42,0.22);

            padding:18px 14px;

            color:#ffe8a7;

            height:100%;

            max-height:92vh;

            overflow-y:auto;

        }



        .sidebar-logo{

            width:100%;

            max-width:220px;

            display:block;

            margin:0 auto 10px auto;

        }



        .sidebar-title{

            text-align:center;

            color:#ffe5a6;

            font-family:Georgia, serif;

            font-size:24px;

            margin-bottom:18px;

        }



        .menu-link{

            display:block;

            width:100%;

            text-decoration:none;

            color:#fff0b8;

            background:rgba(255,255,255,0.04);

            border:1px solid rgba(169,179,73,0.22);

            border-radius:10px;

            padding:12px 14px;

            margin-bottom:10px;

            font-size:14px;

            transition:0.2s;

        }



        *.menu-link:hover*,

        *.menu-link.active*{

            background:linear-gradient(to bottom, #8faa2e, #657f1a);

            color:#fff8d4;

        }



        .content{

            flex:1;

            background:rgba(7,16,3,0.95);

            border:1px solid rgba(169,179,73,0.55);

            border-radius:18px;

            box-shadow:0 0 35px rgba(132,170,42,0.22);

            padding:20px;

            color:#fff0b8;

            height:100%;

            overflow-y:auto;

        }



        .topbar{

            display:flex;

            justify-content:space-between;

            align-items:center;

            gap:10px;

            margin-bottom:20px;

            flex-wrap:wrap;

        }



        .page-title{

            color:#ffe5a6;

            font-family:Georgia, serif;

            font-size:34px;

        }



        .message-box{

            margin-bottom:15px;

            padding:12px 14px;

            border-radius:10px;

            background:rgba(255,255,255,0.06);

            border:1px solid rgba(255,214,100,0.18);

            color:#fff4c9;

            font-size:14px;

        }



        .stats-grid{

            display:grid;

            grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));

            gap:16px;

            margin-bottom:20px;

        }



        .stat-card{

            background:rgba(2,10,2,0.92);

            border:1px solid rgba(95,120,38,0.25);

            border-radius:14px;

            padding:18px;

        }



        .stat-label{

            font-size:14px;

            color:#ffd264;

            margin-bottom:8px;

        }



        .stat-value{

            font-size:30px;

            font-weight:bold;

            color:#fff2c3;

        }



        .panel{

            background:rgba(2,10,2,0.92);

            border:1px solid rgba(95,120,38,0.25);

            border-radius:14px;

            padding:16px;

            margin-bottom:18px;

        }



        .panel-header{

            display:flex;

            justify-content:space-between;

            align-items:center;

            gap:12px;

            flex-wrap:wrap;

            margin-bottom:14px;

        }



        .panel-title{

            font-size:22px;

            color:#ffe5a6;

            font-family:Georgia, serif;

        }



        .toggle-btn,

        .btn{

            border:none;

            border-radius:6px;

            cursor:pointer;

            background:linear-gradient(to bottom, #8faa2e, #657f1a);

            color:#fff2c3;

            font-size:15px;

            font-weight:bold;

            padding:11px 18px;

        }



        .section-note{

            font-size:13px;

            color:#f7edbf;

            margin-bottom:10px;

            line-height:1.5;

        }



        .form-box{

            display:none;

            margin-bottom:18px;

            padding:16px;

            background:rgba(255,255,255,0.04);

            border:1px solid rgba(169,179,73,0.20);

            border-radius:12px;

        }



        *.form-box.show*{

            display:block;

        }



        .form-grid{

            display:grid;

            grid-template-columns:1fr 1fr;

            gap:14px;

        }



        .form-group{

            display:flex;

            flex-direction:column;

        }



        *.form-group.full*{

            grid-column:1 / -1;

        }



        label{

            margin-bottom:8px;

            color:#ffd264;

            font-size:14px;

        }



        input, select, textarea{

            width:100%;

            background:#e4e2dc;

            border:none;

            outline:none;

            border-radius:6px;

            padding:10px 12px;

            font-size:14px;

            color:#333;

        }



        textarea{

            min-height:90px;

            resize:vertical;

        }



        .table-wrap{

            overflow-x:auto;

        }



        table{

            width:100%;

            border-collapse:collapse;

        }



        th, td{

            padding:12px 10px;

            text-align:left;

            border-bottom:1px solid rgba(255,255,255,0.08);

            font-size:14px;

            vertical-align:middle;

        }



        th{

            color:#ffd264;

        }



        td{

            color:#fff0b8;

        }



        .status-badge{

            display:inline-block;

            padding:5px 10px;

            border-radius:20px;

            font-size:12px;

            font-weight:bold;

        }



        .status-available{

            background:rgba(102,153,51,0.22);

            color:#dfffba;

        }



        .status-inactive{

            background:rgba(180,90,90,0.22);

            color:#ffd4d4;

        }



        .status-sold_out{

            background:rgba(200,160,70,0.22);

            color:#fff1b8;

        }



        .action-form{

            display:flex;

            gap:8px;

            flex-wrap:wrap;

        }



        .action-select{

            height:36px;

            border:none;

            outline:none;

            border-radius:6px;

            padding:0 10px;

            background:#e4e2dc;

            color:#333;

        }



        .pagination-wrapper{

            margin-top:16px;

            display:flex;

            justify-content:space-between;

            align-items:center;

            gap:10px;

            flex-wrap:wrap;

        }



        .pagination-info{

            font-size:13px;

            color:#f7edbf;

        }



        .pagination{

            display:flex;

            gap:8px;

            flex-wrap:wrap;

        }



        .page-link{

            display:inline-block;

            text-decoration:none;

            padding:8px 12px;

            border-radius:8px;

            background:rgba(255,255,255,0.05);

            border:1px solid rgba(169,179,73,0.25);

            color:#fff0b8;

            font-size:14px;

            transition:0.2s;

        }



        *.page-link:hover*{

            background:linear-gradient(to bottom, #8faa2e, #657f1a);

            color:#fff8d4;

        }



        *.page-link.active*{

            background:linear-gradient(to bottom, #8faa2e, #657f1a);

            color:#fff8d4;

            pointer-events:none;

        }



        @media (max-width: 1024px){

            .dashboard-shell{

                flex-direction:column;

                height:auto;

            }



            .sidebar{

                width:100%;

                max-height:none;

            }



            .content{

                height:auto;

            }



            .form-grid{

                grid-template-columns:1fr;

            }

        }

    

        /* =========================================================
           AGRI-MARKET ADMIN REDESIGN — SHARED VISUAL SYSTEM
           UI-only styling; existing PHP operations remain unchanged.
        ========================================================= */
        :root {
            --agri-olive: #8faa2e;
            --agri-olive-dark: #657f1a;
            --agri-gold: #ffd264;
            --agri-cream: #fff0b8;
            --agri-muted: #d2ddb6;
            --agri-border: rgba(169, 179, 73, 0.28);
            --agri-panel: rgba(5, 15, 4, 0.94);
        }

        body {
            padding: 24px;
            background-color: #101a0b;
        }

        .dashboard-shell {
            max-width: 1580px;
            min-height: calc(100vh - 48px);
            align-items: stretch;
            gap: 22px;
        }

        .sidebar {
            width: 270px;
            flex: 0 0 270px;
            min-height: calc(100vh - 48px);
            height: fit-content;
            max-height: calc(100vh - 48px);
            position: sticky;
            top: 24px;
            overflow-y: auto;
            padding: 24px 18px;
            border-radius: 22px;
            background: linear-gradient(180deg, rgba(10, 25, 5, 0.98), rgba(4, 12, 2, 0.98));
            border: 1px solid rgba(169, 179, 73, 0.42);
            box-shadow: 0 18px 48px rgba(0, 0, 0, 0.30), 0 0 28px rgba(132, 170, 42, 0.12);
        }

        .sidebar-logo {
            max-width: 205px;
            margin: 0 auto 14px;
        }

        .sidebar-title {
            font-size: 22px;
            letter-spacing: 0.04em;
            padding-bottom: 19px;
            margin-bottom: 17px;
            border-bottom: 1px solid rgba(255, 226, 155, 0.13);
        }

        .menu-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 10px;
        }

        .menu-link {
            display: flex;
            align-items: center;
            gap: 12px;
            min-height: 47px;
            padding: 12px 14px;
            margin: 0;
            border: 1px solid transparent;
            border-radius: 12px;
            color: #f4edc9;
            background: rgba(255, 255, 255, 0.035);
            font-size: 13.5px;
            font-weight: 600;
            line-height: 1.35;
            transition: background 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
        }

        .menu-link i {
            width: 19px;
            flex: 0 0 19px;
            text-align: center;
            color: #c7d88d;
            font-size: 15px;
        }

        .menu-link:hover {
            transform: translateX(3px);
            background: rgba(143, 170, 46, 0.15);
            border-color: rgba(169, 179, 73, 0.28);
            color: #fff9dc;
        }

        .menu-link.active {
            background: linear-gradient(120deg, rgba(143, 170, 46, 0.96), rgba(101, 127, 26, 0.96));
            border-color: rgba(232, 245, 181, 0.25);
            color: #fffbe6;
            box-shadow: 0 7px 18px rgba(101, 127, 26, 0.22);
        }

        .menu-link.active i {
            color: #fffbe6;
        }

        .content {
            min-width: 0;
            min-height: calc(100vh - 48px);
            padding: 30px;
            border-radius: 22px;
            background: linear-gradient(145deg, rgba(8, 20, 5, 0.97), rgba(4, 12, 3, 0.96));
            border: 1px solid rgba(169, 179, 73, 0.40);
            box-shadow: 0 18px 48px rgba(0, 0, 0, 0.27), 0 0 30px rgba(132, 170, 42, 0.10);
        }

        .topbar {
            align-items: flex-start;
            margin-bottom: 26px;
        }

        .page-heading-copy {
            display: flex;
            flex-direction: column;
            gap: 7px;
            min-width: 0;
        }

        .page-eyebrow {
            color: #b8cb76;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.17em;
            text-transform: uppercase;
        }

        .page-title {
            font-size: clamp(28px, 3vw, 38px);
            line-height: 1.12;
            letter-spacing: -0.025em;
        }

        .page-subtitle {
            max-width: 760px;
            color: #d0d9b9;
            font-size: 13px;
            line-height: 1.65;
        }

        .admin-badge {
            border-radius: 12px;
            padding: 11px 15px;
            background: rgba(143, 170, 46, 0.10);
            border: 1px solid rgba(169, 179, 73, 0.25);
            color: #f7efcf;
        }

        .stats-grid {
            grid-template-columns: repeat(auto-fit, minmax(205px, 1fr));
            gap: 16px;
            margin: 8px 0 25px;
        }

        .stat-card {
            position: relative;
            overflow: hidden;
            min-height: 142px;
            padding: 22px 21px;
            border: 1px solid rgba(143, 170, 46, 0.27);
            border-radius: 17px;
            background: linear-gradient(145deg, rgba(28, 47, 13, 0.96), rgba(5, 14, 4, 0.98));
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.17);
            transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .stat-card::before {
            content: "";
            position: absolute;
            inset: 0 auto 0 0;
            width: 4px;
            background: linear-gradient(180deg, #d7e99a, #8faa2e);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            border-color: rgba(192, 216, 111, 0.52);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.23);
        }

        .stat-icon {
            position: absolute;
            top: 18px;
            right: 18px;
            display: grid;
            place-items: center;
            width: 43px;
            height: 43px;
            border-radius: 13px;
            color: #d9e9a6;
            background: rgba(143, 170, 46, 0.16);
            border: 1px solid rgba(169, 179, 73, 0.20);
            font-size: 17px;
        }

        .stat-label {
            max-width: calc(100% - 55px);
            color: #cbd99c;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.09em;
            line-height: 1.45;
            text-transform: uppercase;
        }

        .stat-value {
            margin-top: 17px;
            color: #fff5d1;
            font-size: clamp(26px, 2.6vw, 34px);
            line-height: 1.1;
            letter-spacing: -0.035em;
            overflow-wrap: anywhere;
        }

        .panel {
            margin-bottom: 21px;
            padding: 21px;
            border: 1px solid rgba(143, 170, 46, 0.22);
            border-radius: 18px;
            background: linear-gradient(145deg, rgba(11, 25, 6, 0.97), rgba(3, 11, 3, 0.97));
            box-shadow: 0 10px 28px rgba(0, 0, 0, 0.15);
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 15px;
        }

        .panel-title {
            margin-bottom: 12px;
            color: #f5e5ad;
            font-size: 20px;
            line-height: 1.3;
            letter-spacing: -0.01em;
        }

        .panel-note {
            margin: -3px 0 15px;
            color: #c5d0a8;
            font-size: 12px;
            line-height: 1.6;
        }

        .panel-heading-group {
            display: flex;
            flex-direction: column;
            min-width: 0;
            gap: 2px;
        }

        .panel-heading-group .panel-title {
            margin-bottom: 8px;
        }

        .section-note {
            color: #c5d0a8;
            font-size: 12px;
            line-height: 1.65;
        }

        .table-wrap {
            overflow-x: auto;
            border: 1px solid rgba(169, 179, 73, 0.16);
            border-radius: 13px;
            background: rgba(0, 0, 0, 0.12);
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        th, td {
            padding: 13px 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.065);
            font-size: 13px;
            line-height: 1.5;
        }

        th {
            position: sticky;
            top: 0;
            z-index: 1;
            background: rgba(35, 52, 16, 0.98);
            color: #f3d77e;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.09em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        td {
            color: #f1edcf;
        }

        tbody tr:nth-child(even) td {
            background: rgba(255, 255, 255, 0.018);
        }

        tbody tr:hover td {
            background: rgba(143, 170, 46, 0.075);
        }

        tbody tr:last-child td {
            border-bottom: 0;
        }

        .status-badge {
            padding: 6px 11px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.035em;
            white-space: nowrap;
        }

        .btn, .toggle-btn {
            border-radius: 10px;
            padding: 10px 16px;
            box-shadow: 0 6px 15px rgba(101, 127, 26, 0.16);
            transition: transform 0.18s ease, filter 0.18s ease, box-shadow 0.18s ease;
        }

        .btn:hover, .toggle-btn:hover {
            transform: translateY(-1px);
            filter: brightness(1.08);
            box-shadow: 0 8px 18px rgba(101, 127, 26, 0.24);
        }

        .form-box {
            padding: 21px;
            border: 1px solid rgba(169, 179, 73, 0.23);
            border-radius: 15px;
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.055), rgba(255, 255, 255, 0.018));
        }

        input, select, textarea {
            min-height: 43px;
            border: 1px solid rgba(114, 129, 74, 0.35);
            border-radius: 9px;
        }

        input:focus, select:focus, textarea:focus {
            border-color: #9ebd45;
            box-shadow: 0 0 0 3px rgba(143, 170, 46, 0.16);
        }

        .pagination-wrapper {
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px solid rgba(255, 255, 255, 0.07);
        }

        .page-link {
            border-radius: 9px;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 700;
        }

        .message-box {
            border-radius: 12px;
            padding: 13px 16px;
            line-height: 1.55;
        }

        .quick-note {
            border-radius: 14px;
            border-color: rgba(169, 179, 73, 0.20);
            background: rgba(143, 170, 46, 0.07);
        }

        @media (max-width: 1024px) {
            body { padding: 14px; }
            .dashboard-shell { min-height: 0; flex-direction: column; }
            .sidebar {
                position: relative;
                top: 0;
                width: 100%;
                min-height: 0;
                max-height: none;
                flex-basis: auto;
            }
            .menu-group {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }
            .content { min-height: 0; padding: 24px; }
        }

        @media (max-width: 640px) {
            body { padding: 10px; }
            .sidebar { padding: 20px 15px; }
            .menu-group { grid-template-columns: 1fr; }
            .content { padding: 18px; }
            .stats-grid { grid-template-columns: 1fr; }
            .page-title { font-size: 29px; }
            .panel { padding: 16px; }
            .stat-card { min-height: 126px; }
        }

    </style>

</head>

<body>



<div class="dashboard-shell">



    <div class="sidebar">

        <img src="logo.png" alt="Agri-Market Logo" class="sidebar-logo">

        <div class="sidebar-title">Admin Panel</div>



        <?php $currentPage = basename($_SERVER['PHP_SELF']); ?>



        <div class="menu-group">

            <a href="admin_dashboard.php" class="menu-link <?= $currentPage === 'admin_dashboard.php' ? 'active' : '' ?>"><i class="fas fa-tachometer-alt"></i> Dashboard Overview</a>

            <a href="admin_users_management.php" class="menu-link <?= $currentPage === 'admin_users_management.php' ? 'active' : '' ?>"><i class="fas fa-users"></i> Users Management</a>

            <a href="admin_products_management.php" class="menu-link <?= $currentPage === 'admin_products_management.php' ? 'active' : '' ?>"><i class="fas fa-box"></i> Products</a>

            <a href="admin_bidding_auction.php" class="menu-link <?= $currentPage === 'admin_bidding_auction.php' ? 'active' : '' ?>"><i class="fas fa-gavel"></i> Bidding / Auction</a>

            <a href="admin_transaction_management.php" class="menu-link <?= $currentPage === 'admin_transaction_management.php' ? 'active' : '' ?>"><i class="fas fa-credit-card"></i> Transaction</a>

            <a href="admin_delivery_status.php" class="menu-link <?= $currentPage === 'admin_delivery_status.php' ? 'active' : '' ?>"><i class="fas fa-truck"></i> Delivery</a>

            <a href="admin_reports.php" class="menu-link <?= $currentPage === 'admin_reports.php' ? 'active' : '' ?>"><i class="fas fa-chart-bar"></i> Reports</a>

            <a href="../logout.php?logout=1" class="menu-link"><i class="fas fa-sign-out-alt"></i> Logout</a>

        </div>

    </div>



    <div class="content">

        <div class="topbar">
            <div class="page-heading-copy">
                <div class="page-eyebrow">INVENTORY WORKSPACE</div>
                <div class="page-title">Products Management</div>
                <p class="page-subtitle">Review marketplace listings, monitor product availability, and manage new agricultural products.</p>
            </div>

        </div>



        <?php if ($message !== "") { ?>

            <div class="message-box"><?php echo htmlspecialchars($message); ?></div>

        <?php } ?>



        <div class="stats-grid">

            <div class="stat-card">

                <div class="stat-icon"><i class="fas fa-boxes-stacked"></i></div><div class="stat-label">Total Products</div>

                <div class="stat-value"><?php echo $total_products; ?></div>

            </div>

            <div class="stat-card">

                <div class="stat-icon"><i class="fas fa-circle-check"></i></div><div class="stat-label">Available</div>

                <div class="stat-value"><?php echo $available_products; ?></div>

            </div>

            <div class="stat-card">

                <div class="stat-icon"><i class="fas fa-circle-pause"></i></div><div class="stat-label">Inactive</div>

                <div class="stat-value"><?php echo $inactive_products; ?></div>

            </div>

            <div class="stat-card">

                <div class="stat-icon"><i class="fas fa-box-open"></i></div><div class="stat-label">Sold Out</div>

                <div class="stat-value"><?php echo $sold_out_products; ?></div>

            </div>

        </div>



        <div class="panel">

            <div class="panel-header">

                <div class="panel-title">Product Inventory</div>

                <button type="button" class="toggle-btn" onclick="toggleProductForm()">Add Product</button>

            </div>



            <div class="section-note">

                Review product details, seller information, quantities, pricing, and listing status. Use Add Product to create a new marketplace listing.

            </div>



            <div id="productFormBox" class="form-box">

                <form method="POST">

                    <div class="form-grid">

                        <div class="form-group">

                            <label>Farmer</label>

                            <select name="farmer_id" required>

                                <option value="">Select Farmer</option>

                                <?php foreach ($farmers_data as $farmer) { ?>

                                    <option value="<?php echo $farmer['farmer_id']; ?>">

                                        <?php echo htmlspecialchars($farmer['full_name']); ?>

                                    </option>

                                <?php } ?>

                            </select>

                        </div>



                        <div class="form-group">

                            <label>Product Name</label>

                            <input type="text" name="product_name" required>

                        </div>



                        <div class="form-group">

                            <label>Category</label>

                            <select name="category_id" id="category_id" required>

                                <option value="">Select Category</option>

                                <?php foreach ($categories_data as $category) { ?>

                                    <option value="<?php echo $category['category_id']; ?>">

                                        <?php echo htmlspecialchars($category['category_name']); ?>

                                    </option>

                                <?php } ?>

                            </select>

                        </div>



                        <div class="form-group">

                            <label>Subcategory</label>

                            <select name="subcategory_id" id="subcategory_id" required>

                                <option value="">Select Subcategory</option>

                                <?php foreach ($subcategories_data as $subcategory) { ?>

                                    <option value="<?php echo $subcategory['subcategory_id']; ?>" data-category="<?php echo $subcategory['category_id']; ?>">

                                        <?php echo htmlspecialchars($subcategory['subcategory_name']); ?>

                                    </option>

                                <?php } ?>

                            </select>

                        </div>



                        <div class="form-group">

                            <label>Quantity</label>

                            <input type="number" step="0.01" name="quantity" required>

                        </div>



                        <div class="form-group">

                            <label>Unit</label>

                            <input type="text" name="unit" placeholder="kg, sack, tray, piece" required>

                        </div>



                        <div class="form-group">

                            <label>Base Price</label>

                            <input type="number" step="0.01" name="base_price" required>

                        </div>



                        <div class="form-group">

                            <label>Status</label>

                            <select name="status" required>

                                <option value="available">Available</option>

                                <option value="inactive">Inactive</option>

                                <option value="sold_out">Sold Out</option>

                            </select>

                        </div>



                        <div class="form-group full">

                            <button type="submit" name="add_product" class="btn">Save Product</button>

                        </div>

                    </div>

                </form>

            </div>



            <div class="table-wrap">

                <table>

                    <thead>

                        <tr>

                            <th>Product</th>

                            <th>Farmer</th>

                            <th>Category</th>

                            <th>Subcategory</th>

                            <th>Quantity</th>

                            <th>Base Price</th>

                            <th>Status</th>

                            <th>Date Added</th>

                            <th>Action</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php if ($product_list && mysqli_num_rows($product_list) > 0) { ?>

                            <?php while ($product = mysqli_fetch_assoc($product_list)) { ?>

                                <tr>

                                    <td><?php echo htmlspecialchars($product['product_name']); ?></td>

                                    <td><?php echo htmlspecialchars($product['farmer_name']); ?></td>

                                    <td><?php echo htmlspecialchars($product['category_name']); ?></td>

                                    <td><?php echo htmlspecialchars($product['subcategory_name']); ?></td>

                                    <td><?php echo number_format((float)$product['quantity'], 2) . ' ' . htmlspecialchars($product['unit']); ?></td>

                                    <td>₱<?php echo number_format((float)$product['base_price'], 2); ?></td>

                                    <td>

                                        <span class="status-badge status-<?php echo htmlspecialchars($product['status']); ?>">

                                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $product['status']))); ?>

                                        </span>

                                    </td>

                                    <td><?php echo htmlspecialchars($product['created_at']); ?></td>

                                    <td>

                                        <form method="POST" class="action-form">

                                            <input type="hidden" name="product_id" value="<?php echo $product['product_id']; ?>">

                                            <select name="new_status" class="action-select">

                                                <option value="available" <?php echo ($product['status'] === 'available') ? 'selected' : ''; ?>>Available</option>

                                                <option value="inactive" <?php echo ($product['status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>

                                                <option value="sold_out" <?php echo ($product['status'] === 'sold_out') ? 'selected' : ''; ?>>Sold Out</option>

                                            </select>

                                            <button type="submit" name="update_product_status" class="btn">Update</button>

                                        </form>

                                    </td>

                                </tr>

                            <?php } ?>

                        <?php } else { ?>

                            <tr>

                                <td colspan="9">No products found.</td>

                            </tr>

                        <?php } ?>

                    </tbody>

                </table>

            </div>



            <?php echo renderPagination($products_page, $total_products_pages, 'products_page'); ?>

        </div>

    </div>

</div>



<script>

function toggleProductForm() {

    const formBox = document.getElementById('productFormBox');

    formBox.classList.toggle('show');

}



function fetchFarmers() {

    fetch('fetch_farmers.php')

        .then(response => response.json())

        .then(data => {

            const farmerSelect = document.querySelector('select[name="farmer_id"]');

            farmerSelect.innerHTML = '<option value="">Select Farmer</option>';



            data.forEach(farmer => {

                const option = document.createElement('option');

                option.value = farmer.farmer_id;

                option.textContent = farmer.full_name;

                farmerSelect.appendChild(option);

            });

        })

        .catch(error => console.error('Error fetching farmers:', error));

}



document.addEventListener('DOMContentLoaded', function () {

    fetchFarmers();



    const categorySelect = document.getElementById('category_id');

    const subcategorySelect = document.getElementById('subcategory_id');



    function filterSubcategories() {

        const selectedCategory = categorySelect.value;

        const options = subcategorySelect.querySelectorAll('option');



        options.forEach(option => {

            const category = option.getAttribute('data-category');



            if (option.value === "") {

                option.hidden = false;

                return;

            }



            if (category === selectedCategory) {

                option.hidden = false;

            } else {

                option.hidden = true;

            }

        });



        subcategorySelect.value = "";

    }



    categorySelect.addEventListener('change', filterSubcategories);

    filterSubcategories();

});

</script>



</body>

</html>
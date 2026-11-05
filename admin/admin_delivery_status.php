<?php

session_start();



// ---------------- AUTH CHECK ----------------

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {

    header("Location: index.php?page=login");

    exit();

}



// ---------------- DB CONNECTION ----------------

require_once dirname(__DIR__) . '/db.php';

if (!$conn) {

    die("Database connection failed: " . mysqli_connect_error());

}

mysqli_set_charset($conn, "utf8mb4");



/* ================= AJAX: LIVE DELIVERY STATUS ================= */

if (isset($_GET['ajax']) && $_GET['ajax'] === 'delivery_statuses') {

    header('Content-Type: application/json');



    // auto-progress first

    mysqli_query($conn, "

        UPDATE deliveries

        SET delivery_status = 'shipped',

            shipped_at = IFNULL(shipped_at, NOW())

        WHERE delivery_status = 'pending'

          AND TIMESTAMPDIFF(SECOND, created_at, NOW()) >= 60

    ");



    mysqli_query($conn, "

        UPDATE deliveries

        SET delivery_status = 'delivered',

            delivered_at = IFNULL(delivered_at, NOW())

        WHERE delivery_status = 'shipped'

          AND shipped_at IS NOT NULL

          AND TIMESTAMPDIFF(SECOND, shipped_at, NOW()) >= 60

    ");



    $result = mysqli_query($conn, "

        SELECT

            delivery_id,

            delivery_status,

            shipped_at,

            delivered_at

        FROM deliveries

        ORDER BY delivery_id DESC

    ");



    $rows = [];

    if ($result) {

        while ($row = mysqli_fetch_assoc($result)) {

            $rows[] = $row;

        }

    }



    echo json_encode([

        'success' => true,

        'deliveries' => $rows

    ]);

    exit;

}



// ---------------- HELPER FUNCTIONS ----------------

function e($value) {

    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

}



function set_flash($message, $type = 'success') {

    $_SESSION['delivery_flash'] = ['message' => $message, 'type' => $type];

}



function redirect_to_page($pageNo = 1) {

    header("Location: delivery_status.php?page_no=" . max(1, (int)$pageNo));

    exit();

}



function get_total($conn, $sql) {

    $result = mysqli_query($conn, $sql);

    if ($result) {

        $row = mysqli_fetch_assoc($result);

        return (int)($row['total'] ?? 0);

    }

    return 0;

}



// ---------------- FLASH MESSAGE ----------------

$flash = $_SESSION['delivery_flash'] ?? null;

unset($_SESSION['delivery_flash']);



// ---------------- DEFAULT STATUS ----------------

mysqli_query($conn, "UPDATE deliveries SET delivery_status='pending' WHERE delivery_status IS NULL OR TRIM(delivery_status)=''");

mysqli_query($conn, "UPDATE deliveries SET delivery_status='shipped', shipped_at=IFNULL(shipped_at, created_at) WHERE delivery_status IN ('preparing','out_for_delivery')");



// ---------------- AUTO-PROGRESS STATUS ----------------

$deliveries = mysqli_query(

    $conn,

    "SELECT delivery_id, delivery_status, created_at, shipped_at, tracking_number

     FROM deliveries

     WHERE delivery_status IN ('pending','shipped') OR delivery_status IS NULL OR TRIM(delivery_status)=''"

);



$current_time = time();



while ($d = mysqli_fetch_assoc($deliveries)) {

    $delivery_id = (int)$d['delivery_id'];

    $current_status = $d['delivery_status'];

    $created_at = strtotime($d['created_at']);

    $shipped_at = !empty($d['shipped_at']) ? strtotime($d['shipped_at']) : null;



    // Generate tracking number if empty

    if (empty($d['tracking_number'])) {

        $random_str = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));

        $tracking_number = "DEL" . str_pad($delivery_id, 4, '0', STR_PAD_LEFT) . $random_str;



        $stmt = mysqli_prepare($conn, "UPDATE deliveries SET tracking_number=? WHERE delivery_id=?");

        mysqli_stmt_bind_param($stmt, "si", $tracking_number, $delivery_id);

        mysqli_stmt_execute($stmt);

        mysqli_stmt_close($stmt);

    }



    // Auto-update status every 1 minute per step

    if ($current_status === 'pending' && ($current_time - $created_at) >= 60) { // pending -> shipped in 1 min

        $stmt = mysqli_prepare($conn, "

            UPDATE deliveries

            SET delivery_status = 'shipped',

                shipped_at = IFNULL(shipped_at, NOW())

            WHERE delivery_id = ?

        ");

        mysqli_stmt_bind_param($stmt, "i", $delivery_id);

        mysqli_stmt_execute($stmt);

        mysqli_stmt_close($stmt);

    } elseif ($current_status === 'shipped') {

        $base_time = $shipped_at ?: $created_at;



        if (($current_time - $base_time) >= 60) { // shipped -> delivered in 1 min

            $stmt = mysqli_prepare($conn, "

                UPDATE deliveries

                SET delivery_status = 'delivered',

                    delivered_at = IFNULL(delivered_at, NOW())

                WHERE delivery_id = ?

            ");

            mysqli_stmt_bind_param($stmt, "i", $delivery_id);

            mysqli_stmt_execute($stmt);

            mysqli_stmt_close($stmt);

        }

    }

}



// ---------------- PAGINATION ----------------

$perPage = 5;

$pageNo = isset($_GET['page_no']) ? max(1,(int)$_GET['page_no']) : 1;

$total_deliveries = get_total($conn, "SELECT COUNT(*) AS total FROM deliveries");

$totalPages = ceil($total_deliveries / $perPage);

$offset = ($pageNo - 1) * $perPage;



// ---------------- UPDATE ADDRESS ----------------

if (isset($_POST['update_address'])) {

    $currentPage = isset($_POST['current_page']) ? (int)$_POST['current_page'] : 1;

    $delivery_id = (int)($_POST['delivery_id'] ?? 0);

    $delivery_address = trim($_POST['delivery_address'] ?? '');



    if ($delivery_id <= 0 || $delivery_address === '') {

        set_flash("Please enter a valid address.", "error");

        redirect_to_page($currentPage);

    }



    $stmt = mysqli_prepare($conn, "UPDATE deliveries SET delivery_address=? WHERE delivery_id=?");

    mysqli_stmt_bind_param($stmt, "si", $delivery_address, $delivery_id);

    if (mysqli_stmt_execute($stmt)) {

        set_flash("Delivery address updated successfully.", "success");

    } else {

        set_flash("Failed to update delivery address.", "error");

    }

    mysqli_stmt_close($stmt);

    redirect_to_page($currentPage);

}



/* ================= AUTO UPDATE DELIVERY STATUS ================= */

$update_stmt = mysqli_query($conn, "

    UPDATE deliveries

    SET 

        delivery_status = 'shipped',

        shipped_at = IFNULL(shipped_at, NOW())

    WHERE 

        delivery_status = 'pending'

        AND TIMESTAMPDIFF(SECOND, created_at, NOW()) >= 60

");



$update_stmt2 = mysqli_query($conn, "

    UPDATE deliveries

    SET 

        delivery_status = 'delivered',

        delivered_at = IFNULL(delivered_at, NOW())

    WHERE 

        delivery_status = 'shipped'

        AND TIMESTAMPDIFF(SECOND, shipped_at, NOW()) >= 60

");



// ---------------- DELIVERY SUMMARY COUNTS ----------------
$total_pending_deliveries = get_total($conn, "SELECT COUNT(*) AS total FROM deliveries WHERE delivery_status = 'pending'");
$total_shipped_deliveries = get_total($conn, "SELECT COUNT(*) AS total FROM deliveries WHERE delivery_status = 'shipped'");
$total_delivered_deliveries = get_total($conn, "SELECT COUNT(*) AS total FROM deliveries WHERE delivery_status = 'delivered'");

// ---------------- DELIVERY LIST ----------------

$delivery_list = mysqli_query(

    $conn,

    "SELECT

        d.delivery_id,

        d.transaction_id,

        d.delivery_address,

        d.courier_name,

        d.tracking_number,

        d.delivery_status,

        d.shipped_at,

        d.delivered_at,

        d.notes,

        d.created_at,

        p.product_name,

        seller.full_name AS seller_name,

        buyer.full_name AS buyer_name

     FROM deliveries d

     INNER JOIN transactions t ON d.transaction_id=t.transaction_id

     INNER JOIN products p ON t.product_id=p.product_id

     INNER JOIN farmers seller ON t.seller_id=seller.farmer_id

     INNER JOIN users buyer ON t.buyer_id=buyer.user_id

     ORDER BY d.delivery_id DESC

     LIMIT $offset, $perPage"

);



function status_class($status) {

    switch ($status) {

        case 'pending': return 'status-pending';

        case 'shipped': return 'status-shipped';

        case 'delivered': return 'status-delivered';

        default: return 'status-pending';

    }

}



function status_label($status) {

    switch ($status) {

        case 'pending': return 'Pending';

        case 'shipped': return 'Shipped';

        case 'delivered': return 'Delivered';

        default: return ucfirst(str_replace('_',' ',$status));

    }

}

?>



<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Agri-Market Delivery Status</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>

    * {

            margin: 0;

            padding: 0;

            box-sizing: border-box;

            font-family: Arial, sans-serif;

        }



        body {

            min-height: 100vh;

            background-image: url('bg.png');

            background-size: cover;

            background-position: center;

            background-attachment: fixed;

            padding: 20px;

        }



        .dashboard-shell {

            width: 100%;

            max-width: 1450px;

            min-height: 92vh;

            margin: 0 auto;

            display: flex;

            gap: 18px;

        }



        .sidebar {

            width: 285px;

            background: rgba(7, 16, 3, 0.96);

            border: 1px solid rgba(169, 179, 73, 0.55);

            border-radius: 18px;

            box-shadow: 0 0 35px rgba(132, 170, 42, 0.22);

            padding: 18px 14px;

            color: #ffe8a7;

        }



        .sidebar-logo {

            width: 100%;

            max-width: 220px;

            display: block;

            margin: 0 auto 10px auto;

        }



        .sidebar-title {

            text-align: center;

            color: #ffe5a6;

            font-family: Georgia, serif;

            font-size: 24px;

            margin-bottom: 18px;

        }



        .menu-link {

            display: block;

            width: 100%;

            text-decoration: none;

            color: #fff0b8;

            background: rgba(255, 255, 255, 0.04);

            border: 1px solid rgba(169, 179, 73, 0.22);

            border-radius: 10px;

            padding: 12px 14px;

            margin-bottom: 10px;

            font-size: 14px;

            transition: 0.2s;

        }



        *.menu-link:hover*,

        *.menu-link.active* {

            background: linear-gradient(to bottom, #8faa2e, #657f1a);

            color: #fff8d4;

        }



        .content {

            flex: 1;

            background: rgba(7, 16, 3, 0.95);

            border: 1px solid rgba(169, 179, 73, 0.55);

            border-radius: 18px;

            box-shadow: 0 0 35px rgba(132, 170, 42, 0.22);

            padding: 20px;

            color: #fff0b8;

        }



        .page-title {

            color: #ffe5a6;

            font-family: Georgia, serif;

            font-size: 34px;

            margin-bottom: 18px;

        }



        .message-box {

            margin-bottom: 15px;

            padding: 12px 14px;

            border-radius: 10px;

            font-size: 14px;

            border: 1px solid rgba(255, 214, 100, 0.18);

        }



        .message-success {

            background: rgba(70, 120, 40, 0.22);

            color: #eaf8d2;

        }



        .message-error {

            background: rgba(160, 60, 60, 0.20);

            color: #ffdede;

        }



        .stats-grid {

            display: grid;

            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));

            gap: 16px;

            margin-bottom: 20px;

        }



        .stat-card {

            background: rgba(2, 10, 2, 0.92);

            border: 1px solid rgba(95, 120, 38, 0.25);

            border-radius: 14px;

            padding: 18px;

        }



        .stat-label {

            font-size: 14px;

            color: #ffd264;

            margin-bottom: 8px;

        }



        .stat-value {

            font-size: 30px;

            font-weight: bold;

            color: #fff2c3;

        }



        .panel {

            background: rgba(2, 10, 2, 0.92);

            border: 1px solid rgba(95, 120, 38, 0.25);

            border-radius: 14px;

            padding: 16px;

            margin-bottom: 18px;

        }



        .panel-title {

            font-size: 22px;

            color: #ffe5a6;

            font-family: Georgia, serif;

            margin-bottom: 10px;

        }



        .section-note {

            font-size: 13px;

            color: #f7edbf;

            margin-bottom: 14px;

            line-height: 1.6;

        }



        .form-grid {

            display: grid;

            grid-template-columns: 1fr 1fr;

            gap: 14px;

        }



        .form-group {

            display: flex;

            flex-direction: column;

        }



        *.form-group.full* {

            grid-column: 1 / -1;

        }



        label {

            margin-bottom: 8px;

            color: #ffd264;

            font-size: 14px;

        }



        input,

        select,

        textarea {

            width: 100%;

            background: #e4e2dc;

            border: none;

            outline: none;

            border-radius: 6px;

            padding: 10px 12px;

            font-size: 14px;

            color: #333;

        }



        textarea {

            min-height: 80px;

            resize: vertical;

        }



        .readonly-box {

            width: 100%;

            background: rgba(255, 255, 255, 0.08);

            border: 1px solid rgba(255, 214, 100, 0.15);

            border-radius: 6px;

            padding: 10px 12px;

            color: #fff3c4;

            font-size: 14px;

        }



        .btn {

            border: none;

            border-radius: 6px;

            cursor: pointer;

            background: linear-gradient(to bottom, #8faa2e, #657f1a);

            color: #fff2c3;

            font-size: 14px;

            font-weight: bold;

            padding: 10px 16px;

        }



        *.btn:hover* {

            opacity: 0.95;

        }



        .empty-note {

            margin-top: 12px;

            padding: 10px 12px;

            border-radius: 8px;

            background: rgba(255, 255, 255, 0.05);

            border: 1px solid rgba(255, 214, 100, 0.15);

            color: #fff0b8;

            font-size: 13px;

            line-height: 1.5;

        }



        .table-wrap {

            overflow-x: auto;

        }



        table {

            width: 100%;

            border-collapse: collapse;

        }



        th,

        td {

            padding: 12px 10px;

            text-align: left;

            border-bottom: 1px solid rgba(255, 255, 255, 0.08);

            font-size: 14px;

            vertical-align: top;

        }



        th {

            color: #ffd264;

        }



        td {

            color: #fff0b8;

        }



        .status-badge {

            display: inline-block;

            padding: 5px 10px;

            border-radius: 20px;

            font-size: 12px;

            font-weight: bold;

        }



        .status-pending {

            background: rgba(200, 160, 70, 0.22);

            color: #fff1b8;

        }



        .status-shipped {

            background: rgba(100, 170, 210, 0.22);

            color: #d8f0ff;

        }



        .status-delivered {

            background: rgba(102, 153, 51, 0.22);

            color: #dfffba;

        }



        .update-form {

            display: flex;

            gap: 8px;

            min-width: 250px;

        }



        .update-form input {

            min-width: 180px;

        }



        .pagination {

            margin-top: 18px;

            display: flex;

            justify-content: center;

            gap: 8px;

            flex-wrap: wrap;

        }



        .page-link {

            text-decoration: none;

            padding: 8px 12px;

            border-radius: 8px;

            background: rgba(255, 255, 255, 0.06);

            color: #fff0b8;

            border: 1px solid rgba(169, 179, 73, 0.22);

            font-size: 14px;

        }



        *.page-link.active*,

        *.page-link:hover* {

            background: linear-gradient(to bottom, #8faa2e, #657f1a);

            color: #fff8d4;

        }



        .small-note {

            font-size: 12px;

            color: #f4e7b1;

            margin-top: 6px;

            line-height: 1.5;

        }



        .status-pending {

            background: #5f551b;

            color: #fff3bf;

            padding: 6px 14px;

            border-radius: 999px;

            display: inline-block;

            font-weight: 600;

        }



        .status-shipped {

            background: #1f4f7a;

            color: #d9f0ff;

            padding: 6px 14px;

            border-radius: 999px;

            display: inline-block;

            font-weight: 600;

        }



        .status-delivered {

            background: #2f6b2f;

            color: #e3ffe3;

            padding: 6px 14px;

            border-radius: 999px;

            display: inline-block;

            font-weight: 600;

        }



        @media (max-width: 1024px) {

            .dashboard-shell {

                flex-direction: column;

            }



            .sidebar {

                width: 100%;

            }



            .form-grid {

                grid-template-columns: 1fr;

            }



            .update-form {

                flex-direction: column;

                min-width: 180px;

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
                <div class="page-eyebrow">DELIVERY MONITORING</div>
                <div class="page-title">Delivery Records</div>
                <p class="page-subtitle">Monitor order fulfillment, update delivery addresses, and track the latest delivery status.</p>
            </div>
        </div>



        <?php if($flash): ?>

            <div class="message-box <?= $flash['type']==='success'?'message-success':'message-error' ?>">

                <?= e($flash['message']) ?>

            </div>

        <?php endif; ?>



        <div class="stats-grid delivery-stats">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-truck-fast"></i></div>
                <div class="stat-label">Total Deliveries</div>
                <div class="stat-value"><?php echo (int)$total_deliveries; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-label">Pending</div>
                <div class="stat-value"><?php echo (int)$total_pending_deliveries; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-truck"></i></div>
                <div class="stat-label">Shipped</div>
                <div class="stat-value"><?php echo (int)$total_shipped_deliveries; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-circle-check"></i></div>
                <div class="stat-label">Delivered</div>
                <div class="stat-value"><?php echo (int)$total_delivered_deliveries; ?></div>
            </div>
        </div>

        <div class="panel-title">Delivery Queue</div>
        <p class="panel-note">Update the delivery address while an order is pending or in transit. Completed deliveries are locked.</p>
        <div class="table-wrap">
            <table>

                <thead>

                    <tr>

                        <th>Product</th>

                        <th>Buyer</th>

                        <th>Seller</th>

                        <th>Address</th>

                        <th>Status</th>

                        <th>Tracking #</th>

                        <th>Action</th>

                    </tr>

                </thead>

                <tbody>

                    <?php while ($delivery = mysqli_fetch_assoc($delivery_list)) { ?>

                    <tr data-delivery-id="<?php echo (int)$delivery['delivery_id']; ?>">

                        <td><?php echo htmlspecialchars($delivery['product_name']); ?></td>

                        <td><?php echo htmlspecialchars($delivery['buyer_name']); ?></td>

                        <td><?php echo htmlspecialchars($delivery['seller_name']); ?></td>

                        <td><?php echo htmlspecialchars($delivery['delivery_address']); ?></td>

                        <td>

                            <span class="status-badge delivery-status status-<?php echo strtolower($delivery['delivery_status']); ?>">

                                <?php echo htmlspecialchars(ucfirst($delivery['delivery_status'])); ?>

                            </span>

                        </td>

                        <td><?php echo htmlspecialchars($delivery['tracking_number']); ?></td>

                        <td>

                            <?php if ($delivery['delivery_status'] === 'delivered') { ?>



                                <!-- Disabled view -->

                                <div style="display:flex; gap:10px; align-items:center;">

                                    <input 

                                        type="text" 

                                        value="<?php echo htmlspecialchars($delivery['delivery_address']); ?>"

                                        disabled

                                        style="flex:1; padding:10px; border-radius:6px; border:none; background:#ccc;"

                                    >



                                    <button class="btn" disabled style="opacity:0.6; cursor:not-allowed;">

                                        Completed

                                    </button>

                                </div>



                            <?php } else { ?>



                                <!-- Editable view -->

                                <form method="POST" class="update-form">

                                    <input type="hidden" name="delivery_id" value="<?php echo (int)$delivery['delivery_id']; ?>">

                                    <input type="hidden" name="current_page" value="<?php echo (int)$pageNo; ?>">



                                    <input

                                        type="text"

                                        name="delivery_address"

                                        value="<?php echo htmlspecialchars($delivery['delivery_address'] ?? ''); ?>"

                                        placeholder="Update Address"

                                        required

                                    >



                                    <button type="submit" name="update_address" class="btn">

                                        Update

                                    </button>

                                </form>



                            <?php } ?>

                        </td>

                    </tr>

                    <?php } ?>

                    </tbody>

            </table>

        </div>



        <!-- Pagination -->

        <?php if ($totalPages > 1): ?>

            <div class="pagination">

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>

                    <a href="delivery_status.php?page_no=<?= $i ?>" class="page-link <?= $i === $pageNo ? 'active' : '' ?>"><?= $i ?></a>

                <?php endfor; ?>

            </div>

        <?php endif; ?>



    </div>

</div>



<script>

async function refreshDeliveryStatuses() {

    try {

        const response = await fetch('delivery_status.php?ajax=delivery_statuses', {

            method: 'GET',

            cache: 'no-store'

        });



        const data = await response.json();



        if (!data.success || !Array.isArray(data.deliveries)) {

            return;

        }



        data.deliveries.forEach((delivery) => {

            const row = document.querySelector(\`tr[data-delivery-id="${delivery.delivery_id}"]\`);

            if (!row) return;



            const badge = row\.querySelector('.delivery-status');

            if (!badge) return;



            const status = String(delivery.delivery_status || '').toLowerCase();

            const label = status.charAt(0).toUpperCase() + status.slice(1);



            badge.textContent = label;

            badge.className = 'status-badge delivery-status status-' + status;

        });

    } catch (error) {

        console.error('Failed to refresh delivery statuses:', error);

    }

}



document.addEventListener('DOMContentLoaded', function () {

    refreshDeliveryStatuses();

    setInterval(refreshDeliveryStatuses, 5000);

});

</script>

</body>

</html>
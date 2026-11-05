<?php

session_start();

date_default_timezone_set('Asia/Manila');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);



if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {

    header("Location: index.php?page=login");

    exit();

}



require_once dirname(__DIR__) . '/db.php';

if (!$conn) {

    die("Database connection failed: " . mysqli_connect_error());

}



$message = "";

if (isset($_SESSION['transaction_message'])) {

    $message = $_SESSION['transaction_message'];

    unset($_SESSION['transaction_message']);

}



/* ================= HELPERS ================= */

function get_total($conn, $sql) {

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



function syncClosedAuctionTransactions($conn) {

    $auctions_stmt = mysqli_prepare($conn, "

        SELECT

            a.auction_id,

            a.product_id,

            a.seller_id,

            a.winner_user_id,

            a.winning_bid_amount

        FROM auctions a

        WHERE a.status = 'closed'

          AND a.winner_user_id IS NOT NULL

          AND a.winning_bid_amount IS NOT NULL

    ");

    mysqli_stmt_execute($auctions_stmt);

    $auctions_result = mysqli_stmt_get_result($auctions_stmt);



    while ($auction = mysqli_fetch_assoc($auctions_result)) {

        $product_id = (int)$auction['product_id'];

        $seller_id = (int)$auction['seller_id'];

        $buyer_id = (int)$auction['winner_user_id'];

        $winning_amount = (float)$auction['winning_bid_amount'];



        if ($product_id <= 0 || $seller_id <= 0 || $buyer_id <= 0 || $winning_amount <= 0) {

            continue;

        }



        $check_stmt = mysqli_prepare($conn, "

            SELECT transaction_id

            FROM transactions

            WHERE product_id = ?

              AND seller_id = ?

              AND buyer_id = ?

              AND total_amount = ?

            LIMIT 1

        ");

        mysqli_stmt_bind_param($check_stmt, "iiid", $product_id, $seller_id, $buyer_id, $winning_amount);

        mysqli_stmt_execute($check_stmt);

        $check_result = mysqli_stmt_get_result($check_stmt);



        if (mysqli_num_rows($check_result) === 0) {

            $quantity = 1;

            $unit_price = $winning_amount;

            $total_amount = $winning_amount;

            $commission_amount = 0;

            $net_amount = $winning_amount;

            $status = 'pending';

            $transaction_status = 'pending';

            $payment_status = 'pending';



            $insert_stmt = mysqli_prepare($conn, "

                INSERT INTO transactions (

                    product_id,

                    seller_id,

                    buyer_id,

                    quantity,

                    unit_price,

                    total_amount,

                    status,

                    transaction_status,

                    payment_status,

                    order_date,

                    commission_amount,

                    net_amount

                )

                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)

            ");

            mysqli_stmt_bind_param(

                $insert_stmt,

                "iiiiddsssdd",

                $product_id,

                $seller_id,

                $buyer_id,

                $quantity,

                $unit_price,

                $total_amount,

                $status,

                $transaction_status,

                $payment_status,

                $commission_amount,

                $net_amount

            );

            mysqli_stmt_execute($insert_stmt);

        }

    }

}



/* ================= AUTO SYNC CLOSED AUCTIONS TO TRANSACTIONS ================= */

syncClosedAuctionTransactions($conn);



/* ================= CONFIRM TRANSACTION ================= */

if (isset($_POST['confirm_transaction'])) {

    $transaction_id = (int)$_POST['transaction_id'];



    mysqli_begin_transaction($conn);



    try {

        $update_stmt = mysqli_prepare(

            $conn,

            "UPDATE transactions

             SET transaction_status = 'confirmed',

                 status = 'confirmed',

                 confirmed_at = NOW()

             WHERE transaction_id = ?

               AND transaction_status = 'pending'"

        );

        mysqli_stmt_bind_param($update_stmt, "i", $transaction_id);

        mysqli_stmt_execute($update_stmt);



        if (mysqli_stmt_affected_rows($update_stmt) > 0) {

            $check_delivery_stmt = mysqli_prepare(

                $conn,

                "SELECT delivery_id

                 FROM deliveries

                 WHERE transaction_id = ?

                 LIMIT 1"

            );

            mysqli_stmt_bind_param($check_delivery_stmt, "i", $transaction_id);

            mysqli_stmt_execute($check_delivery_stmt);

            $delivery_result = mysqli_stmt_get_result($check_delivery_stmt);



            if (mysqli_num_rows($delivery_result) === 0) {

                $delivery_status = 'pending';

                $insert_delivery_stmt = mysqli_prepare(

                    $conn,

                    "INSERT INTO deliveries

                     (transaction_id, delivery_status, created_at)

                     VALUES (?, ?, NOW())"

                );

                mysqli_stmt_bind_param($insert_delivery_stmt, "is", $transaction_id, $delivery_status);

                mysqli_stmt_execute($insert_delivery_stmt);

            }



            mysqli_commit($conn);

            $_SESSION['transaction_message'] = "Transaction confirmed and delivery created.";

        } else {

            mysqli_commit($conn);

            $_SESSION['transaction_message'] = "Transaction already confirmed or not found.";

        }

    } catch (Throwable $e) {

        mysqli_rollback($conn);

        $_SESSION['transaction_message'] = "Failed to confirm transaction.";

    }



    $redirect_url = "transaction_management.php";

    if (!empty($_GET)) {

        $redirect_url .= "?" . http_build_query($_GET);

    }



    header("Location: " . $redirect_url);

    exit();

}



/* ================= COUNTS ================= */

$pending_transactions = get_total($conn, "SELECT COUNT(*) AS total FROM transactions WHERE transaction_status = 'pending'");

$confirmed_transactions = get_total($conn, "SELECT COUNT(*) AS total FROM transactions WHERE transaction_status = 'confirmed'");

$completed_transactions = get_total($conn, "SELECT COUNT(*) AS total FROM transactions WHERE transaction_status = 'completed'");

$total_sales = get_total($conn, "SELECT IFNULL(SUM(total_amount), 0) AS total FROM transactions WHERE transaction_status = 'completed'");



/* ================= PAGINATION ================= */

$rows_per_page = 5;



$pending_page = isset($_GET['pending_page']) ? max(1, (int)$_GET['pending_page']) : 1;

$completed_page = isset($_GET['completed_page']) ? max(1, (int)$_GET['completed_page']) : 1;

$history_page = isset($_GET['history_page']) ? max(1, (int)$_GET['history_page']) : 1;



$total_pending_rows = get_total($conn, "

    SELECT COUNT(*) AS total

    FROM transactions

    WHERE transaction_status = 'pending'

");



$total_completed_rows = get_total($conn, "

    SELECT COUNT(*) AS total

    FROM transactions

    WHERE transaction_status = 'completed'

");



$total_history_rows = get_total($conn, "

    SELECT COUNT(*) AS total

    FROM transactions

");



$total_pending_pages = max(1, (int)ceil($total_pending_rows / $rows_per_page));

$total_completed_pages = max(1, (int)ceil($total_completed_rows / $rows_per_page));

$total_history_pages = max(1, (int)ceil($total_history_rows / $rows_per_page));



if ($pending_page > $total_pending_pages) {

    $pending_page = $total_pending_pages;

}

if ($completed_page > $total_completed_pages) {

    $completed_page = $total_completed_pages;

}

if ($history_page > $total_history_pages) {

    $history_page = $total_history_pages;

}



$pending_offset = ($pending_page - 1) * $rows_per_page;

$completed_offset = ($completed_page - 1) * $rows_per_page;

$history_offset = ($history_page - 1) * $rows_per_page;



/* ================= PENDING LIST ================= */

$pending_list = mysqli_query($conn, "

    SELECT

        t.transaction_id,

        t.quantity,

        t.unit_price,

        t.total_amount,

        t.payment_status,

        t.transaction_status,

        t.order_date,

        p.product_name,

        f.full_name AS seller_name,

        b.full_name AS buyer_name

    FROM transactions t

    INNER JOIN products p ON t.product_id = p.product_id

    INNER JOIN farmers f ON t.seller_id = f.farmer_id

    LEFT JOIN users b ON t.buyer_id = b.user_id

    WHERE t.transaction_status = 'pending'

    ORDER BY t.transaction_id DESC

    LIMIT $pending_offset, $rows_per_page

");



/* ================= COMPLETED SALES ================= */

$completed_sales_list = mysqli_query($conn, "

    SELECT

        t.transaction_id,

        t.total_amount,

        t.commission_amount,

        t.net_amount,

        t.completed_at,

        p.product_name,

        f.full_name AS seller_name,

        b.full_name AS buyer_name

    FROM transactions t

    INNER JOIN products p ON t.product_id = p.product_id

    INNER JOIN farmers f ON t.seller_id = f.farmer_id

    LEFT JOIN users b ON t.buyer_id = b.user_id

    WHERE t.transaction_status = 'completed'

    ORDER BY t.transaction_id DESC

    LIMIT $completed_offset, $rows_per_page

");



/* ================= ORDER HISTORY ================= */

$order_history = mysqli_query($conn, "

    SELECT

        t.transaction_id,

        p.product_name,

        f.full_name AS seller_name,

        u.full_name AS buyer_name,

        t.total_amount,

        t.payment_status,

        t.transaction_status,

        t.order_date,

        t.confirmed_at,

        t.completed_at

    FROM transactions t

    INNER JOIN products p ON t.product_id = p.product_id

    INNER JOIN farmers f ON t.seller_id = f.farmer_id

    LEFT JOIN users u ON t.buyer_id = u.user_id

    ORDER BY t.transaction_id DESC

    LIMIT $history_offset, $rows_per_page

");

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Agri-Market Transactions</title>

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

            min-height:92vh;

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

        }



        .topbar{

            margin-bottom:20px;

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

            line-height:1.5;

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



        .panel-title{

            font-size:22px;

            color:#ffe5a6;

            font-family:Georgia, serif;

            margin-bottom:14px;

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



        .status-pending{

            background:rgba(200,160,70,0.22);

            color:#fff1b8;

        }



        .status-confirmed{

            background:rgba(102,153,51,0.22);

            color:#dfffba;

        }



        .status-completed{

            background:rgba(100,170,210,0.22);

            color:#d8f0ff;

        }



        .action-form{

            display:flex;

            gap:8px;

            flex-wrap:wrap;

        }



        .btn{

            border:none;

            border-radius:6px;

            cursor:pointer;

            background:linear-gradient(to bottom, #8faa2e, #657f1a);

            color:#fff2c3;

            font-size:14px;

            font-weight:bold;

            padding:10px 16px;

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

            }



            .sidebar{

                width:100%;

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
                <div class="page-eyebrow">ORDER PROCESSING</div>
                <div class="page-title">Transaction Management</div>
                <p class="page-subtitle">Review incoming orders, confirm pending transactions, and monitor completed marketplace sales.</p>
            </div>

        </div>



        <?php if ($message !== "") { ?>

            <div class="message-box"><?php echo htmlspecialchars($message); ?></div>

        <?php } ?>



        <div class="stats-grid">

            <div class="stat-card">

                <div class="stat-icon"><i class="fas fa-clock"></i></div><div class="stat-label">Pending Transactions</div>

                <div class="stat-value"><?php echo $pending_transactions; ?></div>

            </div>

            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-circle-check"></i></div>
                <div class="stat-label">Confirmed Orders</div>
                <div class="stat-value"><?php echo $confirmed_transactions; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-check-double"></i></div>
                <div class="stat-label">Completed Orders</div>
                <div class="stat-value"><?php echo $completed_transactions; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-coins"></i></div>
                <div class="stat-label">Completed Sales</div>
                <div class="stat-value">₱<?php echo number_format((float)$total_sales, 2); ?></div>
            </div>
        </div>



        <div class="panel">

            <div class="panel-title">Pending Transactions</div>
            <p class="panel-note">Check each order and confirm it to create its delivery record.</p>

            <div class="table-wrap">

                <table>

                    <thead>

                        <tr>

                            <th>ID</th>

                            <th>Product</th>

                            <th>Seller</th>

                            <th>Buyer</th>

                            <th>Quantity</th>

                            <th>Unit Price</th>

                            <th>Total</th>

                            <th>Payment</th>

                            <th>Status</th>

                            <th>Order Date</th>

                            <th>Action</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php if ($pending_list && mysqli_num_rows($pending_list) > 0) { ?>

                            <?php while ($pending = mysqli_fetch_assoc($pending_list)) { ?>

                                <tr>

                                    <td><?php echo $pending['transaction_id']; ?></td>

                                    <td><?php echo htmlspecialchars($pending['product_name']); ?></td>

                                    <td><?php echo htmlspecialchars($pending['seller_name']); ?></td>

                                    <td><?php echo htmlspecialchars($pending['buyer_name'] ?? '—'); ?></td>

                                    <td><?php echo number_format((float)$pending['quantity'], 2); ?></td>

                                    <td>₱<?php echo number_format((float)$pending['unit_price'], 2); ?></td>

                                    <td>₱<?php echo number_format((float)$pending['total_amount'], 2); ?></td>

                                    <td><?php echo htmlspecialchars(ucfirst($pending['payment_status'])); ?></td>

                                    <td>

                                        <span class="status-badge status-<?php echo htmlspecialchars($pending['transaction_status']); ?>">

                                            <?php echo htmlspecialchars(ucfirst($pending['transaction_status'])); ?>

                                        </span>

                                    </td>

                                    <td><?php echo htmlspecialchars($pending['order_date']); ?></td>

                                    <td>

                                        <?php if ($pending['transaction_status'] === 'pending') { ?>

                                            <form method="POST" class="action-form">

                                                <input type="hidden" name="transaction_id" value="<?php echo $pending['transaction_id']; ?>">

                                                <button type="submit" name="confirm_transaction" class="btn">Confirm</button>

                                            </form>

                                        <?php } else { ?>

                                            Confirmed

                                        <?php } ?>

                                    </td>

                                </tr>

                            <?php } ?>

                        <?php } else { ?>

                            <tr>

                                <td colspan="11">No pending transactions found.</td>

                            </tr>

                        <?php } ?>

                    </tbody>

                </table>

            </div>



            <?php echo renderPagination($pending_page, $total_pending_pages, 'pending_page'); ?>

        </div>



        <div class="panel">

            <div class="panel-title">Order History</div>
            <p class="panel-note">A chronological view of marketplace orders, payment states, and confirmation details.</p>

            <div class="table-wrap">

                <table>

                    <thead>

                        <tr>

                            <th>ID</th>

                            <th>Product</th>

                            <th>Seller</th>

                            <th>Buyer</th>

                            <th>Total</th>

                            <th>Payment</th>

                            <th>Status</th>

                            <th>Ordered</th>

                            <th>Confirmed</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php if ($order_history && mysqli_num_rows($order_history) > 0) { ?>

                            <?php while ($history = mysqli_fetch_assoc($order_history)) { ?>

                                <tr>

                                    <td><?php echo $history['transaction_id']; ?></td>

                                    <td><?php echo htmlspecialchars($history['product_name']); ?></td>

                                    <td><?php echo htmlspecialchars($history['seller_name']); ?></td>

                                    <td><?php echo htmlspecialchars($history['buyer_name'] ?? '—'); ?></td>

                                    <td>₱<?php echo number_format((float)$history['total_amount'], 2); ?></td>

                                    <td><?php echo htmlspecialchars(ucfirst($history['payment_status'])); ?></td>

                                    <td>

                                        <span class="status-badge status-<?php echo htmlspecialchars($history['transaction_status']); ?>">

                                            <?php echo htmlspecialchars(ucfirst($history['transaction_status'])); ?>

                                        </span>

                                    </td>

                                    <td><?php echo htmlspecialchars($history['order_date']); ?></td>

                                    <td><?php echo $history['confirmed_at'] ? htmlspecialchars($history['confirmed_at']) : '—'; ?></td>

                                </tr>

                            <?php } ?>

                        <?php } else { ?>

                            <tr>

                                <td colspan="10">No transaction history found.</td>

                            </tr>

                        <?php } ?>

                    </tbody>

                </table>

            </div>



            <?php echo renderPagination($history_page, $total_history_pages, 'history_page'); ?>

        </div>

    </div>

</div>



</body>

</html>
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

$current_time = date('Y-m-d H:i:s');



function normalizeDateTimeInput($value) {

    $value = trim((string)$value);

    if ($value === "") {

        return "";

    }



    $timestamp = strtotime($value);

    if ($timestamp === false) {

        return "";

    }



    return date('Y-m-d H:i:s', $timestamp);

}



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



function closeAuctionById($conn, $auction_id) {

    if ($auction_id <= 0) {

        return false;

    }



    mysqli_begin_transaction($conn);



    try {

        $auction_stmt = mysqli_prepare($conn, "

            SELECT 

                a.auction_id,

                a.product_id,

                a.commission_id,

                a.status,

                a.starting_bid,

                p.farmer_id AS seller_id,

                cr.rate_percent

            FROM auctions a

            INNER JOIN products p ON a.product_id = p.product_id

            LEFT JOIN commission_rates cr ON a.commission_id = cr.commission_id

            WHERE a.auction_id = ?

            LIMIT 1

            FOR UPDATE

        ");

        mysqli_stmt_bind_param($auction_stmt, "i", $auction_id);

        mysqli_stmt_execute($auction_stmt);

        $auction_result = mysqli_stmt_get_result($auction_stmt);



        if (!$auction_result || mysqli_num_rows($auction_result) === 0) {

            mysqli_rollback($conn);

            return false;

        }



        $auction = mysqli_fetch_assoc($auction_result);



        if ($auction['status'] === 'closed') {

            mysqli_commit($conn);

            return true;

        }



        $bid_stmt = mysqli_prepare($conn, "

            SELECT bid_id, user_id, bid_amount

            FROM bids

            WHERE auction_id = ?

            ORDER BY bid_amount DESC, bid_time ASC

            LIMIT 1

        ");

        mysqli_stmt_bind_param($bid_stmt, "i", $auction_id);

        mysqli_stmt_execute($bid_stmt);

        $bid_result = mysqli_stmt_get_result($bid_stmt);



        if ($bid_result && mysqli_num_rows($bid_result) === 1) {

            $top_bid = mysqli_fetch_assoc($bid_result);



            $winner_user_id = (int)$top_bid['user_id'];

            $winning_bid_amount = (float)$top_bid['bid_amount'];

            $winning_bid_id = (int)$top_bid['bid_id'];



            $update_auction_stmt = mysqli_prepare($conn, "

                UPDATE auctions

                SET status = 'closed',

                    winner_user_id = ?,

                    winning_bid_amount = ?,

                    current_highest_bid = ?

                WHERE auction_id = ?

            ");

            mysqli_stmt_bind_param(

                $update_auction_stmt,

                "iddi",

                $winner_user_id,

                $winning_bid_amount,

                $winning_bid_amount,

                $auction_id

            );

            mysqli_stmt_execute($update_auction_stmt);



            $outbid_stmt = mysqli_prepare($conn, "

                UPDATE bids

                SET bid_status = 'outbid'

                WHERE auction_id = ?

            ");

            mysqli_stmt_bind_param($outbid_stmt, "i", $auction_id);

            mysqli_stmt_execute($outbid_stmt);



            $winning_stmt = mysqli_prepare($conn, "

                UPDATE bids

                SET bid_status = 'winning'

                WHERE bid_id = ?

            ");

            mysqli_stmt_bind_param($winning_stmt, "i", $winning_bid_id);

            mysqli_stmt_execute($winning_stmt);



            $check_tx_stmt = mysqli_prepare($conn, "

                SELECT transaction_id

                FROM transactions

                WHERE product_id = ? AND buyer_id = ?

                LIMIT 1

            ");

            mysqli_stmt_bind_param(

                $check_tx_stmt,

                "ii",

                $auction['product_id'],

                $winner_user_id

            );

            mysqli_stmt_execute($check_tx_stmt);

            $check_tx_result = mysqli_stmt_get_result($check_tx_stmt);



            if (mysqli_num_rows($check_tx_result) === 0) {

                $quantity = 1;

                $unit_price = $winning_bid_amount;

                $total_amount = $winning_bid_amount;



                $rate_percent = (float)($auction['rate_percent'] ?? 0);

                $commission_amount = ($winning_bid_amount * $rate_percent) / 100;

                $net_amount = $winning_bid_amount - $commission_amount;



                $insert_tx_stmt = mysqli_prepare($conn, "

                    INSERT INTO transactions

                    (

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

                    VALUES (?, ?, ?, ?, ?, ?, 'pending', 'pending', 'pending', NOW(), ?, ?)

                ");

                mysqli_stmt_bind_param(

                    $insert_tx_stmt,

                    "iiiidddd",

                    $auction['product_id'],

                    $auction['seller_id'],

                    $winner_user_id,

                    $quantity,

                    $unit_price,

                    $total_amount,

                    $commission_amount,

                    $net_amount

                );

                mysqli_stmt_execute($insert_tx_stmt);

            }

        } else {

            $close_stmt = mysqli_prepare($conn, "

                UPDATE auctions

                SET status = 'closed',

                    winner_user_id = NULL,

                    winning_bid_amount = NULL,

                    current_highest_bid = starting_bid

                WHERE auction_id = ?

            ");

            mysqli_stmt_bind_param($close_stmt, "i", $auction_id);

            mysqli_stmt_execute($close_stmt);

        }



        mysqli_commit($conn);

        return true;

    } catch (Throwable $e) {

        mysqli_rollback($conn);

        return false;

    }

}



/* ================= AUTO OPEN DRAFT AUCTIONS ================= */

mysqli_query($conn, "

    UPDATE auctions

    SET status = 'open'

    WHERE status = 'draft'

      AND start_time <= '$current_time'

      AND end_time > '$current_time'

");



/* ================= AUTO CLOSE EXPIRED AUCTIONS ================= */

$expired_auctions = mysqli_query($conn, "

    SELECT auction_id

    FROM auctions

    WHERE end_time <= '$current_time'

      AND status IN ('open', 'draft')

");



if ($expired_auctions) {

    while ($row = mysqli_fetch_assoc($expired_auctions)) {

        closeAuctionById($conn, (int)$row['auction_id']);

    }

}



/* ================= AJAX AUTO CLOSE ================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_auto_close'])) {

    header('Content-Type: application/json');



    $auction_id = isset($_POST['auction_id']) ? (int)$_POST['auction_id'] : 0;

    $success = closeAuctionById($conn, $auction_id);



    echo json_encode([

        'success' => $success

    ]);

    exit();

}



/* ================= CREATE AUCTION ================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_auction'])) {

    try {

        $product_id = (int)($_POST['product_id'] ?? 0);

        $commission_id = (int)($_POST['commission_id'] ?? 0);

        $auction_title = trim($_POST['auction_title'] ?? '');

        $auction_description = trim($_POST['auction_description'] ?? '');

        $starting_bid = (float)($_POST['starting_bid'] ?? 0);

        $minimum_increment = (float)($_POST['minimum_increment'] ?? 0);

        $start_time = normalizeDateTimeInput($_POST['start_time'] ?? '');

        $end_time = normalizeDateTimeInput($_POST['end_time'] ?? '');



        if (

            $product_id <= 0 ||

            $commission_id <= 0 ||

            $auction_title === "" ||

            $start_time === "" ||

            $end_time === "" ||

            $starting_bid < 0 ||

            $minimum_increment < 1

        ) {

            $message = "Please complete all auction fields correctly.";

        } elseif (strtotime($end_time) <= strtotime($start_time)) {

            $message = "End time must be later than start time.";

        } elseif (strtotime($end_time) <= time()) {

            $message = "End time must be in the future.";

        } else {

            $commission_stmt = mysqli_prepare($conn, "

                SELECT commission_id, rate_name, rate_percent

                FROM commission_rates

                WHERE commission_id = ? AND is_active = 1

                LIMIT 1

            ");

            mysqli_stmt_bind_param($commission_stmt, "i", $commission_id);

            mysqli_stmt_execute($commission_stmt);

            $commission_result = mysqli_stmt_get_result($commission_stmt);



            if (!$commission_result || mysqli_num_rows($commission_result) !== 1) {

                $message = "Selected commission rate is invalid or inactive.";

            } else {

                $product_stmt = mysqli_prepare($conn, "

                    SELECT product_id, farmer_id, product_name

                    FROM products

                    WHERE product_id = ?

                    LIMIT 1

                ");

                mysqli_stmt_bind_param($product_stmt, "i", $product_id);

                mysqli_stmt_execute($product_stmt);

                $product_result = mysqli_stmt_get_result($product_stmt);



                if ($product_result && mysqli_num_rows($product_result) === 1) {

                    $product = mysqli_fetch_assoc($product_result);

                    $seller_id = (int)$product['farmer_id'];

                    $current_highest_bid = $starting_bid;

                    $status = (strtotime($start_time) <= time()) ? 'open' : 'draft';



                    $insert_stmt = mysqli_prepare($conn, "

                        INSERT INTO auctions

                        (

                            product_id,

                            commission_id,

                            seller_id,

                            auction_title,

                            auction_description,

                            starting_bid,

                            minimum_increment,

                            current_highest_bid,

                            start_time,

                            end_time,

                            status

                        )

                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)

                    ");

                    mysqli_stmt_bind_param(

                        $insert_stmt,

                        "iiissdddsss",

                        $product_id,

                        $commission_id,

                        $seller_id,

                        $auction_title,

                        $auction_description,

                        $starting_bid,

                        $minimum_increment,

                        $current_highest_bid,

                        $start_time,

                        $end_time,

                        $status

                    );

                    mysqli_stmt_execute($insert_stmt);



                    header("Location: bidding_auction.php");

                    exit();

                } else {

                    $message = "Selected product was not found.";

                }

            }

        }

    } catch (Throwable $e) {

        $message = "Failed to create auction.";

    }

}



/* ================= FETCH PRODUCTS ================= */

$products_data = [];

$products_query = mysqli_query($conn, "

    SELECT

        p.product_id,

        p.product_name,

        f.full_name AS farmer_name,

        c.category_name,

        s.subcategory_name

    FROM products p

    INNER JOIN farmers f ON p.farmer_id = f.farmer_id

    INNER JOIN product_categories c ON p.category_id = c.category_id

    INNER JOIN product_subcategories s ON p.subcategory_id = s.subcategory_id

    ORDER BY p.product_name ASC

");

if ($products_query) {

    while ($row = mysqli_fetch_assoc($products_query)) {

        $products_data[] = $row;

    }

}



/* ================= FETCH ACTIVE COMMISSION RATES ================= */

$commission_rates_data = [];

$commission_query = mysqli_query($conn, "

    SELECT commission_id, rate_name, rate_percent

    FROM commission_rates

    WHERE is_active = 1

    ORDER BY rate_name ASC

");



if ($commission_query) {

    while ($row = mysqli_fetch_assoc($commission_query)) {

        $commission_rates_data[] = $row;

    }

}



/* ================= PAGINATION ================= */

$rows_per_page = 5;



$active_auctions_page = isset($_GET['active_auctions_page']) ? max(1, (int)$_GET['active_auctions_page']) : 1;

$winning_bids_page = isset($_GET['winning_bids_page']) ? max(1, (int)$_GET['winning_bids_page']) : 1;



$total_active_auctions_rows = get_total($conn, "

    SELECT COUNT(*) AS total

    FROM auctions

    WHERE status = 'open'

      AND start_time <= '$current_time'

      AND end_time > '$current_time'

");



$total_winning_bids_rows = get_total($conn, "

    SELECT COUNT(*) AS total

    FROM bids b

    INNER JOIN auctions a ON b.auction_id = a.auction_id

    WHERE b.bid_status = 'winning'

      AND a.winner_user_id IS NOT NULL

");



$total_active_auctions_pages = max(1, (int)ceil($total_active_auctions_rows / $rows_per_page));

$total_winning_bids_pages = max(1, (int)ceil($total_winning_bids_rows / $rows_per_page));



if ($active_auctions_page > $total_active_auctions_pages) {

    $active_auctions_page = $total_active_auctions_pages;

}

if ($winning_bids_page > $total_winning_bids_pages) {

    $winning_bids_page = $total_winning_bids_pages;

}



$active_auctions_offset = ($active_auctions_page - 1) * $rows_per_page;

$winning_bids_offset = ($winning_bids_page - 1) * $rows_per_page;



/* ================= ACTIVE AUCTIONS ================= */

$active_auctions = mysqli_query($conn, "

    SELECT

        a.auction_id,

        a.auction_title,

        a.starting_bid,

        a.current_highest_bid,

        a.start_time,

        a.end_time,

        a.status,

        p.product_name,

        f.full_name AS seller_name,

        cr.rate_name,

        cr.rate_percent

    FROM auctions a

    INNER JOIN products p ON a.product_id = p.product_id

    INNER JOIN farmers f ON a.seller_id = f.farmer_id

    LEFT JOIN commission_rates cr ON a.commission_id = cr.commission_id

    WHERE a.status = 'open'

      AND a.start_time <= '$current_time'

      AND a.end_time > '$current_time'

    ORDER BY a.end_time ASC

    LIMIT $active_auctions_offset, $rows_per_page

");



/* ================= WINNING BIDS ================= */

$winning_bids = mysqli_query($conn, "

    SELECT

        a.auction_title,

        p.product_name,

        u.full_name AS winner_name,

        b.bid_amount,

        b.bid_time,

        a.status

    FROM bids b

    INNER JOIN auctions a ON b.auction_id = a.auction_id

    INNER JOIN products p ON a.product_id = p.product_id

    INNER JOIN users u ON b.user_id = u.user_id

    WHERE b.bid_status = 'winning'

      AND a.winner_user_id IS NOT NULL

    ORDER BY b.bid_time DESC

    LIMIT $winning_bids_offset, $rows_per_page

");



?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Agri-Market Bidding / Auction</title>

    <title>Agri-Market Admin Dashboard</title>

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



        .status-open,

        .status-active,

        .status-winning{

            background:rgba(102,153,51,0.22);

            color:#dfffba;

        }



        .status-closed,

        .status-outbid{

            background:rgba(180,90,90,0.22);

            color:#ffd4d4;

        }



        .status-draft{

            background:rgba(219,170,62,0.18);

            color:#ffe7a8;

        }



        .countdown{

            font-weight:bold;

            white-space:nowrap;

        }



        .ended-text{

            color:#ff6b6b;

        }



        .action-form{

            display:flex;

            gap:8px;

            flex-wrap:wrap;

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



        .commission-preview-box{

            margin-top: 6px;

            padding: 15px 18px;

            border-radius: 10px;

            background: rgba(255,255,255,0.06);

            border: 1px solid rgba(169,179,73,0.20);

            color: #f5e6b3;

        }



        .commission-preview-box h4{

            margin-bottom: 10px;

            color: #ffd264;

            font-size: 16px;

        }



        .commission-preview-box p{

            margin: 6px 0;

            font-size: 14px;

        }



        @media (max-width: 1024px){

            .dashboard-shell{

                flex-direction:column;

            }



            .sidebar{

                width:100%;

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
                <div class="page-eyebrow">AUCTION CONTROL CENTER</div>
                <div class="page-title">Bidding / Auction</div>
                <p class="page-subtitle">Manage active agricultural auctions, review winning bids, and monitor auction closing times.</p>
            </div>

        </div>



        <?php if ($message !== "") { ?>

            <div class="message-box"><?php echo htmlspecialchars($message); ?></div>

        <?php } ?>



        <div class="stats-grid auction-stats">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-gavel"></i></div>
                <div class="stat-label">Active Auctions</div>
                <div class="stat-value"><?php echo (int)$total_active_auctions_rows; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-trophy"></i></div>
                <div class="stat-label">Winning Bids</div>
                <div class="stat-value"><?php echo (int)$total_winning_bids_rows; ?></div>
            </div>
        </div>

        <div class="panel">

            <div class="panel-header">

                <div class="panel-heading-group"><div class="panel-title">Active Auctions</div><p class="panel-note">Track current listings, highest bids, auction schedules, countdowns, and available actions.</p></div>

                <button type="button" class="toggle-btn" onclick="toggleAuctionForm()">Add Auction</button>

            </div>



            <div id="auctionFormBox" class="form-box">

            <form method="POST">

                <div class="form-grid">

                    <div class="form-group">

                        <label>Product</label>

                        <select name="product_id" required>

                            <option value="">Select Product</option>

                            <?php foreach ($products_data as $product) { ?>

                                <option value="<?php echo (int)$product['product_id']; ?>">

                                    <?php

                                    echo htmlspecialchars(

                                        $product['product_name'] . ' - ' .

                                        $product['category_name'] . ' / ' .

                                        $product['subcategory_name'] . ' - ' .

                                        $product['farmer_name']

                                    );

                                    ?>

                                </option>

                            <?php } ?>

                        </select>

                    </div>



                    <div class="form-group">

                        <label>Commission Rate</label>

                        <select name="commission_id" id="commission_id" required>

                            <option value="">Select Commission Rate</option>

                            <?php foreach ($commission_rates_data as $rate) { ?>

                                <option

                                    value="<?php echo (int)$rate['commission_id']; ?>"

                                    data-rate="<?php echo (float)$rate['rate_percent']; ?>"

                                >

                                    <?php

                                    echo htmlspecialchars(

                                        $rate['rate_name'] . ' (' . number_format((float)$rate['rate_percent'], 2) . '%)'

                                    );

                                    ?>

                                </option>

                            <?php } ?>

                        </select>

                    </div>



                    <div class="form-group">

                        <label>Auction Title</label>

                        <input type="text" name="auction_title" required>

                    </div>



                    <div class="form-group">

                        <label>Starting Bid</label>

                        <input type="number" step="0.01" min="0" name="starting_bid" id="starting_bid" required>

                    </div>



                    <div class="form-group">

                        <label>Minimum Increment</label>

                        <input type="number" step="0.01" min="1" name="minimum_increment" value="1.00" required>

                    </div>



                    <div class="form-group">

                        <label>Start Time</label>

                        <input type="datetime-local" name="start_time" required>

                    </div>



                    <div class="form-group">

                        <label>End Time</label>

                        <input type="datetime-local" name="end_time" required>

                    </div>



                    <div class="form-group full">

                        <label>Description</label>

                        <textarea name="auction_description"></textarea>

                    </div>



                    <div class="form-group full">

                        <div class="commission-preview-box" id="commissionPreviewBox">

                            <h4>Live Commission Preview</h4>

                            <p><strong>Starting Bid:</strong> ₱<span id="previewStartingBid">0.00</span></p>

                            <p><strong>Commission Rate:</strong> <span id="previewRate">0.00%</span></p>

                            <p><strong>Estimated Commission:</strong> ₱<span id="previewCommission">0.00</span></p>

                            <p><strong>Estimated Net Amount:</strong> ₱<span id="previewNet">0.00</span></p>

                        </div>

                    </div>



                    <div class="form-group full">

                        <button type="submit" name="create_auction" class="btn">Save Auction</button>

                    </div>

                </div>

            </form>

        </div>



            <div class="table-wrap">

                <table>

                    <thead>

                        <tr>

                            <th>Auction</th>

                            <th>Product</th>

                            <th>Seller</th>

                            <th>Commission</th>

                            <th>Starting Bid</th>

                            <th>Highest Bid</th>

                            <th>Start</th>

                            <th>End</th>

                            <th>Status</th>

                            <th>Time Left</th>

                            <th>Action</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php if ($active_auctions && mysqli_num_rows($active_auctions) > 0) { ?>

                            <?php while ($auction = mysqli_fetch_assoc($active_auctions)) { ?>

                                <tr data-auction-id="<?php echo (int)$auction['auction_id']; ?>">

                                    <td><?php echo htmlspecialchars($auction['auction_title']); ?></td>

                                    <td><?php echo htmlspecialchars($auction['product_name']); ?></td>

                                    <td><?php echo htmlspecialchars($auction['seller_name']); ?></td>

                                    <td>

                                        <?php echo htmlspecialchars($auction['rate_name'] ?? 'N/A'); ?>

                                        <?php if (isset($auction['rate_percent']) && $auction['rate_percent'] !== null) { ?>

                                            (<?php echo number_format((float)$auction['rate_percent'], 2); ?>%)

                                        <?php } ?>

                                    </td>

                                    <td>₱<?php echo number_format((float)$auction['starting_bid'], 2); ?></td>

                                    <td>₱<?php echo number_format((float)$auction['current_highest_bid'], 2); ?></td>

                                    <td><?php echo htmlspecialchars($auction['start_time']); ?></td>

                                    <td><?php echo htmlspecialchars($auction['end_time']); ?></td>

                                    <td>

                                        <span class="status-badge status-<?php echo htmlspecialchars($auction['status']); ?> auction-status">

                                            <?php echo htmlspecialchars(ucfirst($auction['status'])); ?>

                                        </span>

                                    </td>

                                    <td

                                        class="countdown"

                                        data-auction-id="<?php echo (int)$auction['auction_id']; ?>"

                                        data-end="<?php echo htmlspecialchars(date('c', strtotime($auction['end_time']))); ?>"

                                    ></td>

                                    <td class="action-cell">

                                        <form method="POST" class="action-form">

                                            <input type="hidden" name="auction_id" value="<?php echo (int)$auction['auction_id']; ?>">

                                            <button type="submit" name="close_auction" class="btn">Close Auction</button>

                                        </form>

                                    </td>

                                </tr>

                            <?php } ?>

                        <?php } else { ?>

                            <tr>

                                <td colspan="11">No active auctions found.</td>

                            </tr>

                        <?php } ?>

                    </tbody>

                </table>

            </div>



            <?php echo renderPagination($active_auctions_page, $total_active_auctions_pages, 'active_auctions_page'); ?>

        </div>



        <div class="panel">

            <div class="panel-title">Winning Bids</div>
            <p class="panel-note">Review the winning bidder, final bid amount, and associated auction.</p>



            <div class="table-wrap">

                <table>

                    <thead>

                        <tr>

                            <th>Auction</th>

                            <th>Product</th>

                            <th>Winner</th>

                            <th>Winning Amount</th>

                            <th>Bid Time</th>

                            <th>Auction Status</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php if ($winning_bids && mysqli_num_rows($winning_bids) > 0) { ?>

                            <?php while ($winner = mysqli_fetch_assoc($winning_bids)) { ?>

                                <tr>

                                    <td><?php echo htmlspecialchars($winner['auction_title']); ?></td>

                                    <td><?php echo htmlspecialchars($winner['product_name']); ?></td>

                                    <td><?php echo htmlspecialchars($winner['winner_name']); ?></td>

                                    <td>₱<?php echo number_format((float)$winner['bid_amount'], 2); ?></td>

                                    <td><?php echo htmlspecialchars($winner['bid_time']); ?></td>

                                    <td>

                                        <span class="status-badge status-<?php echo htmlspecialchars($winner['status']); ?>">

                                            <?php echo htmlspecialchars(ucfirst($winner['status'])); ?>

                                        </span>

                                    </td>

                                </tr>

                            <?php } ?>

                        <?php } else { ?>

                            <tr>

                                <td colspan="6">No winning bids found.</td>

                            </tr>

                        <?php } ?>

                    </tbody>

                </table>

            </div>



            <?php echo renderPagination($winning_bids_page, $total_winning_bids_pages, 'winning_bids_page'); ?>

        </div>

    </div>

</div>



<script>

function toggleAuctionForm() {

    const formBox = document.getElementById('auctionFormBox');

    formBox.classList.toggle('show');

}



async function autoCloseAuction(auctionId) {

    const formData = *new* FormData();

    formData.append('ajax_auto_close', '1');

    formData.append('auction_id', auctionId);



    const response = await fetch(window\.location.href, {

        method: 'POST',

        body: formData

    });



    return await response.json();

}



function startCountdown() {

    const countdownElements = document.querySelectorAll('.countdown');



    countdownElements.forEach((el) => {

        const row = el.closest('tr');

        const auctionId = el.getAttribute('data-auction-id');

        const endTime = *new* Date(el.getAttribute('data-end')).getTime();

        let alreadyClosing = false;



        function updateTimer() {

            const now = *new* Date().getTime();

            const distance = endTime - now;



            const statusBadge = row\.querySelector('.auction-status');

            const actionCell = row\.querySelector('.action-cell');

            const currentStatus = statusBadge ? statusBadge.textContent.trim().toLowerCase() : '';



            if (distance <= 0) {

                el.innerHTML = "⛔ Ended";

                el.classList.add('ended-text');



                if (currentStatus === 'closed') {

                    return;

                }



                if (!alreadyClosing) {

                    alreadyClosing = true;



                    autoCloseAuction(auctionId)

                        .then((data) => {

                            if (data.success) {

                                if (statusBadge) {

                                    statusBadge.className = 'status-badge status-closed auction-status';

                                    statusBadge.textContent = 'Closed';

                                }



                                if (actionCell) {

                                    actionCell.innerHTML = 'Closed';

                                }



                                setTimeout(() => {

                                    window\.location.reload();

                                }, 700);

                            }

                        })

                        .catch((error) => {

                            console.error('Auto close failed:', error);

                            alreadyClosing = false;

                        });

                }



                return;

            }



            const days = Math.floor(distance / (1000 * 60 * 60 * 24));

            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));

            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));

            const seconds = Math.floor((distance % (1000 * 60)) / 1000);



            el.innerHTML =

                (days > 0 ? days + "d " : "") +

                hours + "h " +

                minutes + "m " +

                seconds + "s";



            el.classList.remove('ended-text');



            if (distance < 60000) {

                el.style.color = "#ff3b3b";

            } else if (distance < 300000) {

                el.style.color = "#ffaa00";

            } else {

                el.style.color = "";

            }

        }



        updateTimer();

        setInterval(updateTimer, 1000);

    });

}



function initCommissionPreview() {

    const startingBidInput = document.getElementById("starting_bid");

    const commissionSelect = document.getElementById("commission_id");

    const previewStartingBid = document.getElementById("previewStartingBid");

    const previewRate = document.getElementById("previewRate");

    const previewCommission = document.getElementById("previewCommission");

    const previewNet = document.getElementById("previewNet");



    if (!startingBidInput || !commissionSelect) {

        return;

    }



    function formatMoney(value) {

        return parseFloat(value || 0).toFixed(2);

    }



    function updateCommissionPreview() {

        const startingBid = parseFloat(startingBidInput.value) || 0;

        const selectedOption = commissionSelect.options[commissionSelect.selectedIndex];

        const ratePercent = selectedOption ? parseFloat(selectedOption.getAttribute("data-rate")) || 0 : 0;



        const commissionAmount = (startingBid * ratePercent) / 100;

        const netAmount = startingBid - commissionAmount;



        previewStartingBid.textContent = formatMoney(startingBid);

        previewRate.textContent = formatMoney(ratePercent) + "%";

        previewCommission.textContent = formatMoney(commissionAmount);

        previewNet.textContent = formatMoney(netAmount);

    }



    startingBidInput.addEventListener("input", updateCommissionPreview);

    commissionSelect.addEventListener("change", updateCommissionPreview);

    updateCommissionPreview();

}



document.addEventListener('DOMContentLoaded', function () {

    startCountdown();

    initCommissionPreview();

});

</script>



</body>

</html>
<?php
session_start();
date_default_timezone_set('Asia/Manila');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php?page=login");
    exit();
}

$conn = mysqli_connect("localhost", "root", "", "agri_markettt", "3306");
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

        .menu-link:hover,
        .menu-link.active{
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

        .page-link:hover{
            background:linear-gradient(to bottom, #8faa2e, #657f1a);
            color:#fff8d4;
        }

        .page-link.active{
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
            <div class="page-title">Transaction Management</div>
        </div>

        <?php if ($message !== "") { ?>
            <div class="message-box"><?php echo htmlspecialchars($message); ?></div>
        <?php } ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Pending Transactions</div>
                <div class="stat-value"><?php echo $pending_transactions; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Confirmed Orders</div>
                <div class="stat-value"><?php echo $confirmed_transactions; ?></div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-title">Pending Transactions / Confirm Orders</div>
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
<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php?page=login");
    exit();
}

require_once dirname(__DIR__) . '/db.php';
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

$user_id = $_SESSION['user_id'];

/* ================= BID HISTORY ================= */
$bid_history = mysqli_query($conn, "
    SELECT 
        a.auction_title,
        b.bid_amount,
        b.bid_time,
        b.bid_status,
        a.status AS auction_status
    FROM bids b
    INNER JOIN auctions a ON a.auction_id = b.auction_id
    WHERE b.user_id = '$user_id'
    ORDER BY b.bid_time DESC
");

/* ================= WIN / LOSS RESULTS ================= */
$results = mysqli_query($conn, "
    SELECT 
        a.auction_title,
        a.current_highest_bid,
        b.bid_amount,
        a.status,
        a.winner_user_id
    FROM bids b
    INNER JOIN auctions a ON a.auction_id = b.auction_id
    WHERE b.user_id = '$user_id'
    GROUP BY a.auction_id
    ORDER BY a.end_time DESC
");

$payment_records = mysqli_query(
    $conn,
    "SELECT
        t.transaction_id,
        p.product_name,
        t.total_amount,
        d.delivery_status,
        t.order_date
     FROM transactions t
     INNER JOIN products p ON t.product_id = p.product_id
     LEFT JOIN deliveries d ON t.transaction_id = d.transaction_id
     ORDER BY t.transaction_id DESC"
);
?>

<!DOCTYPE html>
<html>
<head>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<title>Transactions</title>
<style>
    /* ===== SAME BIDDER DASHBOARD STYLE ===== */
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
        color:#fff0b8;
    }

    .dashboard-shell{
        width:100%;
        max-width:1450px;
        margin:0 auto;
        display:flex;
        gap:18px;
    }

    /* SIDEBAR */
    .sidebar{
        width:285px;
        background:rgba(7,16,3,0.96);
        border:1px solid rgba(169,179,73,0.55);
        border-radius:18px;
        padding:18px 14px;
    }

    .sidebar-logo{
        width:100%;
        max-width:220px;
        display:block;
        margin:auto;
    }

    .sidebar-title{
        text-align:center;
        font-size:24px;
        color:#ffe5a6;
        margin-bottom:18px;
        font-family:Georgia;
    }

    .menu-link{
        display:block;
        padding:12px;
        margin-bottom:10px;
        text-decoration:none;
        color:#fff0b8;
        border-radius:10px;
        background:rgba(255,255,255,0.04);
    }

    .menu-link:hover,
    .menu-link.active{
        background:linear-gradient(to bottom, #8faa2e, #657f1a);
    }

    /* CONTENT */
    .content{
        flex:1;
        background:rgba(7,16,3,0.95);
        border-radius:18px;
        padding:20px;
        border:1px solid rgba(169,179,73,0.55);
    }

    .page-title{
        font-size:34px;
        font-family:Georgia;
        color:#ffe5a6;
        margin-bottom:20px;
    }

    /* PANEL */
    .panel{
        background:rgba(2,10,2,0.92);
        border:1px solid rgba(95,120,38,0.25);
        border-radius:14px;
        padding:16px;
        margin-bottom:20px;
    }

    .panel-title{
        font-size:22px;
        font-family:Georgia;
        color:#ffe5a6;
        margin-bottom:12px;
    }

    /* TABLE */
    table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed; /* 🔥 important for equal alignment */
    }

    th, td {
        padding: 12px;
        border-bottom: 1px solid rgba(255,255,255,0.08);
        text-align: center; /* 🔥 center everything */
        vertical-align: middle;
    }

    th {
        color: #ffd264;
        font-weight: bold;
    }

    td {
        color: #fff0b8;
        word-wrap: break-word; /* prevents overflow */
    }

    /* STATUS */
    .status-winning{ color:#9dff9d; font-weight:bold; }
    .status-losing{ color:#ff8f8f; font-weight:bold; }
    .status-pending{ color:#ffd264; }
</style>
</head>
<body>
<div class="dashboard-shell">

<div class="sidebar">
    <img src="logo.png" class="sidebar-logo">
    <div class="sidebar-title">Bidder Panel</div>

    <?php $currentPage = basename($_SERVER['PHP_SELF']); ?>

    <a class="menu-link <?= $currentPage === 'bidder_dashboard.php' ? 'active' : '' ?>" href="bidder_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
    <a class="menu-link <?= $currentPage === 'bidder_available_auctions.php' ? 'active' : '' ?>" href="bidder_available_auctions.php"><i class="fas fa-store"></i> Available Auctions</a>
    <a class="menu-link <?= $currentPage === 'bidder_my_bids.php' ? 'active' : '' ?>" href="bidder_my_bids.php"><i class="fas fa-hand-holding-usd"></i> My Bids</a>
    <a class="menu-link <?= $currentPage === 'bidder_transactions.php' ? 'active' : '' ?>" href="bidder_transactions.php"><i class="fas fa-wallet"></i> Transactions</a>
    <a class="menu-link" href="../logout.php?logout=1"><i class="fas fa-sign-out-alt"></i> Logout</a>
</div>

    <!-- CONTENT -->
    <div class="content">

        <div class="page-title">📊 Transactions Center</div>

            <!-- ================= BID HISTORY ================= -->
            <div class="panel">
                <div class="panel-title">🟢 Bid History</div>

                <table>
                    <tr>
                        <th>Auction</th>
                        <th>My Bid</th>
                        <th>Date & Time</th>
                        <th>Status</th>
                    </tr>

                    <?php while($b = mysqli_fetch_assoc($bid_history)) { ?>
                    <tr>
                        <td><?= htmlspecialchars($b['auction_title']) ?></td>
                        <td>₱<?= number_format($b['bid_amount'],2) ?></td>
                        <td><?= $b['bid_time'] ?></td>
                        <td><?= ucfirst($b['bid_status']) ?></td>
                    </tr>
                    <?php } ?>
                </table>
            </div>

            <!-- ================= WIN / LOSS ================= -->
            <div class="panel">
                <div class="panel-title">💰 Win / Loss Results</div>

                <table>
                    <tr>
                        <th>Auction</th>
                        <th>Final Price</th>
                        <th>Your Last Bid</th>
                        <th>Result</th>
                    </tr>

                    <?php while($r = mysqli_fetch_assoc($results)) { ?>

                    <?php
                        $result = "lost";
                        if ($r['winner_user_id'] == $user_id) {
                            $result = "won";
                        }
                    ?>

                    <tr>
                        <td><?= htmlspecialchars($r['auction_title']) ?></td>
                        <td>₱<?= number_format($r['current_highest_bid'],2) ?></td>
                        <td>₱<?= number_format($r['bid_amount'],2) ?></td>
                        <td class="<?= $result == 'won' ? 'status-winning' : 'status-losing' ?>">
                            <?= $result == 'won' ? '🟢 WON AUCTION' : '🔴 LOST AUCTION' ?>
                        </td>
                    </tr>

                    <?php } ?>
                </table>
            </div>

            <!-- ================= PAYMENTS ================= -->
            <div class="panel">
                <div class="panel-title">💳 Payment Records</div>

                <table>
                    <tr>
                        <th>Auction</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>

                    <?php if ($payment_records && mysqli_num_rows($payment_records) > 0) { ?>
                        <?php while ($pay = mysqli_fetch_assoc($payment_records)) { ?>
                            <tr>
                                <td><?php echo htmlspecialchars($pay['product_name']); ?></td>

                                <td>₱<?php echo number_format((float)$pay['total_amount'], 2); ?></td>

                                <td>
                                    <span class="status-badge status-<?php echo strtolower($pay['delivery_status'] ?? 'pending'); ?>">
                                        <?php echo ucfirst($pay['delivery_status'] ?? 'pending'); ?>
                                    </span>
                                </td>

                                <td><?php echo htmlspecialchars($pay['order_date']); ?></td>
                            </tr>
                        <?php } ?>
                    <?php } else { ?>
                        <tr>
                            <td colspan="4">No payment records found.</td>
                        </tr>
                    <?php } ?>
                </table>
            </div>

        </div>
    </div>
</body>
</html>
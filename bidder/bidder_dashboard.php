<?php
session_start();

/* ================= SECURITY CHECK ================= */
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'buyer') {
    header("Location: index.php?page=login");
    exit();
}

require_once dirname(__DIR__) . '/db.php';
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

/* ================= BIDDER STATS ================= */

// Active Auctions
$active_auctions = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) AS total FROM auctions WHERE status = 'open'"
))['total'] ?? 0;

/* My Active Bids */
$my_active_bids = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) AS total FROM bids 
     WHERE user_id = '$user_id' AND bid_status = 'active'"
))['total'] ?? 0;

/* Winning Bids */
$winning_bids = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) AS total FROM bids 
     WHERE user_id = '$user_id' AND bid_status = 'winning'"
))['total'] ?? 0;

/* My Bids Details */
$my_bids = mysqli_query($conn,
    "SELECT b.bid_amount, b.bid_status, a.auction_title, a.current_highest_bid
     FROM bids b
     JOIN auctions a ON b.auction_id = a.auction_id
     WHERE b.user_id = '$user_id'
     ORDER BY b.bid_time DESC"
);

// Total Spent
$total_spent = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT IFNULL(SUM(total_amount),0) AS total 
     FROM transactions 
     WHERE buyer_id = '$user_id'"
))['total'] ?? 0;
/* ================= AUCTIONS ================= */
$auctions = mysqli_query($conn,
    "SELECT auction_id, auction_title, current_highest_bid, end_time, status
     FROM auctions
     WHERE status = 'open'
     ORDER BY end_time ASC"
);

/* ================= MY BIDS ================= */
$my_bids = mysqli_query($conn,
    "SELECT b.bid_amount, b.bid_status, a.auction_title, a.current_highest_bid
     FROM bids b
     JOIN auctions a ON b.auction_id = a.auction_id
     WHERE b.user_id = '$user_id'
     ORDER BY b.bid_time DESC"
);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<title>Bidder Dashboard</title>

<style>
/* ===== SAME DESIGN AS ADMIN ===== */
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

/* SIDEBAR */
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
    font-size:24px;
    font-family:Georgia;
    margin-bottom:18px;
}

.menu-link{
    display:block;
    text-decoration:none;
    color:#fff0b8;
    background:rgba(255,255,255,0.04);
    border:1px solid rgba(169,179,73,0.22);
    border-radius:10px;
    padding:12px 14px;
    margin-bottom:10px;
}

.menu-link:hover,
.menu-link.active{
    background:linear-gradient(to bottom, #8faa2e, #657f1a);
}

/* CONTENT */
.content{
    flex:1;
    background:rgba(7,16,3,0.95);
    border:1px solid rgba(169,179,73,0.55);
    border-radius:18px;
    padding:20px;
    color:#fff0b8;
}

.topbar{
    display:flex;
    justify-content:space-between;
    margin-bottom:20px;
}

.page-title{
    font-size:34px;
    font-family:Georgia;
    color:#ffe5a6;
}

.admin-badge{
    background:rgba(255,255,255,0.05);
    border:1px solid rgba(255,214,100,0.18);
    padding:10px 14px;
    border-radius:10px;
}

/* STATS */
.stats-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(220px,1fr));
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
    color:#ffd264;
    margin-bottom:8px;
}

.stat-value{
    font-size:30px;
    font-weight:bold;
    color:#fff2c3;
}

/* PANEL */
.panel{
    background:rgba(2,10,2,0.92);
    border:1px solid rgba(95,120,38,0.25);
    border-radius:14px;
    padding:16px;
    margin-top:18px;
}

.panel-title{
    font-size:22px;
    font-family:Georgia;
    color:#ffe5a6;
    margin-bottom:12px;
}

/* TABLE */
table{
    width:100%;
    border-collapse:collapse;
}

th, td{
    padding:12px;
    border-bottom:1px solid rgba(255,255,255,0.08);
}

th{
    color:#ffd264;
}

td{
    color:#fff0b8;
}

.btn{
    padding:6px 10px;
    border:none;
    border-radius:5px;
    background:linear-gradient(to bottom, #8faa2e, #657f1a);
    color:white;
    cursor:pointer;
}

table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed; /*  important for equal alignment */
}

th, td {
    padding: 12px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    text-align: center; /*  center everything */
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

    <div class="topbar">
        <div class="page-title">Bidder Dashboard</div>
        <div class="admin-badge">
            Logged in as: <b><?= htmlspecialchars($username) ?></b>
        </div>
    </div>

    <!-- ✅ TOP SUMMARY CARDS -->
    <div class="stats-grid">

        <div class="stat-card">
            <div class="stat-label">Active Auctions</div>
            <div class="stat-value"><?= $active_auctions ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Winning Bids</div>
            <div class="stat-value"><?= $winning_bids ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Total Spent</div>
            <div class="stat-value">₱<?= number_format($total_spent,2) ?></div>
        </div>

    </div>

    <!-- AUCTIONS -->
    <div class="panel" id="auctions">
        <div class="panel-title">Available Auctions</div>

        <table>
            <tr>
                <th>Title</th>
                <th>Highest Bid</th>
                <th>End Time</th>
                <th>Action</th>
            </tr>

            <?php while($a = mysqli_fetch_assoc($auctions)) { ?>
            <tr>
                <td><?= htmlspecialchars($a['auction_title']) ?></td>
                <td>₱<?= number_format($a['current_highest_bid'],2) ?></td>
                <td><?= $a['end_time'] ?></td>
                <td><button class="btn">Place Bid</button></td>
            </tr>
            <?php } ?>
        </table>
    </div>

    <!-- MY BIDS -->
    <div class="panel" id="bids">
        <div class="panel-title">My Bids</div>

        <table>
            <tr>
                <th>Auction</th>
                <th>My Bid</th>
                <th>Current Highest</th>
                <th>Status</th>
            </tr>

            <?php while($b = mysqli_fetch_assoc($my_bids)) { ?>
            <tr>
                <td><?= htmlspecialchars($b['auction_title']) ?></td>
                <td>₱<?= number_format($b['bid_amount'],2) ?></td>
                <td>₱<?= number_format($b['current_highest_bid'],2) ?></td>
                <td><?= $b['bid_status'] ?></td>
            </tr>
            <?php } ?>
        </table>
    </div>

</div>
</div>

</body>
</html>
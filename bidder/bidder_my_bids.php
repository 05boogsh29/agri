<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php?page=login");
    exit();
}

$conn = mysqli_connect("localhost", "root", "", "agri_markettt", "3306");
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

$user_id = $_SESSION['user_id'];

/* ================= MY BIDS ================= */
$bids = mysqli_query($conn, "
    SELECT 
        a.auction_title,
        b.bid_amount AS my_bid,
        b.bid_status,
        a.current_highest_bid,
        a.status
    FROM bids b
    INNER JOIN auctions a ON a.auction_id = b.auction_id
    WHERE b.user_id = '$user_id'
    ORDER BY b.bid_time DESC
");
?>

<!DOCTYPE html>
<html>
<head>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<title>My Active Bids</title>

<style>
/* SAME DESIGN AS BIDDER DASHBOARD */
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
    box-shadow:0 0 35px rgba(132,170,42,0.22);
    padding:18px 14px;
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
    font-family:Georgia;
    font-size:24px;
    margin-bottom:18px;
}

.menu-link{
    display:block;
    text-decoration:none;
    color:#fff0b8;
    background:rgba(255,255,255,0.04);
    border:1px solid rgba(169,179,73,0.22);
    border-radius:10px;
    padding:12px;
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
}

.page-title{
    font-family:Georgia;
    font-size:34px;
    color:#ffe5a6;
    margin-bottom:20px;
}

/* PANEL */
.panel{
    background:rgba(2,10,2,0.92);
    border:1px solid rgba(95,120,38,0.25);
    border-radius:14px;
    padding:16px;
}

.panel-title{
    font-family:Georgia;
    font-size:22px;
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

/* STATUS COLORS */
.status-winning{
    color:#9dff9d;
    font-weight:bold;
}

.status-losing{
    color:#ff8f8f;
    font-weight:bold;
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

    <div class="page-title">🟢 My Active Bids</div>

    <div class="panel">

        <table>
            <tr>
                <th>Auction Title</th>
                <th>My Bid</th>
                <th>Current Highest Bid</th>
                <th>Status</th>
            </tr>

            <?php if ($bids && mysqli_num_rows($bids) > 0) { ?>
                <?php while ($b = mysqli_fetch_assoc($bids)) { ?>

                    <?php $status = $b['bid_status']; ?>

                    <tr>
                        <td><?= htmlspecialchars($b['auction_title']) ?></td>

                        <td>₱<?= number_format($b['my_bid'], 2) ?></td>

                        <td>₱<?= number_format($b['current_highest_bid'], 2) ?></td>

                        <td class="<?= $status == 'winning' ? 'status-winning' : 'status-losing' ?>">
                            <?= strtoupper($status) ?>
                        </td>
                    </tr>

                <?php } ?>
                
            <?php } else { ?>
                <tr>
                    <td colspan="4">No bids found.</td>
                </tr>
            <?php } ?>

        </table>

    </div>

</div>

</div>

</body>
</html>
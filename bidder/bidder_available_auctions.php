<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php?page=login");
    exit();
}

$conn = mysqli_connect("localhost", "root", "", "agri_markettt", "3306");
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

$user_id = $_SESSION['user_id'];

/* ================= LIVE DATA ================= */
if (isset($_GET['live'])) {

    $result = mysqli_query($conn, "
        SELECT auction_id, current_highest_bid, status
        FROM auctions
    ");

    $data = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $data[$row['auction_id']] = $row;
    }

    echo json_encode($data);
    exit();
}

/* ================= PLACE BID ================= */
if (isset($_POST['submit_bid'])) {

    $auction_id = (int)$_POST['auction_id'];
    $bid_amount = (float)$_POST['bid_amount'];

    /* GET CURRENT HIGHEST BID */
    $stmt = mysqli_prepare($conn, "
        SELECT current_highest_bid 
        FROM auctions 
        WHERE auction_id = ?
    ");
    mysqli_stmt_bind_param($stmt, "i", $auction_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $current_bid);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if ($bid_amount <= $current_bid) {
        $message = "Bid must be higher than current bid.";
    } else {

        /* INSERT BID */
        $insert = mysqli_prepare($conn, "
            INSERT INTO bids (auction_id, user_id, bid_amount, bid_status, bid_time)
            VALUES (?, ?, ?, 'winning', NOW())
        ");

        mysqli_stmt_bind_param($insert, "iid", $auction_id, $user_id, $bid_amount);
        mysqli_stmt_execute($insert);
        mysqli_stmt_close($insert);

        /* SET ALL BIDS TO OUTBID */
        mysqli_query($conn, "
            UPDATE bids
            SET bid_status = 'outbid'
            WHERE auction_id = $auction_id
            AND user_id != $user_id
        ");

        /* SET HIGHEST BID AS WINNING */
        mysqli_query($conn, "
            UPDATE bids
            SET bid_status = 'winning'
            WHERE auction_id = $auction_id
            ORDER BY bid_amount DESC
            LIMIT 1
        ");

        /* UPDATE AUCTION TABLE */
        mysqli_query($conn, "
            UPDATE auctions
            SET current_highest_bid = $bid_amount
            WHERE auction_id = $auction_id
        ");

        $message = "Bid placed successfully!";
    }
}

/* ================= AUCTIONS ================= */
$auctions = mysqli_query($conn, "
    SELECT auction_id, auction_title, starting_bid, current_highest_bid, end_time, status
    FROM auctions
    WHERE status = 'open'
    ORDER BY end_time ASC
");
?>

<!DOCTYPE html>
<html>
<head>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<title>Available Auctions</title>

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
    color:#fff0b8;
}

.dashboard-shell{
    width:100%;
    max-width:1450px;
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

.panel{
    background:rgba(2,10,2,0.92);
    border:1px solid rgba(95,120,38,0.25);
    border-radius:14px;
    padding:16px;
    margin-top:18px;
}

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

.btn{
    padding:8px 12px;
    background:linear-gradient(to bottom, #8faa2e, #657f1a);
    border:none;
    color:white;
    border-radius:6px;
    cursor:pointer;
}

.modal{
    display:none;
    position:fixed;
    top:0;
    left:0;
    width:100%;
    height:100%;
    background:rgba(0,0,0,0.7);
    justify-content:center;
    align-items:center;
}

.modal-content{
    background:rgba(10,20,10,0.98);
    padding:20px;
    border-radius:12px;
    width:350px;
    border:1px solid #8faa2e;
    color:#fff0b8;
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

<div class="content">

<div class="page-title">🟢 Available Auctions</div>

<div class="panel">

<table>
<tr>
<th>Auction Title</th>
<th>Starting Bid</th>
<th>Highest Bid</th>
<th>End Time</th>
<th>Status</th>
<th>Action</th>
</tr>

<?php while ($a = mysqli_fetch_assoc($auctions)) { ?>
<tr data-id="<?= $a['auction_id'] ?>">

<td><?= htmlspecialchars($a['auction_title']) ?></td>
<td>₱<?= number_format($a['starting_bid'], 2) ?></td>
<td class="highest">₱<?= number_format($a['current_highest_bid'], 2) ?></td>
<td><?= $a['end_time'] ?></td>
<td class="status"><?= ucfirst($a['status']) ?></td>

<td>
<button class="btn"
onclick="openModal(<?= $a['auction_id'] ?>,'<?= htmlspecialchars($a['auction_title'], ENT_QUOTES) ?>',<?= $a['current_highest_bid'] ?>)">
Place Bid
</button>
</td>

</tr>
<?php } ?>

</table>

</div>

</div>
</div>

<div id="bidModal" class="modal">
<div class="modal-content">

<h2>Place Your Bid</h2>

<form method="POST">
<input type="hidden" name="auction_id" id="auction_id">

<p><b>Auction:</b> <span id="auction_title"></span></p>
<p><b>Current Bid:</b> ₱<span id="current_bid"></span></p>

<br>

<input type="number" step="0.01" name="bid_amount" required>

<br><br>

<button type="submit" name="submit_bid" class="btn">Submit Bid</button>
<button type="button" class="btn" onclick="closeModal()">Cancel</button>

</form>

</div>
</div>

<script>
function openModal(id,title,bid){
    document.getElementById("bidModal").style.display="flex";
    document.getElementById("auction_id").value=id;
    document.getElementById("auction_title").innerText=title;
    document.getElementById("current_bid").innerText=parseFloat(bid).toFixed(2);
}
function closeModal(){
    document.getElementById("bidModal").style.display="none";
}
window.onclick=function(e){
    if(e.target.id==="bidModal"){
        closeModal();
    }
}
</script>

</body>
</html>
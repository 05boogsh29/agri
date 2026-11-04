<?php
session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php?page=login");
    exit();
}

$conn = mysqli_connect("localhost", "root", "", "agri_markettt", "3306");
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

function get_total($conn, $sql, $field_name = "total") {
    $result = mysqli_query($conn, $sql);
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        return $row[$field_name] ?? 0;
    }
    return 0;
}

$total_farmers = get_total($conn, "SELECT COUNT(*) AS total FROM farmers");
$total_buyers = get_total($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'buyer'");
$active_bids = get_total($conn, "SELECT COUNT(*) AS total FROM bids WHERE bid_status IN ('active', 'winning')");
$ongoing_auctions = get_total($conn, "SELECT COUNT(*) AS total FROM auctions WHERE status = 'open'");
$total_sales = get_total($conn, "
    SELECT IFNULL(SUM(total_amount), 0) AS total
    FROM transactions
    WHERE transaction_status IN ('confirmed', 'completed')
");

$recent_auctions = mysqli_query(
    $conn,
    "SELECT auction_id, auction_title, current_highest_bid, end_time, status
     FROM auctions
     ORDER BY auction_id DESC
     LIMIT 5"
);

$recent_users = mysqli_query(
    $conn,
    "SELECT full_name, username, role, status, created_at
     FROM users
     WHERE role IN ('farmer', 'buyer')
     ORDER BY user_id DESC
     LIMIT 5"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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

        .menu-group{
            margin-bottom:10px;
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

        .admin-badge{
            background:rgba(255,255,255,0.05);
            border:1px solid rgba(255,214,100,0.18);
            border-radius:10px;
            padding:10px 14px;
            font-size:14px;
            color:#fff4c9;
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
            font-size:32px;
            font-weight:bold;
            color:#fff2c3;
        }

        .panel-grid{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:18px;
        }

        .panel{
            background:rgba(2,10,2,0.92);
            border:1px solid rgba(95,120,38,0.25);
            border-radius:14px;
            padding:16px;
        }

        .panel-title{
            font-size:22px;
            color:#ffe5a6;
            font-family:Georgia, serif;
            margin-bottom:14px;
        }

        table{
            width:100%;
            border-collapse:collapse;
            overflow:hidden;
        }

        th, td{
            padding:12px 10px;
            text-align:left;
            border-bottom:1px solid rgba(255,255,255,0.08);
            font-size:14px;
        }

        th{
            color:#ffd264;
            font-weight:bold;
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
        .status-active{
            background:rgba(102, 153, 51, 0.22);
            color:#dfffba;
        }

        .status-closed{
            background:rgba(180, 90, 90, 0.22);
            color:#ffd4d4;
        }

        .quick-note{
            margin-top:18px;
            background:rgba(255,255,255,0.05);
            border:1px solid rgba(255,214,100,0.18);
            border-radius:10px;
            padding:14px;
            font-size:14px;
            line-height:1.6;
        }

        @media (max-width: 1024px){
            .dashboard-shell{
                flex-direction:column;
            }

            .sidebar{
                width:100%;
            }

            .panel-grid{
                grid-template-columns:1fr;
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
            <div class="page-title">Admin Dashboard</div>
            <div class="admin-badge">
                Logged in as: <strong><?php echo htmlspecialchars($_SESSION['full_name']); ?></strong>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Farmers</div>
                <div class="stat-value"><?php echo $total_farmers; ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Total Buyers</div>
                <div class="stat-value"><?php echo $total_buyers; ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Active Bids</div>
                <div class="stat-value"><?php echo $active_bids; ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Ongoing Auctions</div>
                <div class="stat-value"><?php echo $ongoing_auctions; ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Total Sales</div>
                <div class="stat-value">₱<?php echo number_format((float)$total_sales, 2); ?></div>
            </div>
        </div>

        <div class="panel-grid">
            <div class="panel">
                <div class="panel-title">Recent Auctions</div>
                <table>
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Highest Bid</th>
                            <th>End Time</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recent_auctions && mysqli_num_rows($recent_auctions) > 0) { ?>
                            <?php while ($auction = mysqli_fetch_assoc($recent_auctions)) { ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($auction['auction_title']); ?></td>
                                    <td>₱<?php echo number_format((float)$auction['current_highest_bid'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($auction['end_time']); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo ($auction['status'] === 'open') ? 'status-open' : 'status-closed'; ?>">
                                            <?php echo htmlspecialchars(ucfirst($auction['status'])); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr>
                                <td colspan="4">No auction records found.</td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

            <div class="panel">
                <div class="panel-title">Newest Users</div>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recent_users && mysqli_num_rows($recent_users) > 0) { ?>
                            <?php while ($user = mysqli_fetch_assoc($recent_users)) { ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst($user['role'])); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo ($user['status'] === 'active') ? 'status-active' : 'status-closed'; ?>">
                                            <?php echo htmlspecialchars(ucfirst($user['status'])); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr>
                                <td colspan="4">No user records found.</td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

</div>

</body>
</html>
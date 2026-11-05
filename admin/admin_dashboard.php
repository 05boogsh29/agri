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
                <div class="page-eyebrow">MARKETPLACE OVERVIEW</div>
                <div class="page-title">Admin Dashboard</div>
                <p class="page-subtitle">Monitor farmers, buyers, active bids, ongoing auctions, and marketplace sales in one place.</p>
            </div>

            <div class="admin-badge">

                Logged in as: <strong><?php echo htmlspecialchars($_SESSION['full_name']); ?></strong>

            </div>

        </div>



        <div class="stats-grid">

            <div class="stat-card">

                <div class="stat-icon"><i class="fas fa-tractor"></i></div><div class="stat-label">Total Farmers</div>

                <div class="stat-value"><?php echo $total_farmers; ?></div>

            </div>



            <div class="stat-card">

                <div class="stat-icon"><i class="fas fa-users"></i></div><div class="stat-label">Total Buyers</div>

                <div class="stat-value"><?php echo $total_buyers; ?></div>

            </div>



            <div class="stat-card">

                <div class="stat-icon"><i class="fas fa-gavel"></i></div><div class="stat-label">Active Bids</div>

                <div class="stat-value"><?php echo $active_bids; ?></div>

            </div>



            <div class="stat-card">

                <div class="stat-icon"><i class="fas fa-bullhorn"></i></div><div class="stat-label">Ongoing Auctions</div>

                <div class="stat-value"><?php echo $ongoing_auctions; ?></div>

            </div>



            <div class="stat-card">

                <div class="stat-icon"><i class="fas fa-coins"></i></div><div class="stat-label">Total Sales</div>

                <div class="stat-value">₱<?php echo number_format((float)$total_sales, 2); ?></div>

            </div>

        </div>



        <div class="panel-grid">

            <div class="panel">

                <div class="panel-title">Recent Auctions</div>
                <p class="panel-note">The five most recently created auctions and their current bidding status.</p>

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
                <p class="panel-note">Recently registered farmers and buyers on the marketplace.</p>

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
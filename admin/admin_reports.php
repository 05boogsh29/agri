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

$message = "";

/* ================= ADD COMMISSION RATE ================= */
if (isset($_POST['add_commission'])) {
    $rate_name = trim($_POST['rate_name']);
    $rate_percent = (float)$_POST['rate_percent'];
    $description = trim($_POST['description']);

    if ($rate_name === "" || $rate_percent < 0) {
        $message = "Please enter a valid commission name and rate.";
    } else {
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO commission_rates (rate_name, rate_percent, description, is_active, effective_date)
             VALUES (?, ?, ?, 0, CURDATE())"
        );
        mysqli_stmt_bind_param($stmt, "sds", $rate_name, $rate_percent, $description);

        if (mysqli_stmt_execute($stmt)) {
            $message = "Commission rate added successfully.";
        } else {
            $message = "Failed to add commission rate.";
        }
    }
}

/* ================= SET ACTIVE COMMISSION RATE ================= */
if (isset($_POST['set_active_commission'])) {
    $commission_id = (int)$_POST['commission_id'];

    mysqli_query($conn, "UPDATE commission_rates SET is_active = 0");
    $stmt = mysqli_prepare($conn, "UPDATE commission_rates SET is_active = 1 WHERE commission_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $commission_id);

    if (mysqli_stmt_execute($stmt)) {
        $message = "Active commission rate updated.";
    } else {
        $message = "Failed to update active commission rate.";
    }
}

/* ================= PAGINATION SETTINGS ================= */
$perPage = 5;
$sales_page = isset($_GET['sales_page']) ? max(1, (int)$_GET['sales_page']) : 1;
$bidding_page = isset($_GET['bidding_page']) ? max(1, (int)$_GET['bidding_page']) : 1;
$commission_page = isset($_GET['commission_page']) ? max(1, (int)$_GET['commission_page']) : 1;

/* ================= SUMMARY ================= */
function get_total($conn, $sql, $field = "total") {
    $result = mysqli_query($conn, $sql);
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        return $row[$field] ?? 0;
    }
    return 0;
}

$total_sales = get_total($conn, "
    SELECT IFNULL(SUM(total_amount),0) AS total
    FROM transactions
    WHERE transaction_status IN ('confirmed', 'completed')
");

$total_commission = get_total($conn, "
    SELECT IFNULL(SUM(commission_amount),0) AS total
    FROM transactions
    WHERE transaction_status IN ('confirmed', 'completed')
");
$total_bids = get_total($conn, "SELECT COUNT(*) AS total FROM bids");
$total_auctions = get_total($conn, "SELECT COUNT(*) AS total FROM auctions");
$total_users = get_total($conn, "SELECT COUNT(*) AS total FROM users WHERE role IN ('farmer','buyer')");
$total_activity = get_total($conn, "SELECT COUNT(*) AS total FROM activity_logs");

// Pagination
$perPage = 5;
$sales_page = isset($_GET['sales_page']) ? max(1, (int)$_GET['sales_page']) : 1;
$sales_offset = ($sales_page - 1) * $perPage;

// Sales Report
$sales_report = mysqli_query(
    $conn,
    "SELECT
        t.transaction_id,
        p.product_name,
        seller.full_name AS seller_name,
        buyer.full_name AS buyer_name,
        t.total_amount,
        t.commission_amount,
        t.net_amount,
        t.completed_at,
        t.transaction_status
     FROM transactions t
     INNER JOIN products p ON t.product_id = p.product_id
     INNER JOIN farmers seller ON t.seller_id = seller.farmer_id
     LEFT JOIN users buyer ON t.buyer_id = buyer.user_id
     WHERE t.transaction_status IN ('confirmed', 'completed')
     ORDER BY t.transaction_id DESC
     LIMIT $sales_offset, $perPage"
);

$total_sales_rows = get_total(
    $conn,
    "SELECT COUNT(*) AS total
     FROM transactions
     WHERE transaction_status IN ('confirmed', 'completed')"
);
$total_sales_pages = ceil($total_sales_rows / $perPage);

/* Commission Rates */
$commission_offset = ($commission_page - 1) * $perPage;
$commission_rates = mysqli_query(
    $conn,
    "SELECT commission_id, rate_name, rate_percent, description, is_active, effective_date, created_at
     FROM commission_rates
     ORDER BY commission_id DESC
     LIMIT $commission_offset, $perPage"
);
$total_commission_rows = get_total($conn, "SELECT COUNT(*) AS total FROM commission_rates");
$total_commission_pages = ceil($total_commission_rows / $perPage);

/* ================= BIDDING REPORT PAGINATION ================= */
$bidding_page = isset($_GET['bidding_page']) ? max(1, (int)$_GET['bidding_page']) : 1;

$total_bidding_rows = get_total($conn, "
    SELECT COUNT(*) AS total
    FROM auctions
");

$total_bidding_pages = max(1, (int)ceil($total_bidding_rows / $perPage));

if ($bidding_page > $total_bidding_pages) {
    $bidding_page = $total_bidding_pages;
}

$bidding_offset = ($bidding_page - 1) * $perPage;

/* ================= BIDDING REPORT ================= */
$bidding_report = mysqli_query(
    $conn,
    "SELECT
        a.auction_id,
        a.auction_title,
        p.product_name,
        a.status,
        a.starting_bid,
        a.current_highest_bid,
        a.winning_bid_amount,
        u.full_name AS winner_name
     FROM auctions a
     INNER JOIN products p ON a.product_id = p.product_id
     LEFT JOIN users u ON a.winner_user_id = u.user_id
     ORDER BY a.auction_id DESC
     LIMIT $bidding_offset, $perPage"
);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Agri-Market Reports</title>
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

        .menu-link:hover,
        .menu-link.active {
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

        .form-group.full {
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

        .btn:hover {
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

        .page-link.active,
        .page-link:hover {
            background: linear-gradient(to bottom, #8faa2e, #657f1a);
            color: #fff8d4;
        }

        .small-note {
            font-size: 12px;
            color: #f4e7b1;
            margin-top: 6px;
            line-height: 1.5;
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

.pagination {
    margin-top: 12px;
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.page-link {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 6px;
    background: rgba(255,255,255,0.05);
    color: #fff0b8;
    text-decoration: none;
    font-weight: bold;
    transition: 0.2s;
}

.page-link:hover {
    background: rgba(255,255,255,0.12);
}

.page-link.active {
    background: linear-gradient(to bottom, #8faa2e, #657f1a);
    color: #fff2c3;
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
            <div class="page-title">Reports</div>
        </div>

        <?php if ($message != ""): ?>
            <div class="message-box"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Sales</div>
                <div class="stat-value">₱<?php echo number_format((float)$total_sales, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Commission</div>
                <div class="stat-value">₱<?php echo number_format((float)$total_commission, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Bids</div>
                <div class="stat-value"><?php echo $total_bids; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Auctions</div>
                <div class="stat-value"><?php echo $total_auctions; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Users</div>
                <div class="stat-value"><?php echo $total_users; ?></div>
            </div>
        </div>

        <!-- Commission Rates Table -->
        <div class="panel">
        <div class="panel-title">Commission Rate</div>

        <!-- Commission Rates Table -->
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Rate</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($commission_rates && mysqli_num_rows($commission_rates) > 0): ?>
                        <?php while ($rate = mysqli_fetch_assoc($commission_rates)): ?>
                            <tr>
                                <td><?= htmlspecialchars($rate['rate_name']) ?></td>
                                <td><?= number_format((float)$rate['rate_percent'], 2) ?>%</td>
                                <td><?= htmlspecialchars($rate['description'] ?? '') ?></td>
                                <td>
                                    <span class="<?= $rate['is_active'] == 1 ? 'status-delivered' : 'status-pending' ?>">
                                        <?= $rate['is_active'] == 1 ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="POST">
                                        <input type="hidden" name="commission_id" value="<?= $rate['commission_id'] ?>">
                                        <button type="submit" name="set_active_commission" class="btn">Set Active</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="empty-note">No commission rates found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_commission_pages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $total_commission_pages; $i++): ?>
                <a class="page-link <?= $i === $commission_page ? 'active' : '' ?>" href="?commission_page=<?= $i ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>

    <script>
    function toggleCommissionForm() {
        const formBox = document.getElementById('commissionFormBox');
        formBox.classList.toggle('show');
    }
    </script>

        <!-- Sales Report Table -->
        <div class="panel">
            <div class="panel-title">Sales Report</div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Transaction</th>
                            <th>Product</th>
                            <th>Seller</th>
                            <th>Buyer</th>
                            <th>Total Amount</th>
                            <th>Commission</th>
                            <th>Net Amount</th>
                            <th>Completed At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($sales_report && mysqli_num_rows($sales_report) > 0): ?>
                            <?php while ($sale = mysqli_fetch_assoc($sales_report)): ?>
                                <tr>
                                    <td>#<?= $sale['transaction_id'] ?></td>
                                    <td><?= htmlspecialchars($sale['product_name']) ?></td>
                                    <td><?= htmlspecialchars($sale['seller_name']) ?></td>
                                    <td><?= htmlspecialchars($sale['buyer_name']) ?></td>
                                    <td>₱<?= number_format((float)$sale['total_amount'],2) ?></td>
                                    <td>₱<?= number_format((float)$sale['commission_amount'],2) ?></td>
                                    <td>₱<?= number_format((float)$sale['net_amount'],2) ?></td>
                                    <td><?= htmlspecialchars($sale['completed_at']) ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="8">No sales records found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Sales Pagination -->
            <?php if ($total_sales_pages > 1): ?>
            <div class="pagination">
                <?php for ($i=1; $i<=$total_sales_pages; $i++): ?>
                    <a class="page-link <?= $i === $sales_page ? 'active' : '' ?>"
                       href="?sales_page=<?= $i ?>"><?= $i ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Bidding Report Table -->
        <div class="panel">
            <div class="panel-title">Bidding Report</div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Auction</th>
                            <th>Product</th>
                            <th>Status</th>
                            <th>Starting Bid</th>
                            <th>Highest Bid</th>
                            <th>Winning Bid</th>
                            <th>Winner</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($bidding_report && mysqli_num_rows($bidding_report) > 0): ?>
                            <?php while ($bid = mysqli_fetch_assoc($bidding_report)): ?>
                                <tr>
                                    <td><?= htmlspecialchars($bid['auction_title']) ?></td>
                                    <td><?= htmlspecialchars($bid['product_name']) ?></td>
                                    <td><?= htmlspecialchars(ucfirst($bid['status'])) ?></td>
                                    <td>₱<?= number_format((float)$bid['starting_bid'],2) ?></td>
                                    <td>₱<?= number_format((float)$bid['current_highest_bid'],2) ?></td>
                                    <td><?= $bid['winning_bid_amount'] ? '₱' . number_format((float)$bid['winning_bid_amount'],2) : '—' ?></td>
                                    <td><?= $bid['winner_name'] ? htmlspecialchars($bid['winner_name']) : 'No winner' ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="7">No bidding records found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Bidding Pagination -->
            <?php if ($total_bidding_pages > 1): ?>
            <div class="pagination">
                <?php for ($i=1; $i<=$total_bidding_pages; $i++): ?>
                    <a class="page-link <?= $i === $bidding_page ? 'active' : '' ?>"
                       href="?bidding_page=<?= $i ?>"><?= $i ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
function toggleCommissionForm() {
    const formBox = document.getElementById('commissionFormBox');
    formBox.classList.toggle('show');
}
</script>
</body>
</html>
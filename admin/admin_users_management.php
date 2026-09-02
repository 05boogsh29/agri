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

/* ================= HELPERS ================= */
function get_total($conn, $sql, $field_name = "total") {
    $result = mysqli_query($conn, $sql);
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        return (int)($row[$field_name] ?? 0);
    }
    return 0;
}

function count_status($conn, $table, $status) {
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM $table WHERE status = ?");
    mysqli_stmt_bind_param($stmt, "s", $status);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    return (int)($row['total'] ?? 0);
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

/* ================= ADD FARMER ================= */
if (isset($_POST['add_farmer'])) {
    $full_name = trim($_POST['farmer_full_name']);
    $contact_number = trim($_POST['farmer_contact_number']);
    $address = trim($_POST['farmer_address']);
    $email = trim($_POST['farmer_email']);
    $status = trim($_POST['farmer_status']);

    if ($full_name === "" || $email === "") {
        $message = "Full name and email are required.";
    } elseif (!in_array($status, ['active', 'suspended'])) {
        $message = "Invalid status.";
    } else {
        $check_stmt = mysqli_prepare($conn, "SELECT farmer_id FROM farmers WHERE email = ?");
        mysqli_stmt_bind_param($check_stmt, "s", $email);
        mysqli_stmt_execute($check_stmt);
        mysqli_stmt_store_result($check_stmt);

        if (mysqli_stmt_num_rows($check_stmt) > 0) {
            $message = "Email already exists. Please use a different email.";
        } else {
            $created_at = date("Y-m-d H:i:s");

            $insert_stmt = mysqli_prepare(
                $conn,
                "INSERT INTO farmers 
                (full_name, contact_number, email, address, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?)"
            );

            mysqli_stmt_bind_param(
                $insert_stmt,
                "ssssss",
                $full_name,
                $contact_number,
                $email,
                $address,
                $status,
                $created_at
            );

            if (mysqli_stmt_execute($insert_stmt)) {
                $message = "Farmer added successfully!";
                header("Location: admin_users_management.php");
                exit();
            } else {
                $message = "Failed to add farmer.";
            }
        }
    }
}

/* ================= UPDATE STATUS ================= */
if (isset($_POST['update_status'])) {
    $user_id = (int)$_POST['user_id'];
    $new_status = trim($_POST['new_status']);

    if (!in_array($new_status, ['active', 'suspended'])) {
        $message = "Invalid status.";
    } else {
        $stmt = mysqli_prepare($conn, "UPDATE farmers SET status = ? WHERE farmer_id = ?");
        mysqli_stmt_bind_param($stmt, "si", $new_status, $user_id);
        mysqli_stmt_execute($stmt);

        if (mysqli_stmt_affected_rows($stmt) > 0) {
            $message = "Farmer status updated!";
            header("Location: admin_users_management.php?" . http_build_query($_GET));
            exit();
        } else {
            $stmt2 = mysqli_prepare($conn, "UPDATE users SET status = ? WHERE user_id = ?");
            mysqli_stmt_bind_param($stmt2, "si", $new_status, $user_id);
            mysqli_stmt_execute($stmt2);

            if (mysqli_stmt_affected_rows($stmt2) > 0) {
                $message = "Buyer status updated!";
                header("Location: admin_users_management.php?" . http_build_query($_GET));
                exit();
            } else {
                $message = "Update failed or no changes made.";
            }
        }
    }
}

/* ================= COUNTS ================= */
$total_farmers = get_total($conn, "SELECT COUNT(*) AS total FROM farmers");
$total_buyers  = get_total($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'buyer'");

$active_farmers = count_status($conn, 'farmers', 'active');
$suspended_farmers = count_status($conn, 'farmers', 'suspended');

$active_buyers = count_status($conn, 'users', 'active');
$suspended_buyers = count_status($conn, 'users', 'suspended');

$active_users = $active_farmers + $active_buyers;
$suspended_users = $suspended_farmers + $suspended_buyers;

/* ================= PAGINATION ================= */
$rows_per_page = 5;

$farmers_page = isset($_GET['farmers_page']) ? max(1, (int)$_GET['farmers_page']) : 1;
$buyers_page = isset($_GET['buyers_page']) ? max(1, (int)$_GET['buyers_page']) : 1;

$total_farmers_pages = max(1, (int)ceil($total_farmers / $rows_per_page));
$total_buyers_pages = max(1, (int)ceil($total_buyers / $rows_per_page));

if ($farmers_page > $total_farmers_pages) {
    $farmers_page = $total_farmers_pages;
}
if ($buyers_page > $total_buyers_pages) {
    $buyers_page = $total_buyers_pages;
}

$farmers_offset = ($farmers_page - 1) * $rows_per_page;
$buyers_offset = ($buyers_page - 1) * $rows_per_page;

/* ================= FARMERS LIST ================= */
$farmers_stmt = mysqli_prepare(
    $conn,
    "SELECT farmer_id, full_name, contact_number, address, status, created_at
     FROM farmers
     ORDER BY farmer_id DESC
     LIMIT ?, ?"
);
mysqli_stmt_bind_param($farmers_stmt, "ii", $farmers_offset, $rows_per_page);
mysqli_stmt_execute($farmers_stmt);
$farmers_result = mysqli_stmt_get_result($farmers_stmt);

/* ================= BUYERS LIST ================= */
$buyers_stmt = mysqli_prepare(
    $conn,
    "SELECT user_id, full_name, email, role, status, contact_number, address, created_at
     FROM users
     WHERE role = 'buyer'
     ORDER BY user_id DESC
     LIMIT ?, ?"
);

if ($buyers_stmt === false) {
    die('Error preparing the statement: ' . mysqli_error($conn));
}

mysqli_stmt_bind_param($buyers_stmt, "ii", $buyers_offset, $rows_per_page);

if (!mysqli_stmt_execute($buyers_stmt)) {
    die('Error executing statement: ' . mysqli_error($conn));
}

$buyers_result = mysqli_stmt_get_result($buyers_stmt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agri-Market Users Management</title>
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

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .page-title {
            color: #ffe5a6;
            font-family: Georgia, serif;
            font-size: 34px;
        }

        .message-box {
            margin-bottom: 15px;
            padding: 12px 14px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 214, 100, 0.18);
            color: #fff4c9;
            font-size: 14px;
            line-height: 1.5;
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

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }

        .panel-title {
            font-size: 22px;
            color: #ffe5a6;
            font-family: Georgia, serif;
        }

        .toggle-btn,
        .action-btn {
            height: 38px;
            padding: 0 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            background: linear-gradient(to bottom, #8faa2e, #657f1a);
            color: #fff2c3;
            font-weight: bold;
        }

        .section-note {
            font-size: 13px;
            color: #f7edbf;
            margin-bottom: 10px;
            line-height: 1.5;
        }

        .form-box {
            display: none;
            margin-bottom: 18px;
            padding: 16px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(169, 179, 73, 0.20);
            border-radius: 12px;
        }

        .form-box.show {
            display: block;
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
            vertical-align: middle;
        }

        th {
            color: #ffd264;
            font-weight: bold;
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

        .status-active {
            background: rgba(102, 153, 51, 0.22);
            color: #dfffba;
        }

        .status-suspended {
            background: rgba(180, 90, 90, 0.22);
            color: #ffd4d4;
        }

        .action-form {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .action-select {
            height: 36px;
            border: none;
            outline: none;
            border-radius: 6px;
            padding: 0 10px;
            background: #e4e2dc;
            color: #333;
        }

        .pagination-wrapper {
            margin-top: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .pagination-info {
            font-size: 13px;
            color: #f7edbf;
        }

        .pagination {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .page-link {
            display: inline-block;
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(169, 179, 73, 0.25);
            color: #fff0b8;
            font-size: 14px;
            transition: 0.2s;
        }

        .page-link:hover {
            background: linear-gradient(to bottom, #8faa2e, #657f1a);
            color: #fff8d4;
        }

        .page-link.active {
            background: linear-gradient(to bottom, #8faa2e, #657f1a);
            color: #fff8d4;
            pointer-events: none;
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
            <div class="page-title">Users Management</div>
        </div>

        <?php if ($message != "") { ?>
            <div class="message-box"><?php echo htmlspecialchars($message); ?></div>
        <?php } ?>

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
                <div class="stat-label">Active Users</div>
                <div class="stat-value"><?php echo $active_users; ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Suspended Users</div>
                <div class="stat-value"><?php echo $suspended_users; ?></div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Farmers List</div>
                <button type="button" class="toggle-btn" onclick="toggleFarmerForm()">Add Farmer</button>
            </div>

            <div class="section-note">
                View all registered farmers and update their status to active or suspended.
            </div>

            <div id="farmerFormBox" class="form-box">
                <form method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Full Name</label>
                            <input type="text" name="farmer_full_name" required>
                        </div>

                        <div class="form-group">
                            <label>Contact Number</label>
                            <input type="text" name="farmer_contact_number">
                        </div>

                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="farmer_email" required>
                        </div>

                        <div class="form-group">
                            <label>Status</label>
                            <select name="farmer_status" required>
                                <option value="active">Active</option>
                                <option value="suspended">Suspended</option>
                            </select>
                        </div>

                        <div class="form-group full">
                            <label>Address</label>
                            <textarea name="farmer_address"></textarea>
                        </div>

                        <div class="form-group full">
                            <button type="submit" name="add_farmer" class="action-btn">Save Farmer</button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Full Name</th>
                            <th>Contact</th>
                            <th>Address</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Date Registered</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($farmers_result && mysqli_num_rows($farmers_result) > 0) { ?>
                            <?php while ($farmer = mysqli_fetch_assoc($farmers_result)) { ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($farmer['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($farmer['contact_number'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($farmer['address'] ?? ''); ?></td>
                                    <td>Farmer</td>
                                    <td>
                                        <span class="status-badge <?php echo ($farmer['status'] === 'active') ? 'status-active' : 'status-suspended'; ?>">
                                            <?php echo htmlspecialchars(ucfirst($farmer['status'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($farmer['created_at']); ?></td>
                                    <td>
                                        <form method="POST" class="action-form">
                                            <input type="hidden" name="user_id" value="<?php echo $farmer['farmer_id']; ?>">
                                            <select name="new_status" class="action-select">
                                                <option value="active" <?php echo ($farmer['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                                <option value="suspended" <?php echo ($farmer['status'] === 'suspended') ? 'selected' : ''; ?>>Suspended</option>
                                            </select>
                                            <button type="submit" name="update_status" class="action-btn">Update</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr>
                                <td colspan="7">No farmers found.</td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

            <?php echo renderPagination($farmers_page, $total_farmers_pages, 'farmers_page'); ?>
        </div>

        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Buyers List</div>
            </div>

            <div class="section-note">
                View all registered buyers and update their status to active or suspended.
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Contact</th>
                            <th>Address</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Date Registered</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($buyers_result && mysqli_num_rows($buyers_result) > 0) { ?>
                            <?php while ($buyer = mysqli_fetch_assoc($buyers_result)) { ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($buyer['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($buyer['email']); ?></td>
                                    <td><?php echo htmlspecialchars($buyer['contact_number'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($buyer['address'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst($buyer['role'])); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo ($buyer['status'] === 'active') ? 'status-active' : 'status-suspended'; ?>">
                                            <?php echo htmlspecialchars(ucfirst($buyer['status'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($buyer['created_at']); ?></td>
                                    <td>
                                        <form method="POST" class="action-form">
                                            <input type="hidden" name="user_id" value="<?php echo $buyer['user_id']; ?>">
                                            <select name="new_status" class="action-select">
                                                <option value="active" <?php echo ($buyer['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                                <option value="suspended" <?php echo ($buyer['status'] === 'suspended') ? 'selected' : ''; ?>>Suspended</option>
                                            </select>
                                            <button type="submit" name="update_status" class="action-btn">Update</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr>
                                <td colspan="8">No buyers found.</td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

            <?php echo renderPagination($buyers_page, $total_buyers_pages, 'buyers_page'); ?>
        </div>
    </div>
</div>

<script>
function toggleFarmerForm() {
    const formBox = document.getElementById('farmerFormBox');
    formBox.classList.toggle('show');
}
</script>

</body>
</html>
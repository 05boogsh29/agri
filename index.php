<?php
session_start();

/* ================= DATABASE CONNECTION ================= */
$conn = mysqli_connect("localhost", "root", "", "agri_markettt", "3306");
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

/* ================= AUTO-CREATE DEFAULT ADMIN =================
   username: admin
   password: admin123
   password is stored as HASH in database
*/
$check_admin_stmt = mysqli_prepare($conn, "SELECT user_id FROM users WHERE username = ? LIMIT 1");
$admin_username = "admin";
mysqli_stmt_bind_param($check_admin_stmt, "s", $admin_username);
mysqli_stmt_execute($check_admin_stmt);
$admin_result = mysqli_stmt_get_result($check_admin_stmt);

if (mysqli_num_rows($admin_result) == 0) {
    $admin_full_name = "Administrator";
    $admin_email = "admin@agrimarket.local";
    $admin_password_hash = password_hash("admin123", PASSWORD_DEFAULT);
    $admin_role = "admin";
    $admin_status = "active";

    $insert_admin_stmt = mysqli_prepare(
        $conn,
        "INSERT INTO users (full_name, username, email, password, role, status)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param(
        $insert_admin_stmt,
        "ssssss",
        $admin_full_name,
        $admin_username,
        $admin_email,
        $admin_password_hash,
        $admin_role,
        $admin_status
    );
    mysqli_stmt_execute($insert_admin_stmt);
}

/* ================= DEFAULT PAGE ================= */
$page = "login";
if (isset($_GET['page']) && $_GET['page'] === "register") {
    $page = "register";
}

/* ================= MESSAGES ================= */
$login_message = "";
$register_message = "";

/* ================= LOGOUT ================= */
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header("Location: index.php?page=login");
    exit();
}

/* ================= REGISTER ================= */
if (isset($_POST['register'])) {
    $page = "register";

    $full_name = trim($_POST['full_name']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    $role = "buyer"; // ONLY bidders can register

    if (
        $full_name == "" ||
        $username == "" ||
        $email == "" ||
        $password == "" ||
        $confirm_password == ""
    ) {
        $register_message = "Please fill in all fields.";
    } elseif (strtolower($username) === "admin") {
        $register_message = "The username 'admin' is reserved.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $register_message = "Please enter a valid email address.";
    } elseif ($password !== $confirm_password) {
        $register_message = "Passwords do not match.";
    } else {

        $check_stmt = mysqli_prepare($conn, "SELECT user_id FROM users WHERE username = ? OR email = ?");
        mysqli_stmt_bind_param($check_stmt, "ss", $username, $email);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);

        if (mysqli_num_rows($check_result) > 0) {
            $register_message = "Username or email already exists.";
        } else {

            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $status = "active";

            $insert_stmt = mysqli_prepare(
                $conn,
                "INSERT INTO users (full_name, username, email, password, role, status)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );

            mysqli_stmt_bind_param(
                $insert_stmt,
                "ssssss",
                $full_name,
                $username,
                $email,
                $hashed_password,
                $role,
                $status
            );

            if (mysqli_stmt_execute($insert_stmt)) {
                $register_message = "Registration successful. You may now log in.";
                $page = "login";
            } else {
                $register_message = "Registration failed. Please try again.";
            }
        }
    }
}

/* ================= LOGIN ================= */
if (isset($_POST['login'])) {
    $page = "login";

    $login_username = trim($_POST['login_username']);
    $login_password = $_POST['login_password']; // FIXED

    if ($login_username == "" || $login_password == "") {
        $login_message = "Please enter username and password.";
    } else {

        $login_stmt = mysqli_prepare(
            $conn,
            "SELECT user_id, full_name, username, email, password, role, status, login_attempts
            FROM users
            WHERE username = ?
            LIMIT 1"
        );

        mysqli_stmt_bind_param($login_stmt, "s", $login_username);
        mysqli_stmt_execute($login_stmt);
        $login_result = mysqli_stmt_get_result($login_stmt);

        if ($row = mysqli_fetch_assoc($login_result)) {

    if ($row['status'] === 'suspended') {
        $login_message = "Your account has been suspended. Contact admin to reactivate.";
    } elseif (password_verify($login_password, $row['password'])) {

        // Reset login attempts on successful login
        $reset_stmt = mysqli_prepare($conn, "UPDATE users SET login_attempts = 0 WHERE user_id = ?");
        mysqli_stmt_bind_param($reset_stmt, "i", $row['user_id']);
        mysqli_stmt_execute($reset_stmt);

        $_SESSION['user_id'] = $row['user_id'];
        $_SESSION['full_name'] = $row['full_name'];
        $_SESSION['username'] = $row['username'];
        $_SESSION['email'] = $row['email'];
        $_SESSION['role'] = $row['role'];

        if ($row['role'] === 'admin') {
            header("Location: admin/admin_dashboard.php");
            exit();
        } elseif ($row['role'] === 'buyer') {
            header("Location: bidder/bidder_dashboard.php");
            exit();
        } else {
            header("Location: index.php?page=login");
            exit();
        }

    } else {
        // Increase login attempts
        $attempts = $row['login_attempts'] + 1;

        if ($attempts >= 3) {
            // Suspend account
            $suspend_stmt = mysqli_prepare($conn, "UPDATE users SET status='suspended', login_attempts=? WHERE user_id=?");
            mysqli_stmt_bind_param($suspend_stmt, "ii", $attempts, $row['user_id']);
            mysqli_stmt_execute($suspend_stmt);

            $login_message = "Your account has been suspended after 3 failed attempts. Contact admin.";
        } else {
            // Update attempts
            $update_stmt = mysqli_prepare($conn, "UPDATE users SET login_attempts=? WHERE user_id=?");
            mysqli_stmt_bind_param($update_stmt, "ii", $attempts, $row['user_id']);
            mysqli_stmt_execute($update_stmt);

            $login_message = "Incorrect password. Attempt $attempts of 3.";
        }
    }

} else {
    $login_message = "Username not found.";
}
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agri-Market</title>
    <style>
        *{
            margin:0;
            padding:0;
            box-sizing:border-box;
            font-family: Arial, sans-serif;
        }

        body{
            min-height:100vh;
            display:flex;
            justify-content:center;
            align-items:center;
            background-image:url('bg.png');
            background-size:cover;
            background-position:center;
            background-attachment:fixed;
            padding:20px;
        }

        .main-window{
            width:750px;
            min-height:620px;
            background:rgba(7,16,3,0.95);
            border:1px solid rgba(169,179,73,0.55);
            border-radius:18px;
            box-shadow:0 0 35px rgba(132,170,42,0.28);
            padding:10px;
        }

        .logo-wrap{
            width:100%;
            display:flex;
            justify-content:center;
            align-items:center;
            margin-bottom:4px;
        }

        .form-logo{
            width:100%;
            max-width:380px;
            height:auto;
            display:block;
        }

        .form-box{
            width:100%;
            background:rgba(2,10,2,0.90);
            border:1px solid rgba(95,120,38,0.20);
            border-radius:14px;
            padding:14px 18px 20px 18px;
            min-height:500px;
        }

        .title{
            text-align:center;
            color:#ffe5a6;
            font-family:Georgia, serif;
            font-size:31px;
            font-weight:bold;
            line-height:1.0;
            margin-bottom:18px;
        }

        .message{
            min-height:20px;
            text-align:center;
            color:#ffd264;
            font-size:13px;
            margin-bottom:12px;
        }

        .spacer-login{
            height:35px;
        }

        .spacer-register{
            height:20px;
        }

        input, select{
            width:100%;
            height:40px;
            background:#e4e2dc;
            border:none;
            outline:none;
            border-radius:4px;
            padding:0 12px;
            font-size:13px;
            color:#333;
            margin-bottom:14px;
        }

        select{
            cursor:pointer;
        }

        .btn{
            width:100%;
            height:42px;
            border:none;
            border-radius:4px;
            cursor:pointer;
            background:linear-gradient(to bottom, #8faa2e, #657f1a);
            color:#fff2c3;
            font-size:18px;
            font-weight:bold;
            margin-top:4px;
        }

        .btn:hover{
            opacity:0.96;
        }

        .bottom-text{
            text-align:center;
            color:#fff0b8;
            font-size:14px;
            margin-top:14px;
            line-height:1.5;
        }

        .bottom-text a{
            color:#ffcc40;
            text-decoration:none;
            font-weight:bold;
        }

        .welcome-box{
            width:430px;
            background:rgba(7,16,3,0.95);
            border:1px solid rgba(169,179,73,0.55);
            border-radius:18px;
            box-shadow:0 0 35px rgba(132,170,42,0.28);
            padding:24px;
            color:#ffe8a7;
            text-align:center;
        }

        .welcome-logo{
            width:100%;
            max-width:300px;
            margin:0 auto 18px auto;
            display:block;
        }

        .welcome-box h2{
            font-family:Georgia, serif;
            font-size:34px;
            margin-bottom:12px;
        }

        .welcome-box p{
            font-size:16px;
            margin-bottom:10px;
        }

        .logout-btn{
            display:inline-block;
            margin-top:12px;
            padding:12px 24px;
            border-radius:4px;
            text-decoration:none;
            background:linear-gradient(to bottom, #8faa2e, #657f1a);
            color:#fff2c3;
            font-weight:bold;
        }

        .logout-btn:hover{
            opacity:0.95;
        }

        .note-box{
            margin-top:18px;
            padding:10px 14px;
            background:rgba(255,255,255,0.06);
            border:1px solid rgba(255,214,100,0.18);
            border-radius:8px;
            color:#fff0b8;
            font-size:13px;
            line-height:1.5;
        }

        @media (max-width: 500px){
            .main-window,
            .welcome-box{
                width:100%;
                max-width:430px;
            }

            .title{
                font-size:28px;
            }
        }
    </style>
</head>
<body>

<?php if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] !== 'admin') { ?>

    <div class="welcome-box">
        <img src="logo.png" alt="Agri-Market Logo" class="welcome-logo">
        <h2>Welcome</h2>
        <p>Hello, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</p>
        <p>Role: <?php echo ucfirst(htmlspecialchars($_SESSION['role'])); ?></p>
        <p>You are now logged in to Agri-Market.</p>
        <a href="index.php?logout=1" class="logout-btn">Logout</a>
    </div>

<?php } else { ?>

    <?php if ($page == "login") { ?>
        <div class="main-window">

            <div class="logo-wrap">
                <img src="logo.png" alt="Agri-Market Logo" class="form-logo">
            </div>

            <div class="form-box">
                <div class="title">Login to Agri-Market</div>

                <div class="spacer-login"></div>

                <div class="message"><?php echo htmlspecialchars($login_message); ?></div>

                <form method="POST" action="index.php?page=login">
                    <input type="text" name="login_username" placeholder="Username">
                    <input type="password" name="login_password" placeholder="Password">
                    <button type="submit" name="login" class="btn">Login</button>
                </form>

                <div class="bottom-text">
                    Don't have an account?
                    <a href="index.php?page=register">Register here</a>
                </div>
            </div>
        </div>
    <?php } ?>

    <?php if ($page == "register") { ?>
        <div class="main-window">

            <div class="logo-wrap">
                <img src="logo.png" alt="Agri-Market Logo" class="form-logo">
            </div>

            <div class="form-box">
                <div class="title">Register an<br>Account</div>

                <div class="spacer-register"></div>

                <div class="message"><?php echo htmlspecialchars($register_message); ?></div>

                <form method="POST" action="index.php?page=register">
                    <input type="text" name="full_name" placeholder="Full Name" required>
                    <input type="text" name="username" placeholder="Username" required>
                    <input type="email" name="email" placeholder="Email" required>

                    <input type="password" name="password" placeholder="Password" required>
                    <input type="password" name="confirm_password" placeholder="Confirm Password" required>
                    <button type="submit" name="register" class="btn">Register</button>
                </form>

                <div class="bottom-text">
                    Already have an account?
                    <a href="index.php?page=login">Login here</a>
                </div>
            </div>
        </div>
    <?php } ?>

<?php } ?>

</body>
</html>
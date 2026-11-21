<?php
include 'db.php';

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    // Prepare statement to prevent SQL injection
    $stmt = $conn->prepare("SELECT id, username, password FROM admin WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        // Verify password (assumes you store hashed passwords using password_hash())
        // If storing plain text temporarily for testing, change to: if ($password === $row['password'])
        if (password_verify($password, $row['password'])) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $row['username'];
            header("Location: dashboard.php");
            exit();
        } else {
            $message = "Invalid password.";
        }
    } else {
        $message = "User not found.";
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>YHAN & KIEL Fresh Farm Egg - Admin Login</title>

    <link rel="stylesheet" href="style.css">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>

<body>

    <div class="background"></div>
    <div class="overlay"></div>
    <main class="login-container">
        <div class="login-card">
            <div class="logo">
                <div class="egg-logo">
                    <i class="fa-solid fa-egg"></i>
                </div>
            </div>
            
            <h1>YHAN & KIEL</h1>
            <h2>FRESH FARM EGG</h2>

            <p class="subtitle"> Automated Egg Size Sorting System</p>
            <div class="divider">
            </div>
            <h3>Admin Login</h3>

            <p class="welcome">
                Sign in to access the administrator dashboard
            </p>

            <form id="loginForm">

                <div class="input-group">
                    <label for="username">Username</label>

                    <div class="input-box">
                        <i class="fa-solid fa-user"></i>

                        <input
                            type="text"
                            id="username"
                            placeholder="Enter username"
                            autocomplete="username"
                            required
                        >
                    </div>
                </div>

                
                <div class="input-group">
                    <label for="password">Password</label>

                    <div class="input-box">
                        <i class="fa-solid fa-lock"></i>

                        <input
                            type="password"
                            id="password"
                            placeholder="Enter password"
                            autocomplete="current-password"
                            required
                        >

                        <button
                            type="button"
                            class="show-password"
                            id="togglePassword"
                            aria-label="Show password"
                        >
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="login-options">

                    <label class="remember">
                        <input type="checkbox" id="remember">
                        <span>Remember me</span>
                    </label>

                    <a href="#" id="forgotPassword">
                        Forgot Password?
                    </a>

                </div>

                <button type="submit" class="login-button">
                    <i class="fa-solid fa-right-to-bracket"></i>
                    Login
                </button>

                <div id="message" class="message"></div>

            </form>

            <div class="login-footer">
                <p>
                    <i class="fa-solid fa-shield-halved"></i>
                    Administrator Access Only
                </p>

                <p class="copyright">
                    © 2026 YHAN & KIEL Fresh Farm Egg
                </p>
            </div>

        </div>

    </main>

    <script src="script.js"></script>

</body>
</html>
<?php
session_start();

$credentials_file = '/home/riskverustech/.mysql_user';
if (file_exists($credentials_file)) {
    $credentials = file_get_contents($credentials_file);
    list($db_user, $db_pass) = explode(':', trim($credentials), 2);
} else {
    die("Error: MySQL credentials file not found.");
}

$db_host = 'localhost';
$db_name = 'riskverustech_risk';
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get the redirect URL from the query parameter, default to manage_users.php
$redirect = isset($_GET['redirect']) ? urldecode($_GET['redirect']) : 'manage_users.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $sql = "SELECT user_id, password, is_active, nickname, is_admin FROM users WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user && password_verify($password, $user['password']) && $user['is_active']) {
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['nickname'] = $user['nickname'] ?: $username;
        $_SESSION['is_admin'] = $user['is_admin'];
        header("Location: $redirect"); // Redirect to the original page
        exit;
    } else {
        $error = "Invalid username, password, or inactive account.";
    }
    $stmt->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Login</title>
</head>
<body>
    <h2>Login</h2>
    <?php if ($error) echo "<p style='color:red;'>$error</p>"; ?>
    <form method="POST">
        <label>Username: <input type="text" name="username" required></label><br>
        <label>Password: <input type="password" name="password" required></label><br>
        <input type="submit" value="Login">
    </form>
</body>
</html>

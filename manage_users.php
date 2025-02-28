<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

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

$sql = "SELECT username, is_admin FROM users WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$username = $user['username'];
$is_admin = $user['is_admin'];
$stmt->close();

$status_message = '';
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_username = $_POST['username'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $email = $_POST['email'] ?: null;
    $nickname = $_POST['nickname'] ?: null;
    $is_admin_form = isset($_POST['is_admin']) ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $user_id = $_POST['user_id'] ?: null;

    if ($user_id) {
        $sql = "UPDATE users SET username = ?, password = ?, email = ?, nickname = ?, is_admin = ?, is_active = ? WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssiii", $form_username, $password, $email, $nickname, $is_admin_form, $is_active, $user_id);
    } else {
        $sql = "INSERT INTO users (username, password, email, nickname, is_admin, is_active) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssii", $form_username, $password, $email, $nickname, $is_admin_form, $is_active);
    }

    if ($stmt->execute()) {
        $status_message = "User " . ($user_id ? "updated" : "added") . " successfully!";
    } else {
        $status_message = "Error: " . $conn->error;
    }
    $stmt->close();
} elseif (!$is_admin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $status_message = "Only admins can manage users.";
}

$edit_user = null;
if ($is_admin && isset($_GET['edit'])) {
    $user_id = $_GET['edit'];
    $sql = "SELECT * FROM users WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $edit_user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Users</title>
</head>
<body>
    <p>Logged in as: <?php echo htmlspecialchars($username); ?></p>
    <?php if ($status_message) echo "<p>$status_message</p>"; ?>

    <?php if ($is_admin) { ?>
        <h2><?php echo $edit_user ? "Edit User" : "Add User"; ?></h2>
        <form method="POST">
            <?php if ($edit_user) { ?>
                <input type="hidden" name="user_id" value="<?php echo $edit_user['user_id']; ?>">
            <?php } ?>
            <label>Username: <input type="text" name="username" value="<?php echo $edit_user['username'] ?? ''; ?>" required></label><br>
            <label>Password: <input type="password" name="password" required></label><br>
            <label>Email: <input type="email" name="email" value="<?php echo $edit_user['email'] ?? ''; ?>"></label><br>
            <label>Nickname: <input type="text" name="nickname" value="<?php echo $edit_user['nickname'] ?? ''; ?>"></label><br>
            <label>Admin? <input type="checkbox" name="is_admin" <?php echo ($edit_user && $edit_user['is_admin']) ? 'checked' : ''; ?>></label><br>
            <label>Active? <input type="checkbox" name="is_active" <?php echo ($edit_user && $edit_user['is_active']) || !$edit_user ? 'checked' : ''; ?>></label><br>
            <input type="submit" value="<?php echo $edit_user ? "Update" : "Add"; ?> User">
        </form>
    <?php } else { ?>
        <h2>User Management</h2>
        <p>Only admins can add or edit users.</p>
    <?php } ?>

    <h2>Current Users</h2>
    <?php
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    $result = $conn->query("SELECT * FROM users");
    if ($result->num_rows > 0) {
        echo "<table border='1'><tr><th>ID</th><th>Username</th><th>Nickname</th><th>Email</th><th>Admin</th><th>Active</th>";
        if ($is_admin) echo "<th>Action</th>";
        echo "</tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['user_id'] . "</td>";
            echo "<td>" . $row['username'] . "</td>";
            echo "<td>" . ($row['nickname'] ?: '-') . "</td>";
            echo "<td>" . $row['email'] . "</td>";
            echo "<td>" . ($row['is_admin'] ? 'Yes' : 'No') . "</td>";
            echo "<td>" . ($row['is_active'] ? 'Yes' : 'No') . "</td>";
            if ($is_admin) {
                echo "<td><a href='?edit=" . $row['user_id'] . "'>Edit</a></td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "No users yet.";
    }
    $conn->close();
    ?>
    <p><a href="logout.php">Logout</a></p>
</body>
</html>

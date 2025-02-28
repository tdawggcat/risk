<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    $current_url = urlencode("setup_game.php");
    header("Location: login.php?redirect=$current_url");
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

// Fetch active users (excluding the current user)
$active_users = [];
$result = $conn->query("SELECT user_id, username, nickname FROM users WHERE is_active = 1 AND user_id != {$_SESSION['user_id']}");
while ($row = $result->fetch_assoc()) {
    $active_users[] = $row;
}

// Fetch available colors from the colors table
$available_colors = [];
$colors_result = $conn->query("SELECT name, hex_code FROM colors ORDER BY name");
while ($color = $colors_result->fetch_assoc()) {
    $available_colors[] = $color;
}

if (empty($available_colors)) {
    die("Error: No colors defined in the colors table. Please ask an admin to add some.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Start a new game
    $conn->query("INSERT INTO games (current_turn) VALUES ({$_SESSION['user_id']})");
    $game_id = $conn->insert_id;

    // Add the creator to the game with their chosen hex code
    $creator_hex = $_POST['creator_color']; // Now a hex code
    $sql = "INSERT INTO game_players (game_id, user_id, color) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iis", $game_id, $_SESSION['user_id'], $creator_hex);
    $stmt->execute();

    // Track used hex codes to prevent duplicates
    $used_colors = [$creator_hex];

    // Add selected players with their chosen hex codes
    if (isset($_POST['players']) && is_array($_POST['players'])) {
        foreach ($_POST['players'] as $user_id) {
            $color_key = "player_color_$user_id";
            $hex_code = $_POST[$color_key];
            if (in_array($hex_code, $used_colors)) {
                die("Error: Color '$hex_code' is already used. Please choose a different color for each player.");
            }
            $stmt->bind_param("iis", $game_id, $user_id, $hex_code);
            $stmt->execute();
            $used_colors[] = $hex_code;
        }
    }

    // Insert default game settings
    $settings = [
        ['north_america_bonus', '5'],
        ['south_america_bonus', '2'],
        ['europe_bonus', '5'],
        ['africa_bonus', '3'],
        ['asia_bonus', '7'],
        ['australia_bonus', '2'],
        ['card_trade_bonus', '4']
    ];
    $stmt = $conn->prepare("INSERT INTO game_settings (game_id, setting_name, setting_value) VALUES (?, ?, ?)");
    foreach ($settings as $setting) {
        $stmt->bind_param("iss", $game_id, $setting[0], $setting[1]);
        $stmt->execute();
    }

    // Populate territories for the new game
    $territories = $conn->query("SELECT territory_id, name FROM territory_master");
    $stmt = $conn->prepare("INSERT INTO territories (game_id, name, master_territory_id) VALUES (?, ?, ?)");
    while ($territory = $territories->fetch_assoc()) {
        $stmt->bind_param("isi", $game_id, $territory['name'], $territory['territory_id']);
        $stmt->execute();
    }

    $stmt->close();
    header("Location: game.php?game_id=$game_id");
    exit;
}

$conn->close();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Setup New Game</title>
    <style>
        .color-preview {
            width: 20px;
            height: 20px;
            display: inline-block;
            vertical-align: middle;
            margin-right: 5px;
        }
    </style>
</head>
<body>
    <h2>Start a New Game</h2>
    <form method="POST">
        <label>Your Color:
            <select name="creator_color" required>
                <?php foreach ($available_colors as $color) { ?>
                    <option value="<?php echo htmlspecialchars($color['hex_code']); ?>">
                        <span class="color-preview" style="background-color: <?php echo $color['hex_code']; ?>;"></span>
                        <?php echo htmlspecialchars($color['name']); ?>
                    </option>
                <?php } ?>
            </select>
        </label><br>
        <h3>Add Players</h3>
        <?php if (empty($active_users)) { ?>
            <p>No other active users available to add.</p>
        <?php } else { ?>
            <?php foreach ($active_users as $user) { ?>
                <label>
                    <input type="checkbox" name="players[]" value="<?php echo $user['user_id']; ?>">
                    <?php echo htmlspecialchars($user['nickname'] ?: $user['username']); ?>
                    <select name="player_color_<?php echo $user['user_id']; ?>" required>
                        <?php foreach ($available_colors as $color) { ?>
                            <option value="<?php echo htmlspecialchars($color['hex_code']); ?>">
                                <span class="color-preview" style="background-color: <?php echo $color['hex_code']; ?>;"></span>
                                <?php echo htmlspecialchars($color['name']); ?>
                            </option>
                        <?php } ?>
                    </select>
                </label><br>
            <?php } ?>
        <?php } ?>
        <input type="submit" value="Create Game">
    </form>
    <p><a href="manage_users.php">Back to User Management</a></p>
</body>
</html>

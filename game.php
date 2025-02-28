<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    $current_url = urlencode("game.php" . (isset($_GET['game_id']) ? "?game_id={$_GET['game_id']}" : ""));
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

$game_id = $_GET['game_id'] ?? null;
if (!$game_id) {
    die("No game ID provided.");
}

// Fetch game and player info
$game = $conn->query("SELECT * FROM games WHERE game_id = $game_id")->fetch_assoc() or die("Failed to fetch game: " . $conn->error);
$players = [];
$result = $conn->query("SELECT gp.user_id, gp.color, gp.army_reserve, u.nickname, u.username 
                        FROM game_players gp 
                        JOIN users u ON gp.user_id = u.user_id 
                        WHERE gp.game_id = $game_id") or die("Failed to fetch players: " . $conn->error);
while ($row = $result->fetch_assoc()) {
    $players[$row['user_id']] = $row;
}
$player_count = count($players);

// Initial setup
$territory_count = $conn->query("SELECT COUNT(*) FROM territories WHERE game_id = $game_id AND owner_id IS NOT NULL")->fetch_row()[0];
if ($territory_count == 0) {
    $territories = $conn->query("SELECT territory_id FROM territories WHERE game_id = $game_id") or die("Failed to fetch territories: " . $conn->error);
    $territory_ids = [];
    while ($row = $territories->fetch_assoc()) {
        $territory_ids[] = $row['territory_id'];
    }
    shuffle($territory_ids);
    $player_ids = array_keys($players);
    $territories_per_player = floor(42 / $player_count);
    $extra_territories = 42 % $player_count;

    $stmt = $conn->prepare("UPDATE territories SET owner_id = ?, armies = 1 WHERE territory_id = ?") or die("Prepare failed: " . $conn->error);
    $territory_index = 0;
    foreach ($player_ids as $index => $user_id) {
        $num_territories = $territories_per_player + ($index < $extra_territories ? 1 : 0);
        for ($i = 0; $i < $num_territories; $i++) {
            if ($territory_index < count($territory_ids)) {
                $territory_id = $territory_ids[$territory_index++];
                $stmt->bind_param("ii", $user_id, $territory_id);
                $stmt->execute() or die("Execute failed: " . $conn->error);
            }
        }
    }

    $initial_armies = [2 => 40, 3 => 35, 4 => 30, 5 => 25, 6 => 20];
    $army_count = $initial_armies[$player_count] ?? 20;
    $stmt = $conn->prepare("UPDATE game_players SET army_reserve = ? WHERE game_id = ? AND user_id = ?") or die("Prepare failed: " . $conn->error);
    foreach ($player_ids as $user_id) {
        $stmt->bind_param("iii", $army_count, $game_id, $user_id);
        $stmt->execute() or die("Execute failed: " . $conn->error);
    }

    foreach ($player_ids as $user_id) {
        $player_territories = $conn->query("SELECT territory_id, name FROM territories WHERE game_id = $game_id AND owner_id = $user_id") or die("Player territories query failed: " . $conn->error);
        $territory_ids = [];
        while ($row = $player_territories->fetch_assoc()) {
            $territory_ids[] = $row;
        }
        $num_territories = count($territory_ids);
        $base_armies = floor($army_count / $num_territories);
        $extra_armies = $army_count % $num_territories;

        $stmt = $conn->prepare("UPDATE territories SET armies = ? WHERE territory_id = ?") or die("Prepare failed: " . $conn->error);
        foreach ($territory_ids as $index => $territory) {
            $armies = $base_armies + ($index < $extra_armies ? 1 : 0);
            $stmt->bind_param("ii", $armies, $territory['territory_id']);
            $stmt->execute() or die("Execute failed: " . $conn->error);
            $action = "{$players[$user_id]['nickname']} placed $armies armies in {$territory['name']} during setup.";
            $conn->query("INSERT INTO game_log (game_id, user_id, action) VALUES ($game_id, $user_id, '$action')");
        }
        $conn->query("UPDATE game_players SET army_reserve = 0 WHERE game_id = $game_id AND user_id = $user_id") or die("Reserve reset failed: " . $conn->error);
    }

    $player_ids = array_diff($player_ids, [$_SESSION['user_id']]);
    shuffle($player_ids);
    $turn_order_array = array_merge([$_SESSION['user_id']], $player_ids);
    $turn_order = implode(',', $turn_order_array);
    $stmt = $conn->prepare("UPDATE games SET phase = 'reinforce', current_turn = ?, turn_order = ?, conquest_pending = 0 WHERE game_id = ?");
    $stmt->bind_param("isi", $_SESSION['user_id'], $turn_order, $game_id);
    $stmt->execute() or die("Failed to set turn order: " . $conn->error);

    $game = $conn->query("SELECT * FROM games WHERE game_id = $game_id")->fetch_assoc();
    $players = [];
    $result = $conn->query("SELECT gp.user_id, gp.color, gp.army_reserve, u.nickname, u.username 
                            FROM game_players gp 
                            JOIN users u ON gp.user_id = u.user_id 
                            WHERE gp.game_id = $game_id") or die("Failed to fetch players post-setup: " . $conn->error);
    while ($row = $result->fetch_assoc()) {
        $players[$row['user_id']] = $row;
    }
}

// Initialize status message
$status_message = "";

// Calculate stats for all players
$player_stats = [];
$continent_bonuses = [
    'North America' => ['Alaska', 'Alberta', 'Central America', 'Eastern United States', 'Greenland', 'Northwest Territory', 'Ontario', 'Quebec', 'Western United States'],
    'South America' => ['Argentina', 'Brazil', 'Peru', 'Venezuela'],
    'Europe' => ['Great Britain', 'Iceland', 'Northern Europe', 'Scandinavia', 'Southern Europe', 'Ukraine', 'Western Europe'],
    'Africa' => ['Congo', 'East Africa', 'Egypt', 'Madagascar', 'North Africa', 'South Africa'],
    'Asia' => ['Afghanistan', 'China', 'India', 'Irkutsk', 'Japan', 'Kamchatka', 'Middle East', 'Mongolia', 'Siam', 'Siberia', 'Ural', 'Yakutsk'],
    'Australia' => ['Eastern Australia', 'Indonesia', 'New Guinea', 'Western Australia']
];
$settings = $conn->query("SELECT setting_name, setting_value FROM game_settings WHERE game_id = $game_id");
$bonus_values = [];
while ($row = $settings->fetch_assoc()) {
    $bonus_values[$row['setting_name']] = (int)$row['setting_value'];
}

foreach ($players as $user_id => $player) {
    $territories_held = $conn->query("SELECT COUNT(*) FROM territories WHERE game_id = $game_id AND owner_id = $user_id")->fetch_row()[0];
    $total_armies = $conn->query("SELECT SUM(armies) FROM territories WHERE game_id = $game_id AND owner_id = $user_id")->fetch_row()[0];
    $territory_bonus = max(3, floor($territories_held / 3));
    $continent_bonus = 0;
    $continent_breakdown = [];
    
    foreach ($continent_bonuses as $continent => $territories) {
        $owned = $conn->query("SELECT COUNT(*) FROM territories WHERE game_id = $game_id AND owner_id = $user_id AND name IN ('" . implode("','", $territories) . "')")->fetch_row()[0];
        if ($owned == count($territories)) {
            $bonus_key = strtolower(str_replace(' ', '_', $continent)) . '_bonus';
            $bonus = $bonus_values[$bonus_key] ?? 0;
            $continent_bonus += $bonus;
            $continent_breakdown[] = "$continent: $bonus";
        }
    }
    $income = $territory_bonus + $continent_bonus;
    $income_tooltip = "Territories: $territory_bonus, Continents: $continent_bonus" . (!empty($continent_breakdown) ? " (" . implode(', ', $continent_breakdown) . ")" : "");
    
    $player_stats[$user_id] = [
        'nickname' => $player['nickname'] ?: $player['username'],
        'territories' => $territories_held,
        'armies' => $total_armies,
        'income' => $income,
        'income_tooltip' => $income_tooltip,
        'color' => $player['color']
    ];
}

// Handle reinforcement
if ($game['phase'] == 'reinforce' && $game['current_turn'] == $_SESSION['user_id']) {
    $territory_bonus = $player_stats[$_SESSION['user_id']]['income'];
    $current_reserve = $players[$_SESSION['user_id']]['army_reserve'];

    if ($current_reserve == 0) {
        $conn->query("UPDATE game_players SET army_reserve = $territory_bonus WHERE game_id = $game_id AND user_id = {$_SESSION['user_id']}");
        $players[$_SESSION['user_id']]['army_reserve'] = $territory_bonus;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $territory_id = $_POST['territory'];
        $armies_to_place = (int)$_POST['armies'];

        $territory = $conn->query("SELECT owner_id, armies, name FROM territories WHERE territory_id = $territory_id")->fetch_assoc();
        $army_reserve = $players[$_SESSION['user_id']]['army_reserve'];
        if ($territory['owner_id'] == $_SESSION['user_id'] && $armies_to_place > 0 && $armies_to_place <= $army_reserve) {
            $new_armies = $territory['armies'] + $armies_to_place;
            $new_reserve = $army_reserve - $armies_to_place;
            $conn->query("UPDATE territories SET armies = $new_armies WHERE territory_id = $territory_id");
            $conn->query("UPDATE game_players SET army_reserve = $new_reserve WHERE game_id = $game_id AND user_id = {$_SESSION['user_id']}");
            $status_message = "Placed $armies_to_place armies.";
            $action = "{$players[$_SESSION['user_id']]['nickname']} placed $armies_to_place armies in {$territory['name']}.";
            $conn->query("INSERT INTO game_log (game_id, user_id, action) VALUES ($game_id, {$_SESSION['user_id']}, '$action')");

            if ($new_reserve == 0) {
                $turn_order = explode(',', $game['turn_order']);
                $current_index = array_search($game['current_turn'], $turn_order);
                $next_index = ($current_index + 1) % count($turn_order);
                $next_turn = $turn_order[$next_index];
                if ($game['current_turn'] == $_SESSION['user_id']) {
                    $conn->query("UPDATE games SET phase = 'attack', current_turn = {$_SESSION['user_id']} WHERE game_id = $game_id");
                } else {
                    $conn->query("UPDATE games SET current_turn = $next_turn WHERE game_id = $game_id");
                    $action = "{$players[$_SESSION['user_id']]['nickname']} ended turn.";
                    $conn->query("INSERT INTO game_log (game_id, user_id, action) VALUES ($game_id, {$_SESSION['user_id']}, '$action')");
                }
                $players[$_SESSION['user_id']]['army_reserve'] = $new_reserve;
                $game = $conn->query("SELECT * FROM games WHERE game_id = $game_id")->fetch_assoc();
            }
        } else {
            $status_message = "Invalid placement.";
        }
    }
}

// Handle attack
$from_territory_id = $_GET['from'] ?? null;
$last_to_id = $_GET['last_to'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $game['phase'] == 'attack' && $game['current_turn'] == $_SESSION['user_id']) {
    if (isset($_POST['attack'])) {
        $from_id = $_POST['from'];
        $to_id = $_POST['to'];
        $attack_dice = min(3, (int)$_POST['dice']);

        $from = $conn->query("SELECT name, owner_id, armies, master_territory_id FROM territories WHERE territory_id = $from_id")->fetch_assoc();
        $to = $conn->query("SELECT name, owner_id, armies, master_territory_id FROM territories WHERE territory_id = $to_id")->fetch_assoc();

        $is_adjacent = $conn->query("SELECT COUNT(*) 
                                     FROM territory_adjacencies 
                                     WHERE territory_id = {$from['master_territory_id']} AND adjacent_id = {$to['master_territory_id']}")->fetch_row()[0] > 0;

        if ($from['owner_id'] == $_SESSION['user_id'] && $from['armies'] > 1 && $to['owner_id'] != $_SESSION['user_id'] && $is_adjacent) {
            $defend_dice = min(2, $to['armies']);

            $attack_rolls = array_map(function() { return rand(1, 6); }, range(1, $attack_dice));
            $defend_rolls = array_map(function() { return rand(1, 6); }, range(1, $defend_dice));
            rsort($attack_rolls);
            rsort($defend_rolls);

            $battles = min($attack_dice, $defend_dice);
            $attack_losses = 0;
            $defend_losses = 0;
            for ($i = 0; $i < $battles; $i++) {
                if ($attack_rolls[$i] > $defend_rolls[$i]) {
                    $defend_losses++;
                } else {
                    $attack_losses++;
                }
            }
            $new_from_armies = $from['armies'] - $attack_losses;
            $new_to_armies = $to['armies'] - $defend_losses;
            $conn->query("UPDATE territories SET armies = $new_from_armies WHERE territory_id = $from_id");
            $conn->query("UPDATE territories SET armies = $new_to_armies WHERE territory_id = $to_id");
            $status_message = "Attacked {$to['name']} from {$from['name']}: You rolled " . implode(', ', $attack_rolls) . 
                              ", they rolled " . implode(', ', $defend_rolls) . ". You lost $attack_losses, they lost $defend_losses.";
            $action = "{$players[$_SESSION['user_id']]['nickname']} attacked {$to['name']} from {$from['name']}, rolled a " . implode(' ', $attack_rolls) . " against a " . implode(' ', $defend_rolls) . ".";
            $conn->query("INSERT INTO game_log (game_id, user_id, action) VALUES ($game_id, {$_SESSION['user_id']}, '$action')");

            if ($new_to_armies <= 0) {
                $from_territory_id = $from_id;
                $stmt = $conn->prepare("UPDATE games SET conquest_pending = 1, conquest_from_id = ?, conquest_to_id = ?, conquest_dice = ? WHERE game_id = ?");
                $stmt->bind_param("iiii", $from_id, $to_id, $attack_dice, $game_id);
                $stmt->execute();
                $last_to_id = $to_id;
            } else {
                $from_territory_id = $from_id;
                $last_to_id = $to_id;
            }
        } else {
            $status_message = "Invalid attack.";
            $from_territory_id = $from_id;
            $last_to_id = $to_id;
        }
    } elseif (isset($_POST['submit_move']) && $game['conquest_pending']) {
        $from_id = (int)$game['conquest_from_id'];
        $to_id = (int)$game['conquest_to_id'];
        $dice_rolled = (int)$game['conquest_dice'];
        $move_armies = (int)($_POST['move_armies'] ?? 0);

        $from = $conn->query("SELECT name, armies FROM territories WHERE territory_id = $from_id AND game_id = $game_id")->fetch_assoc() or die("Failed to fetch from territory: " . $conn->error);
        $to = $conn->query("SELECT name FROM territories WHERE territory_id = $to_id AND game_id = $game_id")->fetch_assoc() or die("Failed to fetch to territory: " . $conn->error);
        $max_move = $from['armies'] - 1;

        if ($move_armies >= $dice_rolled && $move_armies <= $max_move && $move_armies > 0) {
            $conn->query("UPDATE territories SET owner_id = {$_SESSION['user_id']}, armies = $move_armies WHERE territory_id = $to_id");
            $conn->query("UPDATE territories SET armies = " . ($from['armies'] - $move_armies) . " WHERE territory_id = $from_id");
            $status_message = "Conquered {$to['name']} and moved $move_armies armies from {$from['name']} to {$to['name']}.";
            $action = "{$players[$_SESSION['user_id']]['nickname']} conquered {$to['name']}, moved $move_armies armies in.";
            $conn->query("INSERT INTO game_log (game_id, user_id, action) VALUES ($game_id, {$_SESSION['user_id']}, '$action')");
            $conn->query("UPDATE games SET conquest_pending = 0, conquest_from_id = NULL, conquest_to_id = NULL, conquest_dice = NULL WHERE game_id = $game_id");
            header("Location: game.php?game_id=$game_id");
            exit;
        } else {
            $status_message = "Failed to move armies: Must be between $dice_rolled and $max_move.";
            $from_territory_id = $from_id;
        }
    } elseif (isset($_POST['change_from']) || isset($_POST['end_attack'])) {
        if (isset($_POST['end_attack'])) {
            $turn_order = explode(',', $game['turn_order']);
            $current_index = array_search($game['current_turn'], $turn_order);
            $next_index = ($current_index + 1) % count($turn_order);
            $next_turn = $turn_order[$next_index];
            $conn->query("UPDATE games SET current_turn = $next_turn, conquest_pending = 0, conquest_from_id = NULL, conquest_to_id = NULL, conquest_dice = NULL WHERE game_id = $game_id");
            $status_message = "Attack phase ended.";
            $action = "{$players[$_SESSION['user_id']]['nickname']} ended turn.";
            $conn->query("INSERT INTO game_log (game_id, user_id, action) VALUES ($game_id, {$_SESSION['user_id']}, '$action')");
        }
        $from_territory_id = null;
    }
}

// Fetch current state
$territories = $conn->query("SELECT t.territory_id, t.name, t.owner_id, t.armies, u.nickname, u.username, gp.color 
                             FROM territories t 
                             LEFT JOIN users u ON t.owner_id = u.user_id 
                             LEFT JOIN game_players gp ON t.owner_id = gp.user_id AND gp.game_id = $game_id 
                             WHERE t.game_id = $game_id 
                             ORDER BY t.name") or die("Territories fetch failed: " . $conn->error);
$game = $conn->query("SELECT * FROM games WHERE game_id = $game_id")->fetch_assoc() or die("Game fetch failed: " . $conn->error);
$reserve_result = $conn->query("SELECT army_reserve FROM game_players WHERE game_id = $game_id AND user_id = {$_SESSION['user_id']}");
if ($reserve_result && $reserve_row = $reserve_result->fetch_row()) {
    $players[$_SESSION['user_id']]['army_reserve'] = $reserve_row[0];
} else {
    $players[$_SESSION['user_id']]['army_reserve'] = 0;
}

// Organize territories by continent
$territory_by_continent = [];
foreach ($continent_bonuses as $continent => $terr_list) {
    $territory_by_continent[$continent] = [];
}
while ($row = $territories->fetch_assoc()) {
    foreach ($continent_bonuses as $continent => $terr_list) {
        if (in_array($row['name'], $terr_list)) {
            $territory_by_continent[$continent][] = $row;
            break;
        }
    }
}
foreach ($territory_by_continent as $continent => &$terr_list) {
    usort($terr_list, function($a, $b) {
        return strcmp($a['name'], $b['name']);
    });
}
unset($terr_list);
ksort($territory_by_continent);

// Fetch game log
$game_log = $conn->query("SELECT timestamp, action FROM game_log WHERE game_id = $game_id ORDER BY timestamp ASC") or die("Game log fetch failed: " . $conn->error);

?>

<!DOCTYPE html>
<html>
<head>
    <title>Risk Game - Game #<?php echo $game_id; ?></title>
    <style>
        table.stats, table.territories {
            border-collapse: collapse;
            width: auto;
            margin-bottom: 20px;
        }
        table.stats th, table.stats td, table.territories th, table.territories td {
            border: 1px solid black;
            padding: 8px;
            text-align: center;
        }
        table.stats th, table.territories th {
            background-color: #f2f2f2;
        }
        h4 {
            margin-top: 20px;
            margin-bottom: 5px;
        }
        #game-log {
            margin-top: 20px;
            padding: 10px;
            border: 1px solid #ccc;
            background-color: #f9f9f9;
            max-height: 200px;
            overflow-y: auto;
        }
        #game-log p {
            margin: 5px 0;
        }
    </style>
</head>
<body>
    <h2>Game #<?php echo $game_id; ?> - <?php echo $game['phase']; ?> Phase</h2>
    <p>Current turn: <?php echo $players[$game['current_turn']]['nickname'] ?: $players[$game['current_turn']]['username']; ?></p>
    <p>Your nickname: <?php echo $_SESSION['nickname']; ?></p>
    <?php if ($status_message) echo "<p>$status_message</p>"; ?>

    <!-- Player Stats Table -->
    <h3>Player Stats</h3>
    <table class="stats">
        <tr>
            <th>Player</th>
            <th>Territories</th>
            <th>Armies</th>
            <th>Income</th>
        </tr>
        <?php
        $turn_order = explode(',', $game['turn_order']);
        foreach ($turn_order as $user_id) {
            if (isset($player_stats[$user_id])) {
                $stats = $player_stats[$user_id];
        ?>
            <tr style="background-color: <?php echo $stats['color'] ?: '#FFFFFF'; ?>;">
                <td><?php echo htmlspecialchars($stats['nickname']); ?></td>
                <td><?php echo $stats['territories']; ?></td>
                <td><?php echo $stats['armies']; ?></td>
                <td title="<?php echo htmlspecialchars($stats['income_tooltip']); ?>"><?php echo $stats['income']; ?></td>
            </tr>
        <?php
            }
        }
        ?>
    </table>

    <?php if ($game['phase'] == 'reinforce' && $game['current_turn'] == $_SESSION['user_id']) { ?>
        <h3>Reinforce (Armies to place: <?php echo $players[$_SESSION['user_id']]['army_reserve']; ?>)</h3>
        <form method="POST">
            <label>Territory:
                <select name="territory" required>
                    <?php
                    $territories->data_seek(0);
                    while ($row = $territories->fetch_assoc()) {
                        if ($row['owner_id'] == $_SESSION['user_id']) {
                            echo "<option value='{$row['territory_id']}'>{$row['name']} ({$row['armies']} armies)</option>";
                        }
                    }
                    ?>
                </select>
            </label><br>
            <label>Armies: <input type="number" name="armies" min="1" max="<?php echo $players[$_SESSION['user_id']]['army_reserve']; ?>" value="<?php echo $players[$_SESSION['user_id']]['army_reserve']; ?>" required></label><br>
            <input type="submit" value="Place Armies">
        </form>
    <?php } elseif ($game['phase'] == 'reinforce') { ?>
        <p>Waiting for <?php echo $players[$game['current_turn']]['nickname'] ?: $players[$game['current_turn']]['username']; ?> to reinforce.</p>
    <?php } elseif ($game['phase'] == 'attack' && $game['current_turn'] == $_SESSION['user_id']) { ?>
        <h3>Attack</h3>
        <?php if ($game['conquest_pending']) { ?>
            <?php
            $from_data = $conn->query("SELECT name, armies FROM territories WHERE territory_id = {$game['conquest_from_id']} AND game_id = $game_id")->fetch_assoc();
            $to_data = $conn->query("SELECT name FROM territories WHERE territory_id = {$game['conquest_to_id']} AND game_id = $game_id")->fetch_assoc();
            $min_move = $game['conquest_dice'];
            $max_move = $from_data['armies'] - 1;
            ?>
            <p>You conquered <?php echo $to_data['name']; ?> from <?php echo $from_data['name']; ?>!</p>
            <form method="POST">
                <input type="hidden" name="from" value="<?php echo $game['conquest_from_id']; ?>">
                <input type="hidden" name="to" value="<?php echo $game['conquest_to_id']; ?>">
                <label>Move armies to <?php echo $to_data['name']; ?> (<?php echo $min_move; ?> to <?php echo $max_move; ?>):
                    <input type="number" name="move_armies" min="<?php echo $min_move; ?>" max="<?php echo $max_move; ?>" value="<?php echo $min_move; ?>" required>
                </label><br>
                <input type="submit" name="submit_move" value="Move Armies">
            </form>
        <?php } elseif (!$from_territory_id) { ?>
            <form method="GET" action="game.php">
                <input type="hidden" name="game_id" value="<?php echo $game_id; ?>">
                <label>Attack from:
                    <select name="from" required>
                        <?php
                        $territories->data_seek(0);
                        while ($row = $territories->fetch_assoc()) {
                            if ($row['owner_id'] == $_SESSION['user_id'] && $row['armies'] > 1) {
                                echo "<option value='{$row['territory_id']}'>{$row['name']} ({$row['armies']} armies)</option>";
                            }
                        }
                        ?>
                    </select>
                </label><br>
                <input type="submit" value="Select Territory">
            </form>
            <form method="POST">
                <input type="submit" name="end_attack" value="End Attack Phase">
            </form>
        <?php } else { ?>
            <?php
            $from_data = $conn->query("SELECT name, armies, master_territory_id FROM territories WHERE territory_id = $from_territory_id AND game_id = $game_id")->fetch_assoc();
            if ($from_data) {
                $adjacent_enemies = $conn->query("SELECT t.territory_id, t.name, t.armies, u.nickname, u.username 
                                                  FROM territories t 
                                                  LEFT JOIN users u ON t.owner_id = u.user_id 
                                                  JOIN territory_adjacencies ta ON t.master_territory_id = ta.adjacent_id 
                                                  WHERE t.game_id = $game_id AND t.owner_id != {$_SESSION['user_id']} 
                                                  AND ta.territory_id = {$from_data['master_territory_id']}");
                $enemy_count = $adjacent_enemies->num_rows;
            } else {
                $enemy_count = 0;
            }
            ?>
            <?php if ($from_data) { ?>
                <form method="POST">
                    <p>Attacking from: <?php echo $from_data['name'] . " ({$from_data['armies']} armies)"; ?></p>
                    <input type="hidden" name="from" value="<?php echo $from_territory_id; ?>">
                    <label>Attack to:
                        <select name="to" required>
                            <?php
                            if ($enemy_count > 0) {
                                while ($row = $adjacent_enemies->fetch_assoc()) {
                                    $selected = ($row['territory_id'] == $last_to_id) ? ' selected' : '';
                                    echo "<option value='{$row['territory_id']}'$selected>{$row['name']} ({$row['armies']} armies, " . ($row['nickname'] ?: $row['username']) . ")</option>";
                                }
                            } else {
                                echo "<option value=''>No adjacent enemy territories</option>";
                            }
                            ?>
                        </select>
                    </label><br>
                    <?php if ($enemy_count > 0) { ?>
                        <label>Dice (max 3): <input type="number" name="dice" min="1" max="3" value="<?php echo min(3, $from_data['armies'] - 1); ?>" required></label><br>
                        <input type="submit" name="attack" value="Attack">
                    <?php } ?>
                    <input type="submit" name="change_from" value="Change Territory">
                    <input type="submit" name="end_attack" value="End Attack Phase">
                </form>
                <?php if ($enemy_count == 0) echo "<p>No adjacent enemies to attack. Change territory or end your attack phase.</p>"; ?>
            <?php } else { ?>
                <p>Error: Could not find selected territory (ID: <?php echo $from_territory_id; ?>).</p>
                <form method="POST">
                    <input type="submit" name="end_attack" value="End Attack Phase">
                </form>
            <?php } ?>
        <?php } ?>
    <?php } elseif ($game['phase'] == 'attack') { ?>
        <p>Waiting for <?php echo $players[$game['current_turn']]['nickname'] ?: $players[$game['current_turn']]['username']; ?> to attack.</p>
    <?php } else { ?>
        <p>Fortify phase coming soon!</p>
    <?php } ?>

    <h3>Territories</h3>
    <?php foreach ($territory_by_continent as $continent => $terr_list) { ?>
        <h4><?php echo $continent; ?></h4>
        <table class="territories">
            <tr>
                <th>Name</th>
                <th>Owner</th>
                <th>Armies</th>
            </tr>
            <?php foreach ($terr_list as $row) { ?>
                <tr style="background-color: <?php echo $row['color'] ?: '#FFFFFF'; ?>;">
                    <td><?php echo $row['name']; ?></td>
                    <td><?php echo $row['nickname'] ?: $row['username'] ?: 'Unclaimed'; ?></td>
                    <td><?php echo $row['armies']; ?></td>
                </tr>
            <?php } ?>
        </table>
    <?php } ?>

    <!-- Game Log -->
    <div id="game-log">
        <h3>Game Log</h3>
        <?php while ($log_entry = $game_log->fetch_assoc()) { ?>
            <p><?php echo date('m/d/Y H:i:s', strtotime($log_entry['timestamp'])) . ' ' . htmlspecialchars($log_entry['action']); ?></p>
        <?php } ?>
    </div>

    <p><a href="manage_users.php">Back to User Management</a></p>
</body>
</html>
<?php $conn->close(); ?>

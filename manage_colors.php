<?php
session_start();
if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    $current_url = urlencode("manage_colors.php");
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

// Handle form submissions
$status_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add'])) {
        $name = trim($_POST['name']);
        $hex_code = trim($_POST['hex_code']);
        if (!empty($name) && preg_match('/^#[0-9A-Fa-f]{6}$/', $hex_code)) {
            $stmt = $conn->prepare("INSERT INTO colors (name, hex_code) VALUES (?, ?)");
            $stmt->bind_param("ss", $name, $hex_code);
            if ($stmt->execute()) {
                $status_message = "Color '$name' added successfully.";
            } else {
                $status_message = "Error adding color: " . $conn->error;
            }
            $stmt->close();
        } else {
            $status_message = "Invalid color name or hex code.";
        }
    } elseif (isset($_POST['edit'])) {
        $color_id = (int)$_POST['color_id'];
        $name = trim($_POST['name']);
        $hex_code = trim($_POST['hex_code']);
        if (!empty($name) && preg_match('/^#[0-9A-Fa-f]{6}$/', $hex_code)) {
            $stmt = $conn->prepare("UPDATE colors SET name = ?, hex_code = ? WHERE color_id = ?");
            $stmt->bind_param("ssi", $name, $hex_code, $color_id);
            if ($stmt->execute()) {
                $status_message = "Color updated successfully.";
            } else {
                $status_message = "Error updating color: " . $conn->error;
            }
            $stmt->close();
        } else {
            $status_message = "Invalid color name or hex code.";
        }
    } elseif (isset($_POST['delete'])) {
        $color_id = (int)$_POST['color_id'];
        $stmt = $conn->prepare("DELETE FROM colors WHERE color_id = ?");
        $stmt->bind_param("i", $color_id);
        if ($stmt->execute()) {
            $status_message = "Color deleted successfully.";
        } else {
            $status_message = "Error deleting color: " . $conn->error;
        }
        $stmt->close();
    }
}

// Fetch all colors
$colors = $conn->query("SELECT * FROM colors ORDER BY name");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Colors</title>
    <style>
        table {
            border-collapse: collapse;
            width: 80%;
            margin: 20px auto;
        }
        th, td {
            border: 1px solid #ccc;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
        }
        .color-preview {
            width: 20px;
            height: 20px;
            display: inline-block;
            vertical-align: middle;
            margin-right: 5px;
        }
        .editable:hover {
            background-color: #f9f9f9;
            cursor: pointer;
        }
    </style>
    <script>
        function editRow(colorId, name, hexCode) {
            document.getElementById('edit_color_id').value = colorId;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_hex_code').value = hexCode;
            document.getElementById('edit_color_picker').value = hexCode;
            document.getElementById('edit_form').style.display = 'block';
            document.getElementById('add_form').style.display = 'none';
        }

        function updateHexCode(pickerId, hexId) {
            const picker = document.getElementById(pickerId);
            const hexField = document.getElementById(hexId);
            hexField.value = picker.value;
        }
    </script>
</head>
<body>
    <h2>Manage Colors</h2>
    <p><a href="manage_users.php">Back to User Management</a></p>
    <?php if ($status_message) echo "<p>$status_message</p>"; ?>

    <table>
        <tr>
            <th>Color</th>
            <th>Name</th>
            <th>Hex Code</th>
            <th>Action</th>
        </tr>
        <?php while ($color = $colors->fetch_assoc()) { ?>
            <tr class="editable" onclick="editRow(<?php echo $color['color_id']; ?>, '<?php echo htmlspecialchars($color['name']); ?>', '<?php echo $color['hex_code']; ?>')">
                <td><span class="color-preview" style="background-color: <?php echo $color['hex_code']; ?>;"></span></td>
                <td><?php echo htmlspecialchars($color['name']); ?></td>
                <td><?php echo $color['hex_code']; ?></td>
                <td>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="color_id" value="<?php echo $color['color_id']; ?>">
                        <input type="submit" name="delete" value="Delete" onclick="return confirm('Are you sure?');">
                    </form>
                </td>
            </tr>
        <?php } ?>
    </table>

    <h3>Add New Color</h3>
    <form id="add_form" method="POST">
        <label>Name: <input type="text" name="name" required></label><br>
        <label>Hex Code: 
            <input type="text" id="add_hex_code" name="hex_code" pattern="#[0-9A-Fa-f]{6}" value="#000000" required>
            <input type="color" id="add_color_picker" value="#000000" onchange="updateHexCode('add_color_picker', 'add_hex_code')">
        </label><br>
        <input type="submit" name="add" value="Add Color">
    </form>

    <h3>Edit Color</h3>
    <form id="edit_form" method="POST" style="display:none;">
        <input type="hidden" id="edit_color_id" name="color_id">
        <label>Name: <input type="text" id="edit_name" name="name" required></label><br>
        <label>Hex Code: 
            <input type="text" id="edit_hex_code" name="hex_code" pattern="#[0-9A-Fa-f]{6}" required>
            <input type="color" id="edit_color_picker" onchange="updateHexCode('edit_color_picker', 'edit_hex_code')">
        </label><br>
        <input type="submit" name="edit" value="Save Changes">
    </form>

</body>
</html>
<?php $conn->close(); ?>

<?php
require 'auth.php';
requireLogin();
$uuid = $_GET['event'] ?? '';
checkEventAccess($conn, $uuid);
$eventName = getEventOrDie($conn, $uuid);

$dir = "uploads/" . $uuid;
$images = is_dir($dir) ? array_diff(scandir($dir), ['.', '..', 'drinks']) : [];
$pageTitle = "Galereie - " . $eventName;
require 'header.php';
?>
<div class="container">
    <div class="flex-between">
        <h1><?php echo htmlspecialchars($eventName); ?></h1>
        <a href="admin.php" class="btn btn-secondary btn-small">🔙 Dashboard</a>
    </div>
    <div class="grid" style="grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); margin-top:20px;">
        <?php foreach ($images as $img):
            $ext = pathinfo($img, PATHINFO_EXTENSION);
            if (in_array(strtolower($ext), ['jpg', 'jpeg', 'png'])):
        ?>
                <a href="<?php echo "$dir/$img"; ?>" target="_blank">
                    <img src="<?php echo "$dir/$img"; ?>" class="img-responsive" style="height:150px; object-fit:cover;">
                </a>
        <?php endif;
        endforeach; ?>
    </div>
</div>
</body>

</html>
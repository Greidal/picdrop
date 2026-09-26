<?php
// Event names and uploader names used to be stored HTML-encoded. They are now
// stored raw and escaped on output, so decode the existing rows once.
return static function (mysqli $conn): void {
    $targets = [
        ['events', 'uuid', 'name'],
        ['uploads', 'id', 'uploader_name'],
    ];

    foreach ($targets as [$table, $key, $column]) {
        $rows = $conn->query("SELECT `$key`, `$column` FROM `$table` WHERE `$column` LIKE '%&%;%'");
        $update = $conn->prepare("UPDATE `$table` SET `$column` = ? WHERE `$key` = ?");
        foreach ($rows as $row) {
            $decoded = html_entity_decode($row[$column], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded !== $row[$column]) {
                $update->bind_param("ss", $decoded, $row[$key]);
                $update->execute();
            }
        }
    }
};

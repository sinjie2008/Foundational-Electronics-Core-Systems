<?php
header('Content-Type: application/json');
require_once '../db_connect.php';

$mysqli = getDbConnection();

$rootId = isset($_GET['root_id']) ? (int)$_GET['root_id'] : 0;

// Fetch child categories of the selected root
// We assume these are "Product Categories"
// If you have another level of grouping, you might need a recursive query or a join.
// For now, let's assume direct children are the groups, and their children are the categories?
// OR, as per the spec, "Product Category (grouped labels + checkboxes)".
// If the DB structure is Root -> Group -> Category, we need to fetch that.

// Let's try to fetch children of root ($rootId)
// If the structure is Root -> Category, we just return them.
// If the structure is Root -> Group -> Category, we need to handle that.

// Based on the mock data:
// Group: "EMC Components" -> Categories: "Ferrite Chip Bead", etc.
// This implies Root -> Group -> Category.

$data = [];

// 1. Fetch Groups (children of root)
$sqlGroups = "SELECT id, name FROM category WHERE parent_id = ? AND type = 'category' ORDER BY display_order ASC";
$stmtGroups = $mysqli->prepare($sqlGroups);
$stmtGroups->bind_param('i', $rootId);
$stmtGroups->execute();
$resultGroups = $stmtGroups->get_result();

while ($group = $resultGroups->fetch_assoc()) {
    $groupId = $group['id'];
    $groupName = $group['name'];

    // 2. Fetch Categories (children of group)
    $sqlCats = "SELECT id, name FROM category WHERE parent_id = ? AND type = 'category' ORDER BY display_order ASC";
    $stmtCats = $mysqli->prepare($sqlCats);
    $stmtCats->bind_param('i', $groupId);
    $stmtCats->execute();
    $resultCats = $stmtCats->get_result();

    $categories = [];
    while ($cat = $resultCats->fetch_assoc()) {
        $categories[] = [
            'id' => $cat['id'],
            'name' => $cat['name']
        ];
    }

    // Only add if it has categories (or if you want to show empty groups too)
    if (!empty($categories)) {
        $data[] = [
            'group' => $groupName,
            'categories' => $categories
        ];
    } else {
        // Fallback: If the group itself is meant to be a category (flat structure),
        // or if the root directly contains categories without groups.
        // If the root's children have NO children, maybe the root's children ARE the categories?
        // Let's handle the case where the "Group" is actually the category if it has no children?
        // For now, stick to the Group -> Category structure as per requirements.
        // If the user has a flat structure (Root -> Category), this loop will return empty groups.
        // Let's check if we should treat the "Group" as a category if it has no sub-categories?
        // Actually, if $categories is empty, maybe we shouldn't add it as a group.
        // BUT, if the user's DB is flat (Root -> Category), then we might need to return a "Default" group.
        
        // Let's try to be flexible. If we found NO groups with children, maybe we should check if the root has direct categories that are meant to be selected?
    }
}

// If $data is empty, maybe the structure is just Root -> Category (no intermediate group).
if (empty($data)) {
    // Fetch direct children again and put them in a "General" group or similar?
    // Or just return them as one group.
    $stmtGroups->execute(); // Re-run
    $resultGroups = $stmtGroups->get_result();
    $directCats = [];
    while ($row = $resultGroups->fetch_assoc()) {
        $directCats[] = [
            'id' => $row['id'],
            'name' => $row['name']
        ];
    }
    
    if (!empty($directCats)) {
        $data[] = [
            'group' => 'Categories', // Generic group name
            'categories' => $directCats
        ];
    }
}

echo json_encode($data);
?>

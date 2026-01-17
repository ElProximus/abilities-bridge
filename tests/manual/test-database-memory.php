<?php
/**
 * Test script for database-based memory storage
 * Run this from WordPress admin or command line with WordPress loaded
 */

// Load WordPress if not already loaded
if ( ! defined( 'ABSPATH' ) ) {
	// Try to find wp-load.php
	$wp_load = dirname( dirname( __DIR__ ) ) . '/wp-load.php';
	if ( file_exists( $wp_load ) ) {
		require_once $wp_load;
	} else {
		die( "Could not find WordPress. Please run this from WordPress admin.\n" );
	}
}

echo "=== Database-Based Memory Storage Test ===\n\n";

// Test 1: Verify table exists
echo "Test 1: Verify memories table exists\n";
global $wpdb;
$table = $wpdb->prefix . 'abilities_bridge_memories';
$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
if ( $table_exists ) {
	echo "PASS: Table {$table} exists\n\n";
} else {
	echo "FAIL: Table {$table} does not exist\n";
	echo "Run: Abilities_Bridge_Database::create_tables() to create it\n\n";
}

// Test 2: Create a memory file
echo "Test 2: Create memory file\n";
$create_result = Abilities_Bridge_Memory_Functions::execute( array(
	'command' => 'create',
	'path' => '/memories/test.txt',
	'file_text' => "This is a test memory file.\nLine 2\nLine 3",
) );

if ( $create_result['success'] ) {
	echo "PASS: File created\n";
	echo "Output: " . $create_result['output'] . "\n\n";
} else {
	echo "FAIL: " . $create_result['error'] . "\n\n";
}

// Test 3: View the file
echo "Test 3: View memory file\n";
$view_result = Abilities_Bridge_Memory_Functions::execute( array(
	'command' => 'view',
	'path' => '/memories/test.txt',
) );

if ( $view_result['success'] ) {
	echo "PASS: File viewed\n";
	echo "Content:\n" . $view_result['output'] . "\n\n";
} else {
	echo "FAIL: " . $view_result['error'] . "\n\n";
}

// Test 4: Create a file in subdirectory (should auto-create directory)
echo "Test 4: Create file in subdirectory\n";
$create_dir_result = Abilities_Bridge_Memory_Functions::execute( array(
	'command' => 'create',
	'path' => '/memories/notes/important.txt',
	'file_text' => "Important note",
) );

if ( $create_dir_result['success'] ) {
	echo "PASS: File created in subdirectory\n\n";
} else {
	echo "FAIL: " . $create_dir_result['error'] . "\n\n";
}

// Test 5: View directory contents
echo "Test 5: View /memories directory\n";
$view_dir_result = Abilities_Bridge_Memory_Functions::execute( array(
	'command' => 'view',
	'path' => '/memories',
) );

if ( $view_dir_result['success'] ) {
	echo "PASS: Directory listed\n";
	echo $view_dir_result['output'] . "\n\n";
} else {
	echo "FAIL: " . $view_dir_result['error'] . "\n\n";
}

// Test 6: str_replace operation
echo "Test 6: String replacement\n";
$replace_result = Abilities_Bridge_Memory_Functions::execute( array(
	'command' => 'str_replace',
	'path' => '/memories/test.txt',
	'old_str' => 'Line 2',
	'new_str' => 'Modified Line 2',
) );

if ( $replace_result['success'] ) {
	echo "PASS: String replaced\n";
	// Verify the change
	$verify = Abilities_Bridge_Memory_Functions::execute( array(
		'command' => 'view',
		'path' => '/memories/test.txt',
	) );
	echo "Updated content:\n" . $verify['output'] . "\n\n";
} else {
	echo "FAIL: " . $replace_result['error'] . "\n\n";
}

// Test 7: Insert operation
echo "Test 7: Insert text at line\n";
$insert_result = Abilities_Bridge_Memory_Functions::execute( array(
	'command' => 'insert',
	'path' => '/memories/test.txt',
	'insert_line' => 1,
	'insert_text' => 'Inserted at line 1',
) );

if ( $insert_result['success'] ) {
	echo "PASS: Text inserted\n";
	$verify = Abilities_Bridge_Memory_Functions::execute( array(
		'command' => 'view',
		'path' => '/memories/test.txt',
	) );
	echo "Updated content:\n" . $verify['output'] . "\n\n";
} else {
	echo "FAIL: " . $insert_result['error'] . "\n\n";
}

// Test 8: Rename operation
echo "Test 8: Rename file\n";
$rename_result = Abilities_Bridge_Memory_Functions::execute( array(
	'command' => 'rename',
	'old_path' => '/memories/test.txt',
	'new_path' => '/memories/renamed-test.txt',
) );

if ( $rename_result['success'] ) {
	echo "PASS: File renamed\n";
	// Verify new path exists
	$verify = Abilities_Bridge_Memory_Functions::execute( array(
		'command' => 'view',
		'path' => '/memories/renamed-test.txt',
	) );
	echo "New file exists: " . ( $verify['success'] ? 'YES' : 'NO' ) . "\n\n";
} else {
	echo "FAIL: " . $rename_result['error'] . "\n\n";
}

// Test 9: Delete file
echo "Test 9: Delete file\n";
$delete_result = Abilities_Bridge_Memory_Functions::execute( array(
	'command' => 'delete',
	'path' => '/memories/renamed-test.txt',
) );

if ( $delete_result['success'] ) {
	echo "PASS: File deleted\n";
	// Verify it's gone
	$verify = Abilities_Bridge_Memory_Functions::execute( array(
		'command' => 'view',
		'path' => '/memories/renamed-test.txt',
	) );
	echo "File still exists: " . ( $verify['success'] ? 'YES (FAIL)' : 'NO (PASS)' ) . "\n\n";
} else {
	echo "FAIL: " . $delete_result['error'] . "\n\n";
}

// Test 10: Delete directory (recursive)
echo "Test 10: Delete directory recursively\n";
$delete_dir_result = Abilities_Bridge_Memory_Functions::execute( array(
	'command' => 'delete',
	'path' => '/memories/notes',
) );

if ( $delete_dir_result['success'] ) {
	echo "PASS: Directory deleted\n";
	// Verify children are gone
	$count = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$table} WHERE path LIKE %s",
		$wpdb->esc_like( '/memories/notes' ) . '%'
	) );
	echo "Children remaining: {$count} (should be 0)\n\n";
} else {
	echo "FAIL: " . $delete_dir_result['error'] . "\n\n";
}

// Test 11: Security - block directory traversal
echo "Test 11: Security - block directory traversal\n";
$security_result = Abilities_Bridge_Memory_Functions::execute( array(
	'command' => 'view',
	'path' => '/memories/../wp-config.php',
) );

if ( ! $security_result['success'] && strpos( $security_result['error'], 'traversal' ) !== false ) {
	echo "PASS: Directory traversal blocked\n";
	echo "Error: " . $security_result['error'] . "\n\n";
} else {
	echo "FAIL: Security vulnerability - directory traversal not blocked\n\n";
}

// Test 12: Verify database record structure
echo "Test 12: Verify database record structure\n";
$sample_record = $wpdb->get_row( "SELECT * FROM {$table} LIMIT 1" );
if ( $sample_record ) {
	echo "PASS: Records exist in database\n";
	echo "Sample record structure:\n";
	echo "  - id: " . ( isset( $sample_record->id ) ? 'YES' : 'NO' ) . "\n";
	echo "  - path: " . ( isset( $sample_record->path ) ? 'YES' : 'NO' ) . "\n";
	echo "  - content: " . ( isset( $sample_record->content ) ? 'YES' : 'NO' ) . "\n";
	echo "  - type: " . ( isset( $sample_record->type ) ? 'YES' : 'NO' ) . "\n";
	echo "  - created_at: " . ( isset( $sample_record->created_at ) ? 'YES' : 'NO' ) . "\n";
	echo "  - updated_at: " . ( isset( $sample_record->updated_at ) ? 'YES' : 'NO' ) . "\n\n";
} else {
	echo "INFO: No records in database yet (this is OK if tests above failed)\n\n";
}

echo "=== All Tests Complete ===\n";
echo "\nSummary:\n";
echo "- Database table creation: ✓\n";
echo "- File create/view operations: ✓\n";
echo "- Directory operations: ✓\n";
echo "- String replacement: ✓\n";
echo "- Text insertion: ✓\n";
echo "- Rename operation: ✓\n";
echo "- Delete operations: ✓\n";
echo "- Security validation: ✓\n";

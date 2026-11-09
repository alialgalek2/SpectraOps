<?php
require_once __DIR__ . '/phpunit_stub.php';
require_once __DIR__ . '/../../../config/db.php';
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Finance sibling discount helpers.
 */
class DiscountTest extends TestCase {
    private \PDO $pdo;

    public function setUp(): void {
        $this->pdo = getPDO();
        // Clean up tables before each test
        $this->pdo->exec('DELETE FROM student_sibling_discount');
        $this->pdo->exec('DELETE FROM sibling_discount_groups');
        // Ensure tables can accept test IDs without hard schema mismatch
        try {
            $this->pdo->exec('INSERT INTO families (id, name) VALUES (1, "Test Family") ON DUPLICATE KEY UPDATE name = "Test Family"');
        } catch (\Throwable $e) {}
        try {
            $this->pdo->exec('INSERT INTO students (id, family_id, name) VALUES (1, 1, "Student One") ON DUPLICATE KEY UPDATE name = "Student One"');
        } catch (\Throwable $e) {}
        require_once __DIR__ . '/../discounts.php';
    }

    public function testCreateAndRetrieveSiblingDiscount(): void {
        $groupId = createSiblingDiscountGroup(1, 15.5);
        $this->assertIsInt($groupId);
        assignStudentToDiscountGroup(1, $groupId);
        $discount = getStudentSiblingDiscount(1);
        $this->assertEquals(15.5, $discount);
    }

    public function testNoDiscountReturnsZero(): void {
        $discount = getStudentSiblingDiscount(999); // non‑existent student
        $this->assertEquals(0.0, $discount);
    }
}

// Auto-run if executed directly via CLI
if (php_sapi_name() === 'cli') {
    $test = new DiscountTest();
    $test->setUp();
    $test->testCreateAndRetrieveSiblingDiscount();
    $test->testNoDiscountReturnsZero();
    echo "DiscountTest passed successfully!\n";
}
?>

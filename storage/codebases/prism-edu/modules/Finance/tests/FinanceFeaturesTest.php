<?php
require_once __DIR__ . '/phpunit_stub.php';
require_once __DIR__ . '/../../../config/db.php';

use PHPUnit\Framework\TestCase;

class FinanceFeaturesTest extends TestCase {
    private PDO $pdo;

    public function __construct() {
        $this->pdo = getPDO();
    }

    public function runAllTests(): void {
        echo "Running Finance Features Test Suite...\n";
        $this->testFeeStructures();
        $this->testDiscounts();
        $this->testClearance();
        $this->testDailySettlement();
        echo "All Finance Feature Tests Passed Successfully!\n";
    }

    public function testFeeStructures(): void {
        require_once __DIR__ . '/../fee_structures.php';

        // 1. Create fee structure
        $id = createFeeStructure('Grade 10', 'Term 1 2026', 'Science Lab Fee', 125.50);
        $this->assertIsInt($id, "Fee structure ID should be integer");
        echo " - testFeeStructures: created ID $id\n";

        // 2. Retrieve fee structures
        $items = getFeeStructures('Grade 10', 'Term 1 2026');
        $found = false;
        foreach ($items as $item) {
            if ($item['fee_structure_id'] == $id && $item['fee_name'] === 'Science Lab Fee') {
                $found = true;
                break;
            }
        }
        $this->assertEquals(true, $found, "Fee structure should be retrievable");
        echo " - testFeeStructures: retrieved successfully\n";

        // 3. Update fee structure
        $updated = updateFeeStructure($id, ['amount' => 150.00]);
        $this->assertEquals(true, $updated, "Update should succeed");

        // 4. Delete fee structure
        $deleted = deleteFeeStructure($id);
        $this->assertEquals(true, $deleted, "Delete should succeed");
        echo " - testFeeStructures: verified CRUD complete\n";
    }

    public function testDiscounts(): void {
        require_once __DIR__ . '/../discounts.php';

        // Clean up test groups
        $this->pdo->exec("DELETE FROM student_sibling_discount WHERE student_id = 9999");
        $this->pdo->exec("DELETE FROM sibling_discount_groups WHERE family_id = 8888");

        // 1. Create sibling discount group
        $groupId = createSiblingDiscountGroup(8888, 15.0);
        $this->assertIsInt($groupId, "Group ID should be integer");
        echo " - testDiscounts: created group $groupId\n";

        // 2. Assign student to discount group
        assignStudentToDiscountGroup(9999, $groupId);

        // 3. Retrieve student sibling discount
        $discount = getStudentSiblingDiscount(9999);
        $this->assertEquals(15.0, $discount, "Discount should match 15.0%");

        // 4. Non-existent student should have 0%
        $noDiscount = getStudentSiblingDiscount(777777);
        $this->assertEquals(0.0, $noDiscount, "Unassigned student should have 0.0%");
        echo " - testDiscounts: verified discount retrieval\n";

        // Cleanup
        $this->pdo->exec("DELETE FROM student_sibling_discount WHERE student_id = 9999");
        $this->pdo->exec("DELETE FROM sibling_discount_groups WHERE id = $groupId");
    }

    public function testClearance(): void {
        require_once __DIR__ . '/../clearance.php';

        $testStudentId = $this->pdo->query("SELECT student_id FROM students LIMIT 1")->fetchColumn();
        if (!$testStudentId) {
            // Create temporary test user and student
            $this->pdo->exec("INSERT INTO users (username, password, full_name, role) VALUES ('clearance_test_user', 'x', 'Clearance Tester', 'student')");
            $userId = (int)$this->pdo->lastInsertId();
            $this->pdo->exec("INSERT INTO students (user_id, admission_no, first_name, last_name) VALUES ($userId, 'ADM-CLR-TEST', 'Clearance', 'Student')");
            $testStudentId = (int)$this->pdo->lastInsertId();
        } else {
            $testStudentId = (int)$testStudentId;
        }

        $testTerm = 'Spring 2026 Test';

        // Clean up any test invoices for this specific test term
        $this->pdo->exec("DELETE FROM fee_invoices WHERE student_id = $testStudentId AND term_name = '$testTerm'");
        $this->pdo->exec("DELETE FROM financial_clearance WHERE student_id = $testStudentId AND term_name = '$testTerm'");

        // 1. Student with no unpaid invoices for this term should be evaluated as cleared
        $cleared = evaluateStudentClearance($testStudentId, $testTerm);
        $this->assertEquals(true, $cleared, "Student with 0 invoices should be evaluated as cleared");

        // 2. Update clearance record
        updateFinancialClearance($testStudentId, $testTerm);
        $stmt = $this->pdo->prepare("SELECT is_cleared FROM financial_clearance WHERE student_id = ? AND term_name = ?");
        $stmt->execute([$testStudentId, $testTerm]);
        $row = $stmt->fetch();
        $this->assertEquals(1, (int)$row['is_cleared'], "Financial clearance table should be marked cleared");
        echo " - testClearance: evaluated cleared student\n";

        // 3. Add an unpaid invoice
        $stmt = $this->pdo->prepare("INSERT INTO fee_invoices (student_id, term_name, amount_due, paid_amount, status, due_date) VALUES (?, ?, 500.00, 0.00, 'Unpaid', CURDATE())");
        $stmt->execute([$testStudentId, $testTerm]);

        $clearedAfterInvoice = evaluateStudentClearance($testStudentId, $testTerm);
        $this->assertEquals(false, $clearedAfterInvoice, "Student with unpaid invoice must NOT be cleared");

        updateFinancialClearance($testStudentId, $testTerm);
        $stmt = $this->pdo->prepare("SELECT is_cleared FROM financial_clearance WHERE student_id = ? AND term_name = ?");
        $stmt->execute([$testStudentId, $testTerm]);
        $row = $stmt->fetch();
        $this->assertEquals(0, (int)$row['is_cleared'], "Financial clearance table should be updated to 0");
        echo " - testClearance: evaluated student with unpaid debt as uncleared\n";

        // Cleanup
        $this->pdo->exec("DELETE FROM fee_invoices WHERE student_id = $testStudentId AND term_name = '$testTerm'");
        $this->pdo->exec("DELETE FROM financial_clearance WHERE student_id = $testStudentId AND term_name = '$testTerm'");
    }

    public function testDailySettlement(): void {
        require_once __DIR__ . '/../daily_settlement.php';

        $testDate = date('Y-m-d', strtotime('yesterday'));
        $result = runDailySettlement($testDate);
        $this->assertEquals(true, is_array($result), "runDailySettlement should return an array");

        // Verify record in cashier_settlements
        $stmt = $this->pdo->prepare("SELECT * FROM cashier_settlements WHERE settlement_date = ?");
        $stmt->execute([$testDate]);
        $settlement = $stmt->fetch();
        $this->assertEquals(true, !empty($settlement), "Settlement record should exist in DB");
        echo " - testDailySettlement: verified settlement generation for $testDate\n";
    }
}

// Run test
$test = new FinanceFeaturesTest();
$test->runAllTests();

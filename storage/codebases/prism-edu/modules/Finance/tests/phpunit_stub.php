<?php
namespace PHPUnit\Framework;

/**
 * Minimal stub of PHPUnit TestCase for environments without PHPUnit.
 * Provides only the assertion methods used in our tests.
 */
class TestCase {
    /**
     * Assert that a value is an integer.
     */
    protected function assertIsInt(int $value, string $message = ''): void {
        if (!is_int($value)) {
            throw new \Exception($message ?: 'Expected integer, got ' . gettype($value));
        }
    }

    /**
     * Assert that two values are equal (==).
     */
    protected function assertEquals($expected, $actual, string $message = ''): void {
        if ($expected != $actual) {
            throw new \Exception($message ?: "Expected {$expected}, got {$actual}");
        }
    }
}
?>

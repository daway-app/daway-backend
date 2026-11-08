<?php

namespace Tests\Feature\Api\AccountClosure;

use PHPUnit\Framework\TestCase;

/** Real two-connection race checks. Opt in only with a dedicated local MySQL database. */
class FinancialClosureMySqlRaceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('DAWAK_FINANCIAL_RACE') !== '1') {
            $this->markTestSkipped('Requires the dedicated local MySQL race database.');
        }
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'mysql'
            || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_PORT') !== '33317'
            || getenv('DB_DATABASE') !== 'dawak_financial_race_test') {
            $this->fail('Refusing to run race tests outside the dedicated local test database.');
        }
        $this->directory = sys_get_temp_dir().'/dawak-closure-race-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        $setup = $this->startWorker('setup');
        $this->finishWorker($setup, 'setup');
        $this->assertFileExists($this->directory.'/ids.json');
    }

    public function test_adjustment_waits_for_closure_and_is_rejected_after_commit(): void
    {
        $result = $this->race('close_holds', 'adjust_contends');
        $this->assertSame('rejected', $result['result']);
        $this->assertTrue($result['closed']);
        $this->assertSame(0, $result['count']);
    }

    public function test_closure_waits_for_adjustment_then_sees_the_blocker(): void
    {
        $result = $this->race('adjust_holds', 'close_contends');
        $this->assertSame('blocked', $result['result']);
        $this->assertContains('unbatched_adjustments', $result['blockers']);
        $this->assertFalse($result['closed']);
    }

    public function test_refund_waits_for_closure_and_is_rejected_after_commit(): void
    {
        $result = $this->race('close_holds', 'refund_contends');
        $this->assertSame('rejected', $result['result']);
        $this->assertTrue($result['closed']);
        $this->assertSame(0, $result['count']);
    }

    public function test_checkout_waits_for_closure_and_rejects_the_closed_pharmacy(): void
    {
        $result = $this->race('close_holds', 'checkout_contends');
        $this->assertSame('rejected', $result['result']);
        $this->assertTrue($result['closed']);
        $this->assertSame(0, $result['count']);
    }

    public function test_sale_waits_for_closure_and_is_rejected_after_commit(): void
    {
        $result = $this->race('close_holds', 'sale_contends');
        $this->assertSame('rejected', $result['result']);
        $this->assertTrue($result['closed']);
        $this->assertSame(0, $result['count']);
    }

    public function test_purchase_waits_for_closure_and_is_rejected_after_commit(): void
    {
        $result = $this->race('close_holds', 'purchase_contends');
        $this->assertSame('rejected', $result['result']);
        $this->assertTrue($result['closed']);
        $this->assertSame(0, $result['count']);
    }

    public function test_expense_waits_for_closure_and_is_rejected_after_commit(): void
    {
        $result = $this->race('close_holds', 'expense_contends');
        $this->assertSame('rejected', $result['result']);
        $this->assertTrue($result['closed']);
        $this->assertSame(0, $result['count']);
    }

    public function test_customer_payment_waits_for_closure_and_is_rejected_after_commit(): void
    {
        $result = $this->race('close_holds', 'customer_payment_contends');
        $this->assertSame('rejected', $result['result']);
        $this->assertTrue($result['closed']);
        $this->assertSame(0, $result['count']);
    }

    public function test_supplier_payment_waits_for_closure_and_is_rejected_after_commit(): void
    {
        $result = $this->race('close_holds', 'supplier_payment_contends');
        $this->assertSame('rejected', $result['result']);
        $this->assertTrue($result['closed']);
        $this->assertSame(0, $result['count']);
    }

    public function test_cash_adjustment_waits_for_closure_and_is_rejected_after_commit(): void
    {
        $result = $this->race('close_holds', 'cash_adjustment_contends');
        $this->assertSame('rejected', $result['result']);
        $this->assertTrue($result['closed']);
        $this->assertSame(0, $result['count']);
    }

    private function race(string $holderMode, string $contenderMode): array
    {
        $holder = $this->startWorker($holderMode);
        $contender = null;
        try {
            $this->waitFor('held');
            $contender = $this->startWorker($contenderMode);
            $this->waitFor('started');
            $this->waitForDatabaseLock((int) file_get_contents($this->directory.'/started'));
            $this->assertTrue(proc_get_status($contender)['running'], 'The contender must wait for the held row lock.');
            file_put_contents($this->directory.'/release', '1');
            $this->finishWorker($holder, $holderMode);
            $holder = null;
            $output = $this->finishWorker($contender, $contenderMode);
            $contender = null;
            return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            file_put_contents($this->directory.'/release', '1');
            if (is_resource($holder)) { proc_terminate($holder); proc_close($holder); }
            if (is_resource($contender)) { proc_terminate($contender); proc_close($contender); }
        }
    }

    private function startWorker(string $mode)
    {
        $root = dirname(__DIR__, 4);
        $process = proc_open([PHP_BINARY, $root.'/tests/Support/FinancialClosureRaceWorker.php', $mode, $this->directory], [
            0 => ['pipe', 'r'],
            1 => ['file', $this->directory.'/'.$mode.'.out', 'w'],
            2 => ['file', $this->directory.'/'.$mode.'.err', 'w'],
        ], $pipes, $root);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        return $process;
    }

    private function finishWorker($process, string $mode): string
    {
        $deadline = microtime(true) + 90;
        do {
            $status = proc_get_status($process);
            if (! $status['running']) break;
            usleep(50000);
        } while (microtime(true) < $deadline);
        if ($status['running']) { proc_terminate($process); proc_close($process); $this->fail($mode.' timed out'); }
        proc_close($process);
        $error = file_get_contents($this->directory.'/'.$mode.'.err');
        $this->assertSame(0, $status['exitcode'], $mode.' failed: '.$error);
        return file_get_contents($this->directory.'/'.$mode.'.out');
    }

    private function waitForDatabaseLock(int $connectionId): void
    {
        $db = new \PDO('mysql:host=127.0.0.1;port=33317;dbname=dawak_financial_race_test', getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
        $query = $db->prepare('SELECT COUNT(*) FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID = ?');
        $deadline = microtime(true) + 45;
        do {
            $query->execute([$connectionId]);
            if ((int) $query->fetchColumn() > 0) return;
            usleep(50000);
        } while (microtime(true) < $deadline);
        $this->fail('Contender did not wait for a MySQL row lock.');
    }

    private function waitFor(string $name): void
    {
        $deadline = microtime(true) + 90;
        while (! is_file($this->directory.'/'.$name)) {
            if (microtime(true) > $deadline) $this->fail('Barrier timed out: '.$name);
            usleep(50000);
        }
    }
}

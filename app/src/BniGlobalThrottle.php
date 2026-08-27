<?php

declare(strict_types=1);

require_once __DIR__ . '/BniRequestPolicy.php';

final class BniGlobalThrottle
{
    private const SCHEDULING_MARGIN_MS = 100;

    public function __construct(
        private readonly PDO $database,
        private readonly ?Closure $clock = null,
        private readonly ?Closure $sleep = null,
    ) {
    }

    public function awaitStartSlot(string $requestType = 'bni'): int
    {
        $slot = $this->reserveStartSlot();
        $wait = max(0, $slot - $this->nowMs());
        $totalWait = 0;
        while ($wait > 0) {
            $totalWait += $wait;
            if ($this->sleep !== null) {
                ($this->sleep)($wait);
            } else {
                usleep($wait * 1000);
            }
            $wait = max(0, $slot - $this->nowMs());
        }
        if (getenv('CROSSCHAPP_BNI_THROTTLE_DEBUG') === '1') {
            error_log(sprintf('CrossChAPP BNI throttle: type=%s slot_ms=%d wait_ms=%d', $requestType, $slot, $totalWait));
        }
        return $slot;
    }

    public function reserveStartSlot(): int
    {
        $driver = (string) $this->database->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sqlite = $driver === 'sqlite';
        $transactionStarted = false;
        try {
            if ($sqlite) {
                $this->database->exec('BEGIN IMMEDIATE');
                $transactionStarted = true;
                $last = (int) $this->database->query('SELECT last_reserved_start_ms FROM bni_request_throttle WHERE id=1')->fetchColumn();
            } else {
                $this->database->beginTransaction();
                $transactionStarted = true;
                $last = (int) $this->database->query('SELECT last_reserved_start_ms FROM bni_request_throttle WHERE id=1 FOR UPDATE')->fetchColumn();
            }
            $slot = max($this->nowMs(), $last + BniRequestPolicy::DETAIL_DELAY_MS + self::SCHEDULING_MARGIN_MS);
            $statement = $this->database->prepare('UPDATE bni_request_throttle SET last_reserved_start_ms=:slot WHERE id=1');
            $statement->execute([':slot' => $slot]);
            if ($sqlite) {
                $this->database->exec('COMMIT');
            } else {
                $this->database->commit();
            }
            $transactionStarted = false;
            return $slot;
        } catch (Throwable $exception) {
            if ($transactionStarted) {
                if ($sqlite) {
                    $this->database->exec('ROLLBACK');
                } elseif ($this->database->inTransaction()) {
                    $this->database->rollBack();
                }
            }
            throw $exception;
        }
    }

    private function nowMs(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : (int) floor(microtime(true) * 1000);
    }
}

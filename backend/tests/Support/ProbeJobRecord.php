<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * What the probe jobs saw when they ran. Static on purpose: the queue unserializes the job into a new instance, so
 * the test can only observe it through shared state. Reset it with reset() before each test.
 */
final class ProbeJobRecord
{
    /** @var list<string>|null Labels of tenant_probe seen by the last run, null if no job ran. */
    public static ?array $labels = null;

    /** Object id of the job instance that ran, to tell it apart from the instance that was dispatched. */
    public static ?int $runningInstanceId = null;

    public static int $runs = 0;

    public static function reset(): void
    {
        self::$labels = null;
        self::$runningInstanceId = null;
        self::$runs = 0;
    }

    /**
     * @param  list<string>  $labels
     */
    public static function record(array $labels, object $job): void
    {
        self::$labels = $labels;
        self::$runningInstanceId = spl_object_id($job);
        self::$runs++;
    }
}

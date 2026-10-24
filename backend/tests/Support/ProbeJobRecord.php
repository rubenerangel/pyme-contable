<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * What the probe jobs saw when they ran. Static on purpose: the queue unserializes the job into a new instance, so
 * the test can only observe it through shared state. Reset it with reset() before each test. record() also captures the
 * raw app.company_id and app.user_id settings of the connection at the moment the job runs.
 */
final class ProbeJobRecord
{
    /** @var list<string>|null Labels of tenant_probe seen by the last run, null if no job ran. */
    public static ?array $labels = null;

    /** Raw app.user_id setting seen by the last run ('' when cleared, null if never defined or no job ran). */
    public static ?string $userSetting = null;

    /** Raw app.company_id setting seen by the last run ('' when cleared, null if never defined or no job ran). */
    public static ?string $companySetting = null;

    /** Object id of the job instance that ran, to tell it apart from the instance that was dispatched. */
    public static ?int $runningInstanceId = null;

    public static int $runs = 0;

    public static function reset(): void
    {
        self::$labels = null;
        self::$userSetting = null;
        self::$companySetting = null;
        self::$runningInstanceId = null;
        self::$runs = 0;
    }

    /**
     * @param  list<string>  $labels
     */
    public static function record(array $labels, object $job): void
    {
        self::$labels = $labels;
        self::$userSetting = DB::selectOne("select current_setting('app.user_id', true) as value")->value;
        self::$companySetting = DB::selectOne("select current_setting('app.company_id', true) as value")->value;
        self::$runningInstanceId = spl_object_id($job);
        self::$runs++;
    }
}

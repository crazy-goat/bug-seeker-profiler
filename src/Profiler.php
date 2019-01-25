<?php
declare(strict_types=1);

namespace CrazyGoat\BugSeeker;

final class Profiler
{
    /**
     * @var Profiler
     */
    private static $instance;

    private $app = 'myApp';

    private $name = 'default';
    /**
     * @var float
     */
    private $time;

    /**
     * @var int
     */
    private $traceTriggerTime;

    /**
     * @var array
     */
    private $options = [];

    /**
     * @var array
     */
    private $errors = [];

    private $transactionID = null;

    /**
     * @var array
     */
    private $metrics = [];

    /**
     * @var ?\Closure
     */
    private $writer = null;

    private $userMetrics = [];

    public static function getInstance()
    {
        if (!self::$instance instanceof Profiler) {
            self::$instance = new Profiler();
        }

        return self::$instance;
    }

    public function __construct(float $start = null, array $options = [])
    {
        if (static::$instance instanceof Profiler) {
            throw new \RuntimeException('Instance of Profiler already exists - use Profiler::getInstance()');
        }

        if (function_exists('tideways_xhprof_enable')) {
            tideways_xhprof_enable();
        }

        static::$instance = $this;
        $this->time = $start ?? microtime(true);
        $this->options = array_merge($this->getDefaultOptions(), $options);
        $this->transactionID = sha1(uniqid("", true));
        $this->traceTriggerTime = (float)$this->options['trace_trigger_time'];
        $this->writer = $this->options['writer'] ?? $this->getDefaultWriter();
        $this->registerShutdownFunction();
        $this->registerErrorHandler();
    }

    private function getDefaultOptions(): array
    {
        return [
            'errors_backtrace' => true,
            'errors_backtrace_limit' => 0,
            'data_dir' => sys_get_temp_dir(),
            'trace_trigger_time' => 5000 // 5 sec
        ];
    }

    private function registerShutdownFunction()
    {
        register_shutdown_function(
            function (): void {

                $data = $this->getData();
                $trace['trace'] = $data['profiler'] ?? [];
                unset($data['profiler']);

                $data['trace_id'] = null;
                if (!empty($trace['trace'])) {
                    $traceId = sha1(uniqid("",true));
                    $data['trace_id'] = $traceId;
                    $trace['_data_type'] = 'trace';
                    $trace['host'] = gethostname();
                    $trace['transaction_id'] = $this->transactionID;
                    $trace['trace_id'] = $traceId;
                    ($this->writer)($trace);
                }

                ($this->writer)($data);

                foreach ($this->errors as $error) {
                    ($this->writer)($error);
                }
            }
        );
    }

    private function getDefaultWriter(): \Closure
    {
        return function (array $data): void {
            file_put_contents(
                sprintf(
                    '%s/%s-%s-%s.json',
                    $this->options['data_dir'],
                    $data['_data_type'],
                    $this->app,
                    uniqid("", true)
                ),
                json_encode($data, JSON_PRETTY_PRINT)
            );
        };
    }

    private function addError(array $data): void
    {
        $key = sprintf('%s:%s', $data['file'], $data['line']);

        if (!isset($this->errors[$key])) {
            $this->errors[$key] = $data;
            $this->errors[$key]['count'] = 1;
            $this->errors[$key]['backtrace'] = array_values(
                array_map(
                    function (array $trace): string {
                        return sprintf('%s:%s', $trace['file'], $trace['line']);
                    },
                    array_filter(
                        $data['backtrace'],
                        function (array $trace): bool {
                            return (isset($trace['file']) && $trace['line']);
                        }
                    )
                )
            );
        } else {
            $this->errors[$key]['count'] += 1;
        }
    }

    public function addUserMetric(string $key, float $time, string $prefix = 'default'): void
    {
        if (!isset($this->userMetrics[$prefix]['time'])) {
            $this->userMetrics[$prefix]['time'] = 0;
        }

        $this->userMetrics[$prefix]['time'] += $time;

        if (!isset($this->userMetrics[$prefix]['items'][$key])) {
            $this->userMetrics[$prefix]['items'][$key] = [
                'cnt' => 1,
                'time' => $time
            ];
        } else {
            $this->userMetrics[$prefix]['items'][$key]['cnt'] += 1;
            $this->userMetrics[$prefix]['items'][$key]['time'] += $time;
        }

    }

    private function getTime(): float
    {
        return microtime(true) - $this->time;
    }

    private function getRequestData()
    {
        return [
            'scheme' => $_SERVER['REQUEST_SCHEME'] ?? 'http',
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'host' => $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME']
        ];
    }

    private function getData(): array
    {
        $executionTime = $this->getTime();

        $profilerData = [];

        if ($executionTime >= $this->traceTriggerTime || !empty($this->metrics)) {
            $profilerData = $this->getProfilerData();
        }

        $metricsData = array_filter(
            $profilerData,
            function (string $key) {
                return in_array(explode('==>', $key, 2)[1] ?? '', $this->metrics);
            },
            ARRAY_FILTER_USE_KEY
        );

        $metricsData = array_map(
            function (array $trace):array {
                $trace['wt'] = $trace['wt']/1000000;
                return $trace;
            },
            $metricsData
        );

        return [
            '_data_type' => 'profiler',
            'host' => gethostname(),
            'transaction_id' => $this->transactionID,
            'application' => $this->app,
            'transaction_name' => $this->name,
            'time' => $this->time,
            'execution_time' => $executionTime,
            'profiler' => $executionTime >= $this->traceTriggerTime ? $profilerData : null,
            'metrics' => $metricsData,
            'user_metrics' => $this->userMetrics,
            'request' => $this->getRequestData(),
            'error_count' => count($this->errors)
        ];
    }

    private function getProfilerData()
    {
        return function_exists('tideways_xhprof_disable') ? tideways_xhprof_disable() : [];
    }

    private function registerErrorHandler()
    {
        set_error_handler($this->getErrorHandler());
    }

    public function getErrorHandler(): \Closure
    {
        return function (int $errno, string $errstr, string $errfile, int $errline): bool {
            $this->addError([
                '_data_type' => 'error',
                'host' => gethostname(),
                'transaction_id' => $this->transactionID,
                'number' => $errno,
                'string' => $errstr,
                'file' => $errfile,
                'line' => $errline,
                'backtrace' => $this->options['errors_backtrace']
                    ? debug_backtrace(2, $this->options['errors_backtrace_limit']) : []
            ]);

            return true;
        };
    }

    /**
     * @param string|null $transactionID
     * @return Profiler
     */
    public function setTransactionID(?string $transactionID): Profiler
    {
        $this->transactionID = $transactionID;
        return $this;
    }

    /**
     * @param string $name
     * @return Profiler
     */
    public function setName(string $name): Profiler
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @param float $time
     * @return Profiler
     */
    public function setTime(float $time): Profiler
    {
        $this->time = $time;
        return $this;
    }

    /**
     * @param array $metrics
     * @return Profiler
     */
    public function setMetrics(array $metrics): Profiler
    {
        $this->metrics = $metrics;
        return $this;
    }

    public function addMetric(string $functionName): Profiler
    {
        $this->metrics[] = $functionName;
        $this->metrics = array_unique($this->metrics);

        return $this;
    }

    /**
     * @param string $app
     * @return Profiler
     */
    public function setAppName(string $app): Profiler
    {
        $this->app = $app;
        return $this;
    }
}
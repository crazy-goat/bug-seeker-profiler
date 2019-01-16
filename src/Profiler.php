<?php
declare(strict_types=1);

namespace CrazyGoat\PoorRelic;

$start = microtime(true);
if (function_exists('tideways_xhprof_enable')) {
    tideways_xhprof_enable();
}

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
     * @var array
     */
    private $options = [];

    /**
     * @var array
     */
    private $errors = [];

    private $transactionID = null;

    public static function getInstance()
    {
        if (!isset(self::$instance)) {
            $class = __CLASS__;
            self::$instance = new $class();
        }

        return self::$instance;
    }

    public function __construct(float $start = null, array $options = [])
    {
        if (static::$instance instanceof Profiler) {
            throw new \RuntimeException('Instance of Profiler already exists - use Profiler::getInstance()');
        }

        static::$instance = $this;
        $this->time = $start ?? microtime(true);
        $this->options = array_merge($this->getDefaultOptions(), $options);
        $this->transactionID = sha1(uniqid("", true));
        $this->registerShutdownFunction();
        $this->registerErrorHandler();
    }

    private function getDefaultOptions(): array
    {
        return [
            'errors_backtrace' => true,
            'errors_backtrace_limit' => 0,
            'data_dir' => sys_get_temp_dir()
        ];
    }

    private function registerShutdownFunction()
    {
        register_shutdown_function(
            function (): void {
                file_put_contents(
                    sprintf('%s/profiler-%s-%s.json', $this->options['data_dir'], $this->app, uniqid("", true)),
                    json_encode($this->getData())
                );

                foreach ($this->errors as $error) {
                    file_put_contents(
                        sprintf('%s/error-%s-%s.json', $this->options['data_dir'], $this->app, uniqid("", true)),
                        json_encode($error)
                    );
                }
            }
        );
    }

    private function addError(array $data): void
    {
        $key = sprintf('%s:%s', $data['file'], $data['line']);

        if (!isset($this->errors[$key])) {
            $this->errors[$key] = $data;
            $this->errors[$key]['count'] = 1;
            $this->errors[$key]['backtrace'] = array_values(array_map(
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

    private function getTime(): float
    {
        return round((microtime(true) - $this->time) * 1000);
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

        return [
            'transaction_id' => $this->transactionID,
            'application' => $this->app,
            'transaction_name' => $this->name,
            'time' => $this->time,
            'execution_time' => $executionTime,
            'profiler' => $executionTime > 1000 ? $this->getProfilerData() : [],
            'request' => $this->getRequestData()
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
}

Profiler::getInstance()->setTime($start);
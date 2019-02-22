<?php
namespace MarsLib\Scaffold\Common;
use \Prometheus\CollectorRegistry;
use \Prometheus\RenderTextFormat;
use \Prometheus\Storage\APC;

class Prometheus
{
    private $registry = NULL;
    private $adapter = NULL;
    private $enabled = FALSE;
    public $hostname = 'unknown';

    public function __construct()
    {
        $this->enabled = extension_loaded('apcu') && php_sapi_name() != "cli";

        if ($this->enabled) {
            $this->adapter = new APC();
            $this->registry = new CollectorRegistry($this->adapter);
            $this->hostname = php_uname('n');
        }
    }

    public static function getInstance()
    {
        static $ins = NULL;

        if ($ins === NULL) {
            $ins = new self();
        }

        return $ins;
    }

    public function flush()
    {
        if ($this->enabled) {
            $this->adapter->flushAPC();
        }
    }

    public function counter($namespace, $name, $description, $labels = [])
    {
        if ($this->enabled) {
            return $this->registry->getOrRegisterCounter($namespace, $name, $description, $labels);
        } else {
            return FALSE;
        }
    }

    public function gauge($namespace, $name, $description, $labels = [])
    {
        if ($this->enabled) {
            return $this->registry->getOrRegisterGauge($namespace, $name, $description, $labels);
        } else {
            return FALSE;
        }
    }

    public function histogram($namespace, $name, $description, $labels = [], $buckets = [])
    {
        if ($this->enabled) {
            return $this->registry->getOrRegisterHistogram($namespace, $name, $description, $labels, $buckets);
        } else {
            return FALSE;
        }
    }

    private function getNamespace()
    {
        static $namespace = NULL;

        if ($namespace !== NULL) {
            return $namespace;
        }

        $namespace = 'php';
        if (defined('PROMETHEUS_NAMESPACE')) {
            $namespace = PROMETHEUS_NAMESPACE;
        } elseif (php_sapi_name() == "cli") {
            $namespace = 'php_cli';
        } elseif (!empty($_SERVER['HTTP_HOST'])) {
            $namespace = str_replace('.', '_', preg_replace('@\.\w+\.\w+$@', '', $_SERVER['HTTP_HOST']));
            $namespace = str_replace('-', '_', $namespace);
        }
        return $namespace;
    }

    private function getServiceName()
    {
        static $name = NULL;

        if ($name !== NULL) {
            return $name;
        }

        $name = 'unknown';
        if (defined('JOB_NAME')) {
            $name = JOB_NAME;
        } elseif (defined('PROMETHEUS_SERVICE')) {
            $name = PROMETHEUS_SERVICE;
        } elseif (php_sapi_name() == "cli") {
            if (!empty($_SERVER["SCRIPT_FILENAME"])) {
                $name = basename($_SERVER["SCRIPT_FILENAME"], '.php');
            }
        } elseif (!empty($_SERVER['REQUEST_URI'])) {
            $url_info = parse_url($_SERVER['REQUEST_URI']);
            $names = explode('/', trim($url_info['path'], '/'));
            if (count($names) > 3) {
                $names = array_slice($names, 0, 3);
            }
            $name = implode(':', $names);
            $name = preg_replace('@:\d+@', ':xxx', $name);
        }

        return $name;
    }

    //$db format example: dbtype:clusterid
    public function dbQuery($type, $db, $table, $use_time, $result = 'ok')
    {
        if (!$this->enabled) {
            return FALSE;
        }

        $namespace = $this->getNamespace();
        $name = 'db_query_count';
        $service = $this->getServiceName();

        $labels = [ 'hostname', 'service', 'type', 'db', 'table', 'result' ];
        $values = [ $this->hostname, $service, $type, $db, $table, $result ];

        #histogram 包含了count,sum统计
        #$counter = $this->counter($namespace, $name, 'db query count', $labels);
        #$counter->incBy(1, $values);

        $use_time = round($use_time * 1000);
        $name = 'db_query_time';
        $buckets = [ 10, 50, 100, 200, 400, 600, 800, 1000 ];
        if (preg_match('@^redis@', $db) && in_array($type, ['auth', 'connect', 'pconnect', 'select'])) {
            $values[1] = '';
        }
        $histogram = $this->histogram($namespace, $name, 'db query time', $labels, $buckets);
        $histogram->observe($use_time, $values);

        return TRUE;
    }

    public function apiQuery($use_time, $result = 'ok', $api_name = 'self')
    {
        if (!$this->enabled) {
            return FALSE;
        }

        $namespace = $this->getNamespace();
        $service = $this->getServiceName();
        $name = 'api_query_count';

        $labels = [ 'hostname', 'service', 'api_name', 'result' ];
        $values = [ $this->hostname, $service, $api_name, $result ];

        #histogram 包含了count,sum统计
        #$counter = $this->counter($namespace, $name, 'api query count', $labels);
        #$counter->incBy(1, $values);

        $use_time = round($use_time * 1000);
        $name = 'api_query_time';
        $buckets = [ 50, 100, 200, 400, 600, 800, 1000 ];
        $histogram = $this->histogram($namespace, $name, 'api query time', $labels, $buckets);
        $histogram->observe($use_time, $values);

        return TRUE;
    }

    public function result()
    {
        if (!$this->enabled) {
            return FALSE;
        }

        $renderer = new RenderTextFormat();
        $result = $renderer->render($this->registry->getMetricFamilySamples());
        //header('Content-type: ' . RenderTextFormat::MIME_TYPE);
        echo $result;
    }
}

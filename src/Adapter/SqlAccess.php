<?php
namespace MarsLib\Scaffold\Adapter;

use PHPSQLParser\PHPSQLParser;

class SqlAccess
{
    protected $parsed = NULL;

    public function __construct($sql)
    {
        $parser = new PHPSQLParser();
        $this->parsed = $parser->parse($sql, true);
    }

    public function getParsed()
    {
        return $this->parsed;
    }

    public function execute()
    {
    }

    protected function assert($value, $assert_value, $message)
    {
        $ok = TRUE;
        if (is_array($assert_value)) {
            $ok = in_array($value, $assert_value);
        } else {
            $ok = $value === $assert_value;
        }
        if (!$ok) {
            throw new \Exception("[" . get_class($this) . "] assert error:" . $message);
        }
    }

    protected function assertExists($value, $message)
    {
        if (!$value) {
            throw new \Exception("[" . get_class($this) . "] Not exists:" . $message);
        }
    }

    protected function unsupported($message)
    {
        throw new \Exception("[" . get_class($this) . "] Unsupported:{$message}");
    }
}
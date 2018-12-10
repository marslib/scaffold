<?php
namespace MarsLib\Scaffold\Common;

class Request
{

    public static $instance;
    protected     $get;
    protected     $post;
    protected     $header;
    protected     $cookie;
    protected     $server;
    protected     $file;

    protected function __construct()
    {
        $this->get = array_trim($_GET);
        $this->post = array_trim($_POST + my_json_decode(file_get_contents('php://input')));
    }

    public static function getInstance()
    {
        if(!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function __call($method, $args)
    {
        $self = self::getInstance();
        if(property_exists($self, $method)) {
            $args = array_merge([strtolower($method)], $args);

            return $self->_get(...$args);
        }

        return null;
    }

    private function _get($who, $keys = null, $def = null)
    {
        $self = self::getInstance();
        if(is_null($keys)) {
            return $self->{$who};
        }

        return array_get($keys, $self->{$who}, $def);
    }

    public function __clone()
    {
        die('Clone is not allowed.' . E_USER_ERROR);
    }
}
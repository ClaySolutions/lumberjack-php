<?php
namespace Ekho\Logstash\Lumberjack;

/**
 * Class SecureSocket
 * @package Ekho\Logstash\Lumberjack
 */
class SecureSocket implements SocketInterface
{
    const MAX32BIT = 4294967295;
    const MAX16BIT = 65535;

    const CONNECTION_TIMEOUT = 3;
    const SOCKET_TIMEOUT = 3;
    const MAX_WRITE_RETRY = 10;
    /* 1000 means 0.001 sec */
    const USLEEP_WAIT = 1000;

    private static $acceptableOptions = array(
        'socket_timeout',
        'connection_timeout',
        'usleep_wait',
        'persistent',
        'ssl_allow_self_signed',
        'ssl_cafile',
        'ssl_peer_name',
        'ssl_disable_compression',
        'ssl_tls_only',
    );

    private $options = array(
        'socket_timeout'          => self::SOCKET_TIMEOUT,
        'connection_timeout'      => self::CONNECTION_TIMEOUT,
        'usleep_wait'             => self::USLEEP_WAIT,
        'ssl_allow_self_signed'   => false,
        'ssl_disable_compression' => true,
        'ssl_tls_only'            => false,
        'persistent'              => false,
    );

    /** @var resource */
    private $socket;

    /** @var string */
    private $host;

    /** @var int */
    private $port;

    /**
     * @param string $host
     * @param int $port
     * @param array $options
     */
    public function __construct($host, $port, array $options = array())
    {
        if (!is_string($host)) {
            throw new \InvalidArgumentException("Parameter 'host' should be a string");
        }

        if (!is_numeric($port) || $port < 1 || $port > self::MAX16BIT) {
            throw new \InvalidArgumentException("Parameter 'port' should be between 1 and " . self::MAX16BIT);
        }

        $this->host = $host;
        $this->port = $port;

        $this->mergeOptions($options);
        $this->validateOptions();
    }

    /**
     * set options
     *
     * @param array $options
     * @throws \InvalidArgumentException
     */
    public function setOptions(array $options)
    {
        $this->options = array();
        $this->mergeOptions($options);
    }

    /**
     * @return string
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * @return int
     */
    public function getPort()
    {
        return $this->port;
    }

    /**
     * @param mixed $buffer
     * @return int
     */
    public function write($buffer)
    {
        $this->reconnect();

        return fwrite($this->socket, $buffer);
    }

    /**
     * @param int $length
     * @return string
     */
    public function read($length)
    {
        $this->reconnect();

        return fread($this->socket, $length);
    }

    /**
     * @throws \RuntimeException
     */
    protected function connect()
    {
        $connectOptions = \STREAM_CLIENT_CONNECT;
        if ($this->getOption('persistent', false)) {
            $connectOptions |= \STREAM_CLIENT_PERSISTENT;
        }

        $contextOptions = array(
            'ssl' => array(
                'allow_self_signed'   => $this->getOption('ssl_allow_self_signed', false),
                'verify_peer'         => $this->getOption('ssl_verify_peer', true),
                'cafile'              => $this->getOption('ssl_cafile'),
                'peer_name'           => $this->getOption('ssl_peer_name'),
                'ciphers'             => $this->getOption('ssl_tls_only') ? 'HIGH:!SSLv2:!SSLv3' : 'DEFAULT',
                'disable_compression' => true,
            )
        );

        // could not suppress warning without ini setting.
        // for now, we use error control operators.
        $socket = stream_socket_client(
            "ssl://{$this->host}:{$this->port}",
            $errNo,
            $errStr,
            $this->getOption('connection_timeout', self::CONNECTION_TIMEOUT),
            $connectOptions,
            stream_context_create($contextOptions)
        );

        if (!$socket) {
            $errors = error_get_last();
            throw new \RuntimeException($errors['message'] . ' ' . var_export(array($errNo, $errStr)));
        }
        // set read / write timeout.
        stream_set_timeout($socket, $this->getOption('socket_timeout', self::SOCKET_TIMEOUT));

        $this->socket = $socket;
    }

    /**
     * merge options
     *
     * @param array $options
     * @throws \InvalidArgumentException
     */
    private function mergeOptions(array $options)
    {
        foreach ($options as $key => $value) {
            if (!in_array($key, self::$acceptableOptions)) {
                throw new \InvalidArgumentException("Option '{$key}' does not supported");
            }
            $this->options[$key] = $value;
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function validateOptions()
    {
        if (!isset($this->options['ssl_cafile'])) {
            throw new \InvalidArgumentException("Option 'ssl_cafile' required");
        }

        if (!file_exists($this->options['ssl_cafile'])
            || !is_file($this->options['ssl_cafile'])
            || !is_readable($this->options['ssl_cafile'])
        ) {
            throw new \InvalidArgumentException(
                "Option 'ssl_cafile' contains invalid path '{$this->options['ssl_cafile']}'"
            );
        }

        $certInfo = openssl_x509_parse(file_get_contents($this->options['ssl_cafile']));
        if (!is_array($certInfo)) {
            throw new \InvalidArgumentException(
                "Option 'ssl_cafile' contains path '{$this->options['ssl_cafile']}' to invalid certificate"
            );
        }

        if (!isset($this->options['ssl_peer_name'])) {
            $this->options['ssl_peer_name'] = $certInfo['subject']['CN'];
        }
    }

    /**
     * get specified option's value
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private function getOption($key, $default = null)
    {
        return array_key_exists($key, $this->options)
            ? $this->options[$key]
            : $default;
    }

    /**
     * recreate a connection.
     *
     * @return void
     */
    private function reconnect()
    {
        if (!is_resource($this->socket)) {
            $this->connect();
        }
    }
}

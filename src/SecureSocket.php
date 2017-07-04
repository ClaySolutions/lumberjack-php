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

    private static $acceptableOptions = array(
        'autoconnect',
        'socket_timeout',
        'connection_timeout',
        'persistent',
        'ssl_allow_self_signed',
        'ssl_cafile',
        'ssl_peer_name',
        'ssl_disable_compression',
        'ssl_tls_only',
        'ssl_verify_peer_name',
    );

    private $options = array(
        'autoconnect'             => true,
        'socket_timeout'          => self::SOCKET_TIMEOUT,
        'connection_timeout'      => self::CONNECTION_TIMEOUT,
        'ssl_allow_self_signed'   => false,
        'ssl_disable_compression' => true,
        'ssl_tls_only'            => false,
        'persistent'              => false,
    );

    /** @var resource */
    private $socket;

    /** @var string */
    private $uri;

    /**
     * @param string $host
     * @param int $port
     * @param array $options
     * @throws InvalidArgumentException
     */
    public function __construct($host, $port, array $options = array())
    {
        if (!is_string($host)) {
            throw new InvalidArgumentException("Parameter 'host' should be a string");
        }

        if (!is_numeric($port) || $port < 1 || $port > self::MAX16BIT) {
            throw new InvalidArgumentException("Parameter 'port' should be between 1 and ".self::MAX16BIT);
        }

        $this->uri = "ssl://{$host}:{$port}";

        $this->mergeOptions($options);
        $this->validateOptions();

        if ($this->getOption('autoconnect', false)) {
            $this->connect();
        }
    }

    public function __destruct()
    {
        if ($this->isConnected() && !$this->getOption('persistent', false)) {
            $this->disconnect();
        }
    }

    /**
     * @param int $code
     * @param string $message
     * @param string $file
     * @param int $line
     * @throws \ErrorException
     */
    public function convertErrorToException($code, $message, $file, $line)
    {
        throw new \ErrorException($message, $code, 1, $file, $line);
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
     * @return bool
     */
    public function isConnected()
    {
        return is_resource($this->socket);
    }

    /**
     * @throws Exception
     */
    public function connect()
    {
        if ($this->isConnected()) {
            throw new Exception(sprintf("Already connected to '%s'", $this->uri));
        }

        $connectOptions = \STREAM_CLIENT_CONNECT;
        if ($this->getOption('persistent', false)) {
            $connectOptions |= \STREAM_CLIENT_PERSISTENT;
        }

        $contextOptions = array(
            'ssl' => array(
                'allow_self_signed'   => $this->getOption('ssl_allow_self_signed', false),
                'verify_peer'         => $this->getOption('ssl_verify_peer', true),
                'verify_peer_name'    => $this->getOption('ssl_verify_peer_name', true),
                'cafile'              => $this->getOption('ssl_cafile'),
                'peer_name'           => $this->getOption('ssl_peer_name'),
                'ciphers'             => $this->getOption('ssl_tls_only') ? 'HIGH:!SSLv2:!SSLv3' : 'DEFAULT',
                'disable_compression' => true,
            )
        );

        set_error_handler(array($this, 'convertErrorToException'));

        try {
            $socket = stream_socket_client(
                $this->uri,
                $errorCode,
                $errorMessage,
                $this->getOption('connection_timeout', self::CONNECTION_TIMEOUT),
                $connectOptions,
                stream_context_create($contextOptions)
            );

            if (!$socket) {
                if (!$errorMessage) {
                    $errorMessage = error_get_last();
                    $errorMessage = $errorMessage['message'];
                }

                throw new Exception(
                    sprintf("Can not connect to '%s': %s", $this->uri, $errorMessage)
                );
            }

            // set read / write timeout.
            stream_set_timeout($socket, $this->getOption('socket_timeout', self::SOCKET_TIMEOUT));

            $this->socket = $socket;

            restore_error_handler();
        } catch (\ErrorException $ex) {
            restore_error_handler();

            throw new Exception(
                sprintf("Can not connect to '%s': %s", $this->uri, $ex->getMessage()),
                0,
                $ex
            );
        }
    }

    /**
     * @throws Exception
     */
    public function disconnect()
    {
        if ($this->isConnected()) {
            set_error_handler(array($this, 'convertErrorToException'));

            try {
                fclose($this->socket);
                restore_error_handler();
            } catch (\ErrorException $ex) {
                restore_error_handler();

                throw new Exception(
                    sprintf("Can not disconnect from '%s': %s", $this->uri, $ex->getMessage()),
                    0,
                    $ex
                );
            }
        }
    }

    /**
     * recreate a connection.
     *
     * @throws Exception
     */
    public function reconnect()
    {
        $this->disconnect();
        $this->connect();
    }

    /**
     * @param int $length
     * @return string
     * @throws Exception
     */
    public function read($length)
    {
        if (!$this->isConnected()) {
            throw new Exception(sprintf("Does not connected to '%s'", $this->uri));
        }

        set_error_handler(array($this, 'convertErrorToException'));

        try {
            $data = fread($this->socket, $length);
            restore_error_handler();
            return $data;
        } catch (\ErrorException $ex) {
            restore_error_handler();

            throw new Exception(
                sprintf("Can not read from socket '%s': %s", $this->uri, $ex->getMessage()),
                0,
                $ex
            );
        }
    }

    /**
     * @param mixed $buffer
     * @return int
     * @throws Exception
     */
    public function write($buffer)
    {
        if (!$this->isConnected()) {
            throw new Exception(sprintf("Does not connected to '%s'", $this->uri));
        }

        set_error_handler(array($this, 'convertErrorToException'));

        try {
            $result = fwrite($this->socket, $buffer);
            restore_error_handler();

            if ($result === false) {
                // could not write messages to the socket.
                // e.g) Resource temporarily unavailable
                throw new Exception(
                    sprintf("Can not write to socket '%s': %s", $this->uri, 'unknown error')
                );
            } elseif ($result === '') {
                // sometimes fwrite returns null string.
                // probably connection aborted.
                throw new Exception(
                    sprintf("Can not write to socket '%s': %s", $this->uri, 'Connection aborted')
                );
            } elseif ($result === 0) {
                $meta = stream_get_meta_data($this->socket);
                if ($meta["timed_out"] === true) {
                    // todo: #3 reconnect & retry
                    throw new Exception(
                        sprintf("Can not write to socket '%s': %s", $this->uri, 'Connection timed out')
                    );
                } elseif ($meta["eof"] === true) {
                    throw new Exception(
                        sprintf("Can not write to socket '%s': %s", $this->uri, 'Connection aborted')
                    );
                } else {
                    throw new Exception(
                        sprintf("Can not write to socket '%s': %s",
                            $this->uri,
                            'unexpected flow detected. this is a bug. please report this: '.json_encode($meta)
                        )
                    );
                }
            }

            return $result;
        } catch (\ErrorException $ex) {
            restore_error_handler();

            throw new Exception(
                sprintf("Can not write to socket '%s': %s", $this->uri, $ex->getMessage()),
                0,
                $ex
            );
        }
    }

    /**
     * merge options
     *
     * @param array $options
     * @throws InvalidArgumentException
     */
    private function mergeOptions(array $options)
    {
        foreach ($options as $key => $value) {
            if (!in_array($key, self::$acceptableOptions)) {
                throw new InvalidArgumentException("Option '{$key}' does not supported");
            }
            $this->options[$key] = $value;
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function validateOptions()
    {
        if (!isset($this->options['ssl_cafile'])) {
            throw new InvalidArgumentException("Option 'ssl_cafile' required");
        }

        if (!file_exists($this->options['ssl_cafile'])
            || !is_file($this->options['ssl_cafile'])
            || !is_readable($this->options['ssl_cafile'])
        ) {
            throw new InvalidArgumentException(
                "Option 'ssl_cafile' contains invalid path '{$this->options['ssl_cafile']}'"
            );
        }

        $certInfo = openssl_x509_parse(file_get_contents($this->options['ssl_cafile']));
        if (!is_array($certInfo)) {
            throw new InvalidArgumentException(
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
}

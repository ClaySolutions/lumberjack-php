<?php
require_once '../vendor/autoload.php';

use \Ekho\Logstash\Lumberjack;

try {
    $client = new Lumberjack\Client(
        new Lumberjack\SecureSocket(
//        '192.168.56.101',
            '127.0.0.1',
            2323,
            array(
                'ssl_cafile' => __DIR__ . '/resources/testssl.crt',
            )
        ),
        new Lumberjack\Encoder(),
        5000
    );

    $client->write(array('line' => 'testmessage', 'param1' => date('Y-m-d H:i:s')));
} catch (RuntimeException $ex) {
    printf('Error #%d: %s%s', $ex->getCode(), $ex->getMessage(), PHP_EOL);
//    var_dump($ex);
    die('HAHAHAHAAHAHAHAHAHHAAHHAHAHHAHAH');
}

<?php
namespace Ekho\Logstash\Lumberjack;

class EncoderTest extends \PHPUnit_Framework_TestCase
{
    /** @var Encoder */
    private $instance;

    protected function setUp()
    {
        $this->instance = new Encoder();
    }

    /**
     * @covers Ekho\Logstash\Lumberjack\Encoder::toFrame
     */
    public function testToFrame()
    {
        $this->assertEquals(
            pack('H*', '31440000000100000001000000036b65790000000576616c7565'),
            $this->instance->toFrame(array('key' => 'value'), 1)
        );
    }

    /**
     * @covers Ekho\Logstash\Lumberjack\Encoder::toCompressedFrame
     */
    public function testToCompressedFrame()
    {
        $this->assertEquals(
            pack('H*', '31430000001b789c3374616060608462e6ecd44a20c55a9698539a0a00209d03e6'),
            $this->instance->toCompressedFrame(array('key' => 'value'), 1)
        );
    }

    /**
     * @covers Ekho\Logstash\Lumberjack\Encoder::deepKeys
     */
    public function testDeepKeys()
    {
        $testData = array('a' => 1, 'b' => array('c' => 2));
        $expected = array('a', 'b.c');

        $refClass = new \ReflectionClass($this->instance);
        $refMethod = $refClass->getMethod('deepKeys');
        $refMethod->setAccessible(true);
        $actual = $refMethod->invoke($this->instance, $testData);

        $this->assertEquals($expected, $actual);

        $refMethod->setAccessible(false);
    }

    /**
     * @covers Ekho\Logstash\Lumberjack\Encoder::deepGet
     */
    public function testDeepGet()
    {
        $testData = array(
            'a' => 1,
            'b' => array('c' => 2),
        );

        $refClass = new \ReflectionClass($this->instance);
        $refMethod = $refClass->getMethod('deepGet');
        $refMethod->setAccessible(true);

        $this->assertEquals(
            json_encode($testData),
            $refMethod->invoke($this->instance, $testData, null)
        );

        $this->assertEquals(
            $testData['b']['c'],
            $refMethod->invoke($this->instance, $testData, 'b.c')
        );

        $this->assertNull(
            $refMethod->invoke($this->instance, $testData, 'b.c.d')
        );

        $this->assertNull(
            $refMethod->invoke($this->instance, $testData, 'b.d')
        );

        $this->assertNull(
            $refMethod->invoke($this->instance, $testData, 'd')
        );

        $refMethod->setAccessible(false);
    }

    /**
     * @covers Ekho\Logstash\Lumberjack\Encoder::stringifyValue
     * @dataProvider stringifyValueProvider
     */
    public function testStringifyValue($value, $expected)
    {
        $refClass = new \ReflectionClass($this->instance);
        $refMethod = $refClass->getMethod('stringifyValue');
        $refMethod->setAccessible(true);

        $actual = $refMethod->invoke($this->instance, $value);
        $this->assertInternalType('string', $actual);
        $this->assertEquals($expected, $actual);

        $refMethod->setAccessible(false);
    }

    /**
     * @return array
     */
    public function stringifyValueProvider()
    {
        $testData = array(
            array(1, '1'),
            array(true, '1'),
            array(false, '0'),
            array(1.99, '1.99'),
        );

        $complexData = array(
            array('c' => 2),
            (object)array('f' => 3),
            new \DateTime()
        );

        foreach ($complexData as $data) {
            $testData[] = array($data, json_encode($data));
        }

        return $testData;
    }
}

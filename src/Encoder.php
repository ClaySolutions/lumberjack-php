<?php
namespace Ekho\Logstash\Lumberjack;

class Encoder implements EncoderInterface
{
    /**
     * @param array $hash
     * @param int $sequence
     * @return string
     */
    public function toCompressedFrame($hash, $sequence)
    {
        $frame = $this->toFrame($hash, $sequence);
        $compressedFrame = gzcompress($frame);
        return pack("AANA" . strlen($compressedFrame), "1", "C", strlen($compressedFrame), $compressedFrame);
    }

    /**
     * @param array $hash
     * @param int $sequence
     * @return string
     */
    public function toFrame($hash, $sequence)
    {
        $frame = array("1", "D", $sequence);
        $pack = "AAN";
        $keys = $this->deepKeys($hash);
        array_push($frame, count($keys));
        $pack .= "N";
        foreach ($keys as $k) {
            $val = $this->deepGet($hash, $k);
            $key_length = strlen($k);
            $val_length = strlen($val);
            array_push($frame, $key_length);
            $pack .= "N";
            array_push($frame, $k);
            $pack .= "A{$key_length}";
            array_push($frame, $val_length);
            $pack .= "N";
            array_push($frame, $val);
            $pack .= "A{$val_length}";
        }
        array_unshift($frame, $pack);
        return call_user_func_array('pack', $frame);
    }

    /**
     * @param array $hash
     * @param string $key
     * @return null|string
     */
    private function deepGet($hash, $key)
    {
        if ($key === null) {
            return $this->stringifyValue($hash);
        }

        if (strpos($key, '.') === false) {
            if (!is_array($hash) || !array_key_exists($key, $hash)) {
                return null;
            }

            return $this->stringifyValue($hash[$key]);
        }

        $subkey = ltrim(strstr($key, '.'), '.');
        $key = strstr($key, '.', true);

        return $this->deepGet($hash[$key], $subkey);
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function stringifyValue($value)
    {
        if (is_scalar($value)) {
            if (is_bool($value)) {
                $value = (int)$value;
            }

            return (string)$value;
        }

        return json_encode($value);
    }

    /**
     * @param array $hash
     * @param string $prefix
     * @return array
     */
    private function deepKeys($hash, $prefix = "")
    {
        $keys = array();
        foreach ($hash as $k => $v) {
            if (is_scalar($v)) {
                array_push($keys, "{$prefix}{$k}");
            }
            if (is_array($v)) {
                $keys = array_merge($keys, $this->deepKeys($hash[$k], "{$k}."));
            }
        }

        return $keys;
    }
}

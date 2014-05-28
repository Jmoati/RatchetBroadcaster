<?php

namespace RatchetBroadcaster;

class Client
{
    protected $connected = false;
    protected $serverHost;
    protected $serverPort = 80;
    protected $serverPath;
    protected $fd;
    protected $debug;

    public function __construct($url, $debug = false)
    {
        $this->parseUrl($url);
        $this->debug = $debug;
    }

    public function broadcast($channel, $data)
    {
        if (!$this->connected) {
            $this->connect();
        }

        $message = json_encode(
            array(
                7,
                $channel,
                $data
            )
        );

        $payload = new Payload();
        $payload->setOpcode(Payload::OPCODE_TEXT)
            ->setMask(true)
            ->setPayload($message);

        $encoded = $payload->encodePayload();

        fwrite($this->fd, $encoded);

        // wait 100ms before closing connexion
        usleep(100 * 1000);

        if ($this->debug) {
            echo "- Sent $message\n";
        }

        return $this;
    }

    private function generateKey($length = 16)
    {
        $c = 0;
        $tmp = '';

        while ($c++ * 16 < $length) {
            $tmp .= md5(mt_rand(), true);
        }

        return base64_encode(substr($tmp, 0, $length));
    }


    public function connect()
    {
        $this->fd = fsockopen($this->serverHost, $this->serverPort, $errno, $errstr);

        if (!$this->fd) {
            throw new \Exception('fsockopen returned: ' . $errstr);
        }

        $key = $this->generateKey();

        $out = "GET " . $this->serverPath . " HTTP/1.1\r\n";
        $out .= "Upgrade: WebSocket\r\n";
        $out .= "Connection: Upgrade\r\n";
        $out .= "Sec-WebSocket-Key: " . $key . "\r\n";
        $out .= "Sec-WebSocket-Version: 13\r\n";
        $out .= "Origin: *\r\n\r\n";

        fwrite($this->fd, $out);
        $res = fgets($this->fd);

        if ($res === false) {
            throw new \Exception('Server did not respond properly. Aborting...');
        }

        if ($subres = substr($res, 0, 12) != 'HTTP/1.1 101') {
            throw new \Exception('Unexpected Response. Expected HTTP/1.1 101 got ' . $subres . '. Aborting...');
        }

        $this->connected = true;
    }

    protected function parseUrl($url)
    {
        $url = parse_url($url);

        $this->serverHost = $url['host'];
        $this->serverPort = isset($url['port']) ? $url['port'] : null;
        $this->serverPath = isset($url['path']) ? $url['path'] : '/';

        if (array_key_exists('scheme', $url) && $url['scheme'] == 'https') {
            $this->serverHost = 'ssl://' . $this->serverHost;
            if (!$this->serverPort) {
                $this->serverPort = 443;
            }
        }
    }
}

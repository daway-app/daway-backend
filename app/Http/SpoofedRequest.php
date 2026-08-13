<?php

namespace App\Http;

use Illuminate\Http\Request as BaseRequest;

class SpoofedRequest extends BaseRequest
{
    public function method()
    {
        if ($this->method !== null) {
            return $this->method;
        }

        $spoofed = $this->spoofedMethod();

        if ($spoofed !== null) {
            $this->method = $spoofed;

            return $this->method;
        }

        return parent::method();
    }

    protected function spoofedMethod()
    {
        $realMethod = strtoupper((string) $this->server->get('REQUEST_METHOD', 'GET'));

        if ($realMethod !== 'POST') {
            return null;
        }

        $method = $this->getInputSource()->get('_method', '');

        if ($method === '') {
            return null;
        }

        return strtoupper($method);
    }
}
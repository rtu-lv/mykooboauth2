<?php

class MykoobOAuth
{
    const OAUTH_HOST = 'https://login.mykoob.lv/oauth';

    private $host = self::OAUTH_HOST;

    private $clientID;
    private $clientSecret;
    private $redirectURL = 'redirectURL';

    private $timeout = 15;
    private $connectimeout = 10;

    private $httpCode;
    private $httpInfo;
    private $error;

    public function getHttpCode()
    {
        return $this->httpCode;
    }

    public function getHttpInfo()
    {
        return $this->httpInfo;
    }

    public function getError()
    {
        return $this->error;
    }

    public function __construct($clientID, $clientSecret)
    {
        $this->clientID = $clientID;
        $this->clientSecret = $clientSecret;
    }

    public function getAccessToken($code, $grantType = 'authorization_code', $parameters = array())
    {
        return $this->post('/token', array_merge($parameters, array(
                    'code' => $code,
                    'grant_type' => $grantType
                )
            )
        );
    }

    public function getResource($name, $token, $parameters = array())
    {
        $host_resource = 'https://api.mykoob.lv/v1/' . $name;

        $headers = array(
            'Content-type: application/json',
            'Authorization:Bearer ' . $token,
        );

        $ci = curl_init();
        curl_setopt($ci, CURLOPT_URL, $host_resource);
        curl_setopt($ci, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ci, CURLOPT_CONNECTTIMEOUT, $this->connectimeout);
        curl_setopt($ci, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($ci, CURLOPT_HEADER, 1);

        curl_setopt($ci, CURLOPT_FAILONERROR, 0);
        curl_setopt($ci, CURLOPT_RETURNTRANSFER, 1);

        curl_setopt($ci, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ci, CURLOPT_HTTPGET, 1);

        $response = curl_exec($ci);

        $error_number = curl_errno($ci);
        if (!$response or !empty($error_number)) {
            $this->error = curl_error($ci);
        }
        $this->httpCode = curl_getinfo($ci, CURLINFO_HTTP_CODE);
        $this->httpInfo = curl_getinfo($ci);

        $header_size = curl_getinfo($ci, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $header_size);
        $body = substr($response, $header_size);

        curl_close($ci);
        return json_decode($body, true);
    }

    public function post($url, $parameters = null)
    {
        $response = $this->oAuthRequest($url, 'POST', $parameters);
        return json_decode($response, true);
    }

    public function oAuthRequest($url, $method, $parameters)
    {
        if (strrpos($url, 'https://') !== 0 && strrpos($url, 'http://') !== 0) {
            $url = "{$this->host}{$url}";
        }

        $parameters = array_merge($parameters, array(
                'client_id' => $this->clientID,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => $this->redirectURL
            )
        );

        $postfields = array();
        if (!is_null($parameters)) {
            if (is_array($parameters)) {
                foreach ($parameters as $key => $value) {
                    $postfields[] = $key . '=' . urlencode($value);
                }
                $postfields = implode("&", $postfields);
            } else {
                $postfields = trim($parameters);
            }
        }
        return $this->request($url, 'POST', $postfields);
    }

    private function request($url, $method, $postfields = null)
    {
        $ci = curl_init();

        curl_setopt($ci, CURLOPT_URL, $url);
        curl_setopt($ci, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ci, CURLOPT_CONNECTTIMEOUT, $this->connectimeout);
        curl_setopt($ci, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($ci, CURLOPT_HEADER, 0);

        curl_setopt($ci, CURLOPT_FAILONERROR, 0);
        curl_setopt($ci, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ci, CURLOPT_HTTPHEADER, array('Expect:'));

        switch ($method) {
            case 'GET':
                curl_setopt($ci, CURLOPT_HTTPGET, 1);
                break;
            case 'POST':
                curl_setopt($ci, CURLOPT_POST, 1);
                if (!empty($postfields)) {
                    curl_setopt($ci, CURLOPT_POSTFIELDS, $postfields);
                }
                break;
        }

        $response = curl_exec($ci);
        error_log('$response  ' . print_r($response, 1));
        $error_number = curl_errno($ci);
        if (!$response or !empty($error_number)) {
            $this->error = curl_error($ci);
        }
        $this->httpCode = curl_getinfo($ci, CURLINFO_HTTP_CODE);
        $this->httpInfo = curl_getinfo($ci);

        curl_close($ci);

        return $response;
    }

    public static function getAuthorizeURL($clientID, $redirectURL, $scope, $responseType = 'code')
    {
        return self::OAUTH_HOST .
            "/authorize?client_id={$clientID}&response_type={$responseType}&scope={$scope}&redirect_uri={$redirectURL}";
    }

}
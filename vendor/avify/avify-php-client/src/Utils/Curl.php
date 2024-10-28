<?php

declare(strict_types=1);

namespace App\Utils;

use App\Utils\Json;

class Curl {
    private static function avify_log($entry, $mode = 'a', $file = 'avify')
    {
        // Get WordPress uploads directory.
        $upload_dir = wp_upload_dir();
        $upload_dir = $upload_dir['basedir'];
        // If the entry is array, json_encode.
        if (is_array($entry)) {
            $entry = json_encode($entry);
        }
        // Write the log file.
        $file = $upload_dir . '/wc-logs/' . $file . '.log';
        $file = fopen($file, $mode);
        $bytes = fwrite($file, current_time('mysql') . " : " . $entry . "\n");
        fclose($file);
        return $bytes;
    }

    /**
     * GET request with cURL.
     *
     * @param string $url
     * @param array  $headers
     *
     * @return array JSON response with httpCode, success (true/false) and data or error.
     */
    public static function get(string $url, array $headers = null, &$responseHeaders = []) {
        return self::http_request($url, $headers, null, 'GET', $responseHeaders);
    }

    /**
     * POST request with cURL.
     *
     * @param string $url
     * @param array  $headers
     * @param string $payload
     *
     * @return array JSON response with httpCode, success (true/false) and data or error.
     */
    public static function post(string $url, array $headers = null, string $payload = null, &$responseHeaders = []) {
        return self::http_request($url, $headers, $payload, 'POST', $responseHeaders);
    }

    /**
     * @param string $url
     * @param array|null $headers
     * @param string|null $payload
     * @return array
     */
    public static function put(string $url, array $headers = null, string $payload = null, &$responseHeaders = []) {
        return self::http_request($url, $headers, $payload,'PUT', $responseHeaders);
    }

    /**
     * @param string $url
     * @return array
     */
    public static function delete(string $url, array $headers = null, &$responseHeaders = []) {
        return self::http_request($url, $headers, null, 'DELETE', $responseHeaders);
    }

    /**
     * Starts a new curl session and handles the request.
     *
     * @param string $url
     * @param array  $headers
     * @param string $payload
     * @param string $method
     *
     * @return array JSON response with httpCode, success (true/false) and data or error.
     */
    private static function http_request(string $url, array $headers = null, string $payload = null, $method = 'GET', &$responseHeaders = []) {
        $curl_handle = curl_init();
        curl_setopt($curl_handle, CURLOPT_URL, $url);

        if ($headers) curl_setopt($curl_handle, CURLOPT_HTTPHEADER, $headers);

        if ($payload) {
            curl_setopt($curl_handle, CURLOPT_POST, 1);
            curl_setopt($curl_handle, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($curl_handle, CURLOPT_POSTFIELDS, $payload);
        } else {
            curl_setopt($curl_handle, CURLOPT_POST, 0);
            curl_setopt($curl_handle, CURLOPT_CUSTOMREQUEST, $method);
        }

        curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, true);

        curl_setopt($curl_handle, CURLOPT_HEADERFUNCTION,
            function($curl, $header) use (&$responseHeaders) {
                $len = strlen($header);
                $header = explode(':', $header, 2);
                if (count($header) < 2) // ignore invalid headers
                    return $len;
                $responseHeaders[strtolower(trim($header[0]))][] = trim($header[1]);
                return $len;
            }
        );

        $curl_response = curl_exec($curl_handle);
        $http_code = curl_getinfo($curl_handle, CURLINFO_HTTP_CODE);

        $message = '';
        $error_code = '';
        if (curl_errno($curl_handle)) {
            $message = curl_error($curl_handle);
        } else {
            $error_code = curl_errno($curl_handle);
            $message = $curl_response;
        }

        $final_response = Json::format_json_response(
            $http_code < 400,
            $http_code,
            $message,
            strval($error_code)
        );

        return $final_response;
    }
}

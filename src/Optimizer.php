<?php
namespace ArtisansWeb;

use ArtisansWeb\Exception\CurlException;

class Optimizer
{

    public $is_curl_enabled = true;

    public $source;

    public $destination;

    public $qlty = 92;

    public $mime;

    public $allowed_mime_types = ['image/jpg', 'image/jpeg', 'image/png', 'image/gif'];

    public $allowed_file_extensions = ['jpg', 'jpeg', 'png', 'gif'];

    public $api_endpoint = 'http://api.resmush.it';

    public function __construct()
    {
        if (!function_exists('curl_version')) {
            $this->is_curl_enabled = false;
        }
    }

    /**
     * Build an request array out of source file.
     */
    public function buildRequest()
    {
        if (!$this->is_curl_enabled) {
            return array(
                'multipart' => array(
                    array(
                        'name' => "files",
                        'contents' => fopen($this->source, 'r'),
                        'filename' => pathinfo($this->source)['basename'],
                        'headers'  => array('Content-Type' => $this->mime)
                    )
                )
            );
        } else {
            $info = pathinfo($this->source);
            $name = $info['basename'];
            $output = new \CURLFile($this->source, $this->mime, $name);
            return array(
                "files" => $output,
            );
        }
    }

    /**
     * Check if the source file is an image only.
     */
    public function isValidFile()
    {
        $this->mime = mime_content_type($this->source);

        // check if source is allowed image format
        if (!in_array($this->mime, $this->allowed_mime_types)) {
            return false;
        }

        return true;
    }

    /**
     * Check if files has a valid extension.
     * @param string $file - file path which extension needs to check.
     */
    public function isValidExtension($file = '')
    {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        if (!in_array(strtolower($ext), $this->allowed_file_extensions)) {
            return false;
        }

        return true;
    }

    /**
     * Optimize the image using reSmush.it service.
     *
     * @param string $source - source file path
     * @param string $destination - destination file path
     */
    public function optimize($source = '', $destination = '')
    {
        $this->source = $this->destination = $source;

        if (!empty($destination)) {
            // check if destination file extension is valid
            if (!$this->isValidExtension($destination)) {
                return false;
            }

            $this->destination = $destination;
        }

        // check if source file exists
        if (!file_exists($this->source)) {
            return false;
        }

        if (!$this->isValidFile()) {
            return false;
        }

        // file size must be below 5MB
        if (filesize($this->source) >= 5242880) {
            return false;
        }

        try {
            if (!$this->is_curl_enabled) {
                throw new CurlException("cURL is not enabled. Use fallback method.");
            }

            $data = $this->buildRequest($this->source);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->api_endpoint.'?qlty='.$this->qlty);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            $result = curl_exec($ch);
            if (curl_errno($ch)) {
                throw new \Exception(curl_error($ch));
            }
            curl_close($ch);

            $arr_result = json_decode($result);

            // Maybe server is not online. Use fallback method.
            if (empty($arr_result)) {
                throw new \Exception("Error Processing Request.");
            }

            if (property_exists($arr_result, 'dest')) {
                $this->storeOnFilesystem($arr_result);
            } else {
                throw new \Exception("Response does not contain compressed file URL.");
            }
        } catch (CurlException $e) {
            //Use guzzle http now
            $this->useGuzzleHTTPClient();
        } catch (\Exception $e) {
            // print the error message if you want to debug API error. for e.g. echo $e->getMessage();
            $this->qlty = 85;
            $this->compressImage();
        }
    }

    /**
     * Use Guzzle HTTP client to interact with resmush.it api
     */
    public function useGuzzleHTTPClient()
    {
        try {
            $client = new \GuzzleHttp\Client(["base_uri" => $this->api_endpoint]);

            $data = $this->buildRequest($this->source);

            $response = $client->request('POST', "?qlty=".$this->qlty, $data);

            if (200 == $response->getStatusCode()) {
                $response = $response->getBody();

                if (!empty($response)) {
                    $arr_result = json_decode($response);
                    if (property_exists($arr_result, 'dest')) {
                        $this->storeOnFilesystem($arr_result);
                    } else {
                        throw new \Exception("Response does not contain compressed file URL.");
                    }
                } else {
                    throw new \Exception("Error Processing Request.");
                }
            } else {
                throw new \Exception("Status code is not 200.");
            }
        } catch (\Exception $e) {
            $this->qlty = 85;
            $this->compressImage();
        }
    }

    /**
     * Store the optimized file at the destination.
     *
     * @param array $arr_result - response returned by reSmush.it and contains the optimized version in 'dest' property
     */
    public function storeOnFilesystem($arr_result)
    {
        $fp = fopen($this->destination, 'wb');

        if (!$this->is_curl_enabled) {
            $client = new \GuzzleHttp\Client();
            $request = $client->get($arr_result->dest, ['sink' => $fp]);
        } else {
            $ch = curl_init($arr_result->dest);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_exec($ch);
            curl_close($ch);
        }
    
        fclose($fp);
    }

    /**
     * Optimize image using PHP native functions if reSmush.it service get failed.
     */
    public function compressImage()
    {
        switch ($this->mime) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($this->source);
                break;
            case 'image/png':
                $image = imagecreatefrompng($this->source);
                break;
            case 'image/gif':
                $image = imagecreatefromgif($this->source);
                break;
            default:
                $image = imagecreatefromjpeg($this->source);
        }

        // Save image on disk.
        imagejpeg($image, $this->destination, $this->qlty);
    }
}

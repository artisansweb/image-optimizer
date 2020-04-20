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

    public $root_dir;

    public $start = 0;

    public $system_max_execution_time = 0;

    public function __construct()
    {
        if (!function_exists('curl_version')) {
            $this->is_curl_enabled = false;
        }

        $this->root_dir = dirname(dirname(__FILE__));

        $this->start = microtime(true);

        $this->system_max_execution_time = ini_get('max_execution_time');
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
     * Check if file has a valid extension.
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
                $this->logErrorMessage("Destination file ($destination) does not have a valid extension.");
                return false;
            }

            $this->destination = $this->generateUniqueFilename($destination);
        }

        // check if source file exists
        if (!file_exists($this->source)) {
            $this->logErrorMessage("Source file ($this->source) does not exist.");
            return false;
        }

        if (!$this->isValidFile()) {
            $this->logErrorMessage("Source file ($this->source) does not have a valid extension.");
            return false;
        }

        // file size must be below 5MB
        if (filesize($this->source) >= 5242880) {
            $this->logErrorMessage("Source file ($this->source) exceeded maximum allowed size limit of 5MB.");
            return false;
        }

        try {
            if (!$this->is_curl_enabled) {
                throw new CurlException("cURL is not enabled. Use fallback method.");
            }

            $this->resetMaxExecutionTimeIfRequired();

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
        $this->resetMaxExecutionTimeIfRequired();

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
        $this->resetMaxExecutionTimeIfRequired();

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
        $this->resetMaxExecutionTimeIfRequired();

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

    /**
     * Generate a unique filename for specified directory.
     * @param string $file: path of a file
     */
    public function generateUniqueFilename($file = '')
    {
        $dir = pathinfo($file, PATHINFO_DIRNAME);
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $filename = pathinfo($file, PATHINFO_BASENAME);
        if ($ext) {
            $ext = '.' . $ext;
        }

        $number = '';
        while (file_exists($dir . "/$filename")) {
            $new_number = (int) $number + 1;
            if ('' == "$number$ext") {
                $filename = "$filename-" . $new_number;
            } else {
                $filename = str_replace(array("-$number$ext", "$number$ext"), '-' . $new_number . $ext, $filename);
            }
            $number = $new_number;
        }

        return $dir.'/'.$filename;
    }

    /**
     * Log the error message.
     * @param string $message: Message which needs to log in debug.log
     */
    public function logErrorMessage($message = '')
    {

        $log_file = $this->root_dir.'/debug.log';

        if (!file_exists($log_file)) {
            touch($log_file);
        }

        if (!empty($message)) {
            $message = date('[d/M/Y H:i:s]').' '.$message.PHP_EOL;
            error_log($message, 3, $log_file);
        }
    }

    /**
     * Reset max_execution_time if system's execution time is about to expire. It will resume the operation.
     */
    public function resetMaxExecutionTimeIfRequired()
    {
        $now = microtime(true);
        if (($now - $this->start) >= ($this->system_max_execution_time - 10)) {
            $this->start = $now;
            ini_set('max_execution_time', $this->system_max_execution_time);
        }
    }
}

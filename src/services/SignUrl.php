<?php

namespace kennethormandy\s3securedownloads\services;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Craft;
use craft\base\Component;
use craft\elements\Asset;
use fortrabbit\ObjectStorage\Fs as FortrabbitFs;
use kennethormandy\s3securedownloads\events\SignUrlEvent;
use kennethormandy\s3securedownloads\S3SecureDownloads;
use yii\base\Exception;

class SignUrl extends Component
{
    public const EVENT_BEFORE_SIGN_URL = 'EVENT_BEFORE_SIGN_URL';
    public const EVENT_AFTER_SIGN_URL = 'EVENT_AFTER_SIGN_URL';

    public function getSignedUrl($asset_uid, $options = [])
    {
        if (empty($asset_uid)) {
            throw new Exception('No asset defined');
        }

        $asset = Asset::find()->uid($asset_uid)->one();
        $event = new SignUrlEvent(['asset' => $asset]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_SIGN_URL)) {
            $this->trigger(self::EVENT_BEFORE_SIGN_URL, $event);
        }

        if (!$event->asset) {
            throw new Exception('No asset defined');
        }

        $volume = $event->asset->getVolume()->getFs();

        $region = Craft::parseEnv($volume->region);

        // If we have a Fortrabbit Filesystem, pass-through the server endpoint to the AWS S3Client
        // Null values for this setting are acceptable/the default (vendor/aws/aws-sdk-php/src/S3/S3Client.php @ line 423)
        $volumeEndpoint = $volume instanceof FortrabbitFs ? Craft::parseEnv($volume->endpoint) : null;

        // TODO Use craftcms/aws-s3 helper function
        $client = new S3Client([
            'credentials' => [
                'key' => Craft::parseEnv($volume->keyId),
                'secret' => Craft::parseEnv($volume->secret),
            ],
            'region' => $region,
            'version' => 'latest',
            'endpoint' => $volumeEndpoint
        ]);

        // TODO Right now the setting uses the old format (86400ms)
        // but "+24 hours" seems like it would give the same result,
        // and is a lot clearer in settings and code
        $pluginSettings = S3SecureDownloads::$plugin->getSettings();
        $linkExpirationTime = $pluginSettings->linkExpirationTime;
        $expires = time() + $linkExpirationTime;

        $bucket = Craft::parseEnv($volume->getSettings()['bucket']);
        $keyname = $this->_getAssetPathWithSubfolder($event->asset);
        $getObjectOptions = [
            'Bucket' => $bucket,

            // If there’s a subfolder, need it here for the key,
            // otherwise you get a key is missing error
            'Key' => $keyname,
        ];

        if (isset($pluginSettings->forceFileDownload) && $pluginSettings->forceFileDownload) {
            $forceDownloadFilename = $event->asset->getFilename();
            if (isset($options['filename'])) {
                $forceDownloadFilename = $options['filename'];
            }

            // https://docs.aws.amazon.com/AmazonS3/latest/dev/RetrieveObjSingleOpPHP.html
            $getObjectOptions['ResponseContentDisposition'] = 'attachment; filename="' . $forceDownloadFilename . '"';
        }

        // https://stackoverflow.com/a/47337098/864799

        $command = $client->getCommand('GetObject', $getObjectOptions);

        try {
            $request = $client->createPresignedRequest($command, $expires);
            $url = (string) $request->getUri();
        } catch (S3Exception $exception) {
            $url = false;
        }

        if (!isset($url) || !$url) {
            // If new signing approach didn’t work…
            $url = $this->_manuallyBuildUrlSignatureV2($event->asset);
        }

        if ($this->hasEventHandlers(self::EVENT_AFTER_SIGN_URL)) {
            $this->trigger(self::EVENT_AFTER_SIGN_URL, $event);
        }

        return $url;
    }

    private function _getAssetPath($asset)
    {
        $filename = $asset->filename;
        if ($asset->folderPath) {
            $filename = $asset->folderPath . $asset->filename;
        }

        return $filename;
    }

    private function _getAssetPathWithSubfolder($asset)
    {
        $filename = $this->_getAssetPath($asset);

        $volume = $asset->getVolume()->getFs();
        $subfolder = $volume->subfolder;

        // Add slash to end of path, since subfolder may not have it
        // https://stackoverflow.com/a/9339669/864799
        // TODO Could replace some of this with Craft normalizePath()
        // https://docs.craftcms.com/api/v3/craft-helpers-filehelper.html#public-methods
        $urlPrefix = '';
        if ($subfolder) {
            $parseSubfolder = Craft::parseEnv($subfolder);
            $urlPrefix = rtrim($parseSubfolder, '/') . '/';
        }

        return $urlPrefix . $filename;
    }

    private function _manuallyBuildUrlSignatureV2($asset)
    {
        $baseAssetPath = $this->_getAssetPathWithSubfolder($asset);
        $sourceType = $asset->volume;
        $assetSettings = $sourceType->getAttributes();
        $awsSettings = isset($assetSettings['settings']) ? $assetSettings['settings'] : $assetSettings;
        $bucketName = Craft::parseEnv($awsSettings['bucket']);

        $keyId = Craft::parseEnv($awsSettings['keyId']);

        $secretKey = Craft::parseEnv($awsSettings['secret']);
        $pluginSettings = S3SecureDownloads::$plugin->getSettings();
        $linkExpirationTime = $pluginSettings->linkExpirationTime;
        $forceDownload = $pluginSettings->forceFileDownload;

        $expires = time() + $linkExpirationTime;

        // S3 Signed URL creation
        $headers = [];

        if ($forceDownload) {
            $headers['response-content-disposition'] = 'attachment; filename=' . $this->_getAssetPath($asset);
        }

        $resource = str_replace(['%2F', '%2B'], ['/', '+'], rawurlencode($baseAssetPath));

        // Remove possible leading slash
        if ($resource[0] == '/') {
            $resource = ltrim($resource, $resource[0]);
        }

        $string_to_sign = "GET\n\n\n{$expires}\n/{$bucketName}/{$resource}";

        if ($assetSettings['hasUrls']) {
            $base_url = Craft::parseEnv($assetSettings['url']);

            // Remove possible duplicate trailing slash
            $base_url = rtrim($base_url, '/');

            $final_url = "{$base_url}/{$resource}?";
        } else {
            $final_url = "https://{$bucketName}.s3.amazonaws.com/{$resource}?";
        }

        $append_char = '?';
        foreach ($headers as $header => $value) {
            $final_url .= $header . '=' . urlencode($value) . '&';
            $string_to_sign .= $append_char . $header . '=' . $value;
            $append_char = '&';
        }

        $signature = urlencode(base64_encode(hash_hmac('sha1', $string_to_sign, $secretKey, true)));

        $final_url = $final_url . "AWSAccessKeyId=$keyId&Signature=$signature&Expires=$expires";

        return $final_url;
    }
}

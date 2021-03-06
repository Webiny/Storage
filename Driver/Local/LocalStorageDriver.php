<?php
/**
 * Webiny Framework (http://www.webiny.com/framework)
 *
 * @copyright Copyright Webiny LTD
 */

namespace Webiny\Component\Storage\Driver\Local;

use Webiny\Component\StdLib\StdObject\ArrayObject\ArrayObject;
use Webiny\Component\Storage\Driver\DriverInterface;
use Webiny\Component\Storage\Driver\AbsolutePathInterface;
use Webiny\Component\Storage\Driver\DirectoryAwareInterface;
use Webiny\Component\Storage\Driver\SizeAwareInterface;
use Webiny\Component\Storage\Driver\TouchableInterface;
use Webiny\Component\Storage\StorageException;
use Webiny\Component\StdLib\StdObject\StringObject\StringObject;

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}

/**
 * Local storage
 *
 * @package   Webiny\Component\Storage\Driver\Local
 */
class LocalStorageDriver implements DirectoryAwareInterface, DriverInterface, SizeAwareInterface, AbsolutePathInterface, TouchableInterface
{

    protected $dateFolderStructure;
    protected $recentKey = null;
    protected $directory;
    protected $publicUrl;
    protected $create;

    /**
     * Constructor
     *
     * @param array|ArrayObject $config
     *
     * @throws StorageException
     */
    public function __construct($config)
    {
        if(is_array($config)){
            $config = new ArrayObject($config);
        }

        if(!$config instanceof ArrayObject){
            throw new StorageException('Storage driver config must be an array or ArrayObject!');
        }

        $this->helper = LocalHelper::getInstance();
        $this->directory = $this->helper->normalizeDirectoryPath($config->key('Directory', '', true));
        $this->publicUrl = $config->key('PublicUrl', '', true);
        $this->dateFolderStructure = $config->key('DateFolderStructure', false, true);
        $this->create = $config->key('Create', false, true);
    }

    /**
     * @inheritdoc
     */
    public function getTimeModified($key)
    {
        $this->recentKey = $key;

        if ($this->keyExists($key)) {
            return filemtime($this->buildPath($key));
        }

        return false;
    }

    /**
     * @inheritdoc
     */
    public function getSize($key)
    {
        $this->recentKey = $key;
        if ($this->keyExists($key)) {
            return filesize($this->buildPath($key));
        }

        return false;
    }

    /**
     * @inheritdoc
     */
    public function touchKey($key)
    {
        $this->recentKey = $key;

        return touch($this->buildPath($key));
    }

    /**
     * @inheritdoc
     */
    public function renameKey($sourceKey, $targetKey)
    {
        $this->recentKey = $sourceKey;
        if ($this->keyExists($sourceKey)) {
            $targetPath = $this->buildPath($targetKey);
            $this->helper->ensureDirectoryExists(dirname($targetPath), true);

            return rename($this->buildPath($sourceKey), $targetPath);
        }
        throw new StorageException(StorageException::FILE_NOT_FOUND);
    }

    /**
     * @inheritdoc
     */
    public function getContents($key)
    {
        $this->recentKey = $key;
        $data = file_get_contents($this->buildPath($key));
        if ($data === false) {
            throw new StorageException(StorageException::FAILED_TO_READ);
        }

        return $data;
    }

    /**
     * @inheritdoc
     */
    public function setContents($key, $contents, $append = false)
    {
        if ($this->dateFolderStructure) {
            if (!preg_match('#^\d{4}/\d{2}/\d{2}/#', $key)) {
                $key = new StringObject($key);
                $key = date('Y' . DS . 'm' . DS . 'd') . DS . $key->trimLeft(DS);
            }
        }
        $this->recentKey = $key;

        $path = $this->buildPath($key);
        $this->helper->ensureDirectoryExists(dirname($path), true);

        return file_put_contents($path, $contents, $append ? FILE_APPEND : null);
    }

    /**
     * @inheritdoc
     */
    public function keyExists($key)
    {
        $this->recentKey = $key;

        return file_exists($this->buildPath($key));
    }


    /**
     * Returns an array of all keys (files and directories)
     *
     * @param string   $key (Optional) Key of a directory to get keys from. If not set - keys will be read from the storage root.
     *
     * @param bool|int $recursive (Optional) Read all items recursively. Pass integer value to specify recursion depth.
     *
     * @return array
     */
    public function getKeys($key = '', $recursive = false)
    {
        if ($key != '') {
            $key = ltrim($key, DS);
            $key = rtrim($key, DS);
            $path = $this->directory . DS . $key;
        } else {
            $path = $this->directory;
        }

        if (!is_dir($path)) {
            return [];
        }

        if ($recursive) {
            try {
                $config = \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS;
                $directoryIterator = new \RecursiveDirectoryIterator($path, $config);
                $iterator = new \RecursiveIteratorIterator($directoryIterator);
                if (is_int($recursive) && $recursive > -1) {
                    $iterator->setMaxDepth($recursive);
                }
            } catch (\Exception $e) {
                $iterator = new \EmptyIterator;
            }
            $files = iterator_to_array($iterator);
        } else {
            $files = [];
            $iterator = new \DirectoryIterator($path);
            foreach ($iterator as $fileinfo) {
                $name = $fileinfo->getFilename();
                if ($name == '.' || $name == '..') {
                    continue;
                }
                $files[] = $fileinfo->getPathname();
            }
        }

        $keys = [];


        foreach ($files as $file) {
            $keys[] = $this->helper->getKey($file, $this->directory);
        }
        sort($keys);


        return $keys;
    }

    /**
     * @inheritdoc
     */
    public function deleteKey($key)
    {
        $this->recentKey = $key;
        $path = $this->buildPath($key);

        if ($this->isDirectory($key)) {
            return @rmdir($path);
        }

        return @unlink($path);
    }

    /**
     * @inheritdoc
     */
    public function getAbsolutePath($key)
    {
        $this->recentKey = $key;

        return $this->buildPath($key);
    }

    /**
     * @inheritdoc
     */
    public function getURL($key)
    {
        $key = str_replace('\\', '/', $key);

        return $this->publicUrl . '/' . ltrim($key, "/");
    }


    /**
     * @inheritdoc
     */
    public function getRecentKey()
    {
        return $this->recentKey;
    }

    /**
     * @inheritdoc
     */
    public function isDirectory($key)
    {
        return is_dir($this->buildPath($key));
    }

    private function buildPath($key)
    {
        $path = $this->helper->buildPath($key, $this->directory, $this->create);
        if (strpos($path, $this->directory) !== 0) {
            throw new StorageException(StorageException::PATH_IS_OUT_OF_STORAGE_ROOT, [
                    $path,
                    $this->directory
                ]);
        }

        return $path;
    }

    /**
     * @inheritDoc
     */
    public function createDateFolderStructure()
    {
        return $this->dateFolderStructure;
    }
}
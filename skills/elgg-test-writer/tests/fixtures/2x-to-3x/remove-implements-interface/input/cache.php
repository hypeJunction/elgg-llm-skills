<?php

namespace MyPlugin;

use Elgg\Cache\Pool;
use Serializable;

class FileCache implements Pool, Serializable
{
    public function get($key, callable $callback)
    {
        return $callback();
    }

    public function invalidate($key)
    {
    }

    public function serialize()
    {
        return '';
    }

    public function unserialize($data)
    {
    }
}

class ExportHelper implements \Exportable
{
    public function export()
    {
        return [];
    }
}

class ImportHelper implements \Importable
{
    public function import()
    {
        return true;
    }
}

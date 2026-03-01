<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Storage;

class JsonStore
{
    protected $table;
    protected $path;

    public function __construct($table)
    {
        $this->table = $table;
        $this->path = storage_path("json_data/{$table}.json");
        $this->ensureFileExists();
    }

    protected function ensureFileExists()
    {
        if (!file_exists($this->path)) {
            file_put_contents($this->path, json_encode([]));
        }
    }

    public function all()
    {
        $content = file_get_contents($this->path);
        return json_decode($content, true) ?? [];
    }

    public function find($id)
    {
        $data = $this->all();
        $key = array_search($id, array_column($data, 'id'));
        return ($key !== false) ? $data[$key] : null;
    }

    public function insert($record)
    {
        $data = $this->all();
        // Auto increment ID if not present
        if (!isset($record['id'])) {
            $maxId = 0;
            foreach ($data as $item) {
                if (isset($item['id']) && $item['id'] > $maxId) {
                    $maxId = $item['id'];
                }
            }
            $record['id'] = $maxId + 1;
        }

        $record['created_at'] = date('Y-m-d H:i:s');
        $record['updated_at'] = date('Y-m-d H:i:s');

        $data[] = $record;
        $this->save($data);
        return $record;
    }

    public function update($id, $updates)
    {
        $data = $this->all();
        $key = array_search($id, array_column($data, 'id'));

        if ($key !== false) {
            $data[$key] = array_merge($data[$key], $updates);
            $data[$key]['updated_at'] = date('Y-m-d H:i:s');
            $this->save($data);
            return $data[$key];
        }
        return null;
    }

    public function where($field, $value)
    {
        $data = $this->all();
        return array_values(array_filter($data, function ($item) use ($field, $value) {
            return isset($item[$field]) && $item[$field] == $value;
        }));
    }

    public function delete($id)
    {
        $data = $this->all();
        $key = array_search($id, array_column($data, 'id'));

        if ($key !== false) {
            array_splice($data, $key, 1);
            $this->save($data);
            return true;
        }
        return false;
    }

    protected function save($data)
    {
        file_put_contents($this->path, json_encode($data, JSON_PRETTY_PRINT));
    }
}

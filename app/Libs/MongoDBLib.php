<?php

namespace App\Libs;

use MongoDB\Client;





class MongoDBLib
{

    protected $collection;
    protected $db;
    public function __construct()
    {
        $client = new Client(env('MONGODB_CLIENT_URL'));
        $this->db = $client->{env('MONGODB_DATABASE')};
    }

    public function getCollections()
    {
        return $this->db->listCollections();
    }


    public function dropCollection($collection)
    {
        $this->db->{$collection}->drop();
    }

    public function collection($collection)
    {
        $this->collection = $this->db->{$collection};
        return $this;
    }

    public function createIndex($key, $options = [])
    {
        $this->collection->createIndex([$key => 1], $options);
    }

    public function setIndexes($indexes)
    {
        foreach ($indexes as $index) {
            $this->setIndex($index['key'], isset($index['options']) ? $index['options'] : []);
        }
    }

    public function count($filter = [], $options = [])
    {
        return $this->collection->count($filter, $options);
    }

    public function findOne($filter)
    {
        $cursor = $this->collection->findOne($filter);
        return iterator_to_array($cursor);
    }

    public function dropIndexes()
    {
        $this->collection->dropIndexes();
    }

    public function dropIndex($index)
    {
        $this->collection->dropIndex($index);
    }




    public function find($query = [], $options = [])
    {
        $cursor = $this->collection->find($query, $options);
        return iterator_to_array($cursor);
    }

    public function insertOne($input)
    {
        $result = $this->collection->insertOne($input);
        return $result->getInsertedId();
    }

    public function getIndexes()
    {
        return iterator_to_array($this->collection->listIndexes());
    }

    public function insertMany($input, $options = [])
    {
        if (empty($options)) {
            $options = [
                'ordered' => false,
                'writeConcern' => new \MongoDB\Driver\WriteConcern(0)
            ];
        }

        $result = $this->collection->insertMany($input, $options);
        return $result->getInsertedIds();
    }

    public function update($filter, $input, $options = [])
    {
        $this->collection->updateMany(
            $filter,
            ['$set' => $input],
            $options
        );
    }

    public function delete($filter)
    {
        $this->collection->deleteMany($filter);
    }
}

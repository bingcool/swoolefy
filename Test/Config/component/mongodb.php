<?php

declare(strict_types=1);

use Swoolefy\Library\Mongodb\MongodbModel;

return [
    'mongodb' => static function (): MongodbModel {
        return new MongodbModel([
            'uri' => (string) (getenv('MONGO_URI') ?: ''),
            'database' => (string) (getenv('MONGO_DB') ?: 'swoolefy_test'),
            'id_key' => '_id',
            'object_id' => true,
        ]);
    },
];

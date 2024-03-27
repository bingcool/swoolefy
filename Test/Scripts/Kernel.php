<?php

namespace Test\Scripts;

class Kernel
{
    public $commands = [
        'gen:mysql:schema' => [GenerateMysql::class, 'generate'],
        'gen:pgsql:schema' => [GeneratePg::class, 'generate'],
        'fixed:user:name' => [FixedUser::class, 'fixName']
    ];


}
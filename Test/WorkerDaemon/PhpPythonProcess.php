<?php

namespace Test\WorkerDaemon;

use PyCore;
use PyDict;
use PyList;

class PhpPythonProcess extends \Swoolefy\Worker\AbstractWorkerProcess
{

    public function run()
    {
        /**
         * @var \python\os $os
         */
        $os = \python\os::import();
        $un = $os->uname();
        $name = $un->sysname;
        $name = strval($name);
        var_dump($name);


//        $docx = PyCore::import('docx.api');
//        $document = $docx->Document();
//        $document->add_paragraph('Hello World!'.rand(1,1000));
//        $document->save('hello.docx');


    }
}
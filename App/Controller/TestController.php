<?php
namespace App\Controller;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\ZModel;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\MGeneral;
use Swoolefy\Core\MTime;

class TestController extends BController {

	public function __construct() {
		parent::__construct();
	}	

	public function test($name,$num) {
		$driverOptions = [
			'typeMap' => [ 'array' => 'MongoDB\Model\BSONArray', 'document' => 'MongoDB\Model\BSONArray', 'root' => 'MongoDB\Model\BSONArray']
		];

		// $mongodb = new \MongoDB\Client('mongodb://127.0.0.1:27017', $uriOptions = [], $driverOptions);
		// $collection = $mongodb->mytest->user;
		$filter1 = ['age' => ['$gt'=>555]];
		$filter2 = ['age' => ['$gt' => (int) $_GET['age']]];
		// $filter2 = ['age' => (int) $_GET['age']];
		$options = array(
			'projection' => array( 'age' => 1, 'name' => 1), // 指定返回哪些字段
			// 'limit' => 100, // 指定返回的条数
			// 'skip' => 0, // 指定起始位置
		);
		
		$insert = [
			[
				'insertOne' =>[ 
					[
						'name'=>'bingcool'.rand(1,100),
						'age'=>42+rand(1,100),
						'sex'=>1
					]
				],
				// 'deleteOne' => [
				// 	[
				// 		'name'=>'bingcool70'
				// 	]
				// ],
			]
		];

		$collection1 = Application::$app->mongodb->collection('user');
		// $collection1->bulkWrite($insert);

		$insert = [
			[
				'name'=>'bingcoolhuang',
				'age'=>555 + rand(1,100),
				'sex'=>1
			],
			[
				'name'=>'bingcoolhuang2',
				'age'=>555 + rand(1,100),
				'sex'=>1
			]	
		];

		$res = $collection1->clear()->insert($insert);
		dump($res);

		$data3 = $collection1->clear()->field('name,age,sex')->where($filter1)->limit(0,100)->find();
		dump($data3);

		$this->assign('name', $value);
		$this->assign('books', $books);
		// MGeneral::xhprof();
		$this->display('test.html');
	}

	public function testajax() {
		$collection1 = Application::$app->mongodb->collection('user');
		// $collection1->bulkWrite($insert);

		// $insert = [
		// 	'name'=>'bingcoolhuang',
		// 	'age'=>555 + rand(1,100),
		// 	'sex'=>1
		// ];
		// $filter = ['age'=>['$in'=>[90,120]]];
		
		// $filter = ['$and'=>[
		// 		['name'=>'bingcoolhuang'],
		// 		['age'=>['$gt'=>630]]
		// 	]
		// ];
		$filter = ['score' => ['$exists'=>true]];
		$res = $collection1->clear()->where($filter)->find();
		// dump($res);
		// foreach($res[0]['score'] as $k=>$value) {
		// 	dump($k);
		// 	dump($value);
		// }

		$res = $collection1->clear()->getTypeMap();
		dump($res);
		

	}

	public function insertOne() {
		$data = [
			'name' => 'bingcool',
			'score'=>[
				'shuxue'=>99,
				'yuwen'=>99,
				'yingyu'=>98,
			],
			'sex'=>1
		];

		$collection1 = Application::$app->mongodb->collection('user');
		$res = $collection1->clear()->insertOne($data);
		dump($res);

	}


	public function mytest() {
		$data = $this->getModel()->getTest();
		return $data;
	}
}
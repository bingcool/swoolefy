<?php
namespace App\Controller;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\ZModel;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\MGeneral;
use Swoolefy\Core\MTime;
use Swoolefy\Core\Task\AsyncTask;
use swoole_process;

class TestController extends BController {

	public $test;

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
		foreach($res[0]['score'] as $k=>$value) {
			var_dump($value);
		}

	}

	public function mytest() {
		$data = $this->getModel()->getTest();
		$res1 = yield $this->test1();
		dump($res1);
		$res2 = yield 'mmm';
		dump($res2);
	}

	public function test1() {
		sleep(2);
		$collection1 = Application::$app->mongodb->collection('user');
		$this->test = $collection1;
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

	public function task() {
		dump('kkkkkk');
		
	}

	public function http() {
		\Swoole\Async::dnsLookup("www.baidu.com", function ($domainName, $ip) {
			$cli = new \swoole_http_client($ip, 80);
			$cli->setHeaders([
				'Host' => $domainName,
				"User-Agent" => 'Chrome/49.0.2587.3',
				'Accept' => 'text/html,application/xhtml+xml,application/xml',
				'Accept-Encoding' => 'gzip',
			]);
			$cli->get('/index.html', function ($cli) {
				echo "Length: " . strlen($cli->body) . "\n";
				echo $cli->body;
			});
		});
		var_dump('mmmmmmmmmmm');
	}

	public function mail() {
		$task_id = AsyncTask::registerTask('AsyncTask/test');
		dump($task_id);
		if($task_id !== false)  {
			dump('success!');
		}else {
			dump('failed');
		}
		// $mailer = Application::$app->mailer;
		// $mailer->message = [
		// 	//邮箱主题
		// 	"subject"=>"test",
		// 	//发送者邮箱与定义的名称，邮箱与上面定义的user_name这里必须一致
		// 	"from"   =>["13560491950@163.com"=>"bingcool"],
		// 	//定义多个收件人和对应的名称
		// 	"to"     =>['2437667702@qq.com'=>"bingcool","bingcoolhuang@gmail.com"=>"dabing"],
		// 	//定义邮件的内容，格式可以包含html
		// 	"body"   =>"<p>this is a mail</p>",
		// 	// body文档类型
		// 	"mime"   =>"text/html",
		// 	//定义要上传的附件，可以多个，附件的大小，由代理的邮件服务器定义提供,key值代表是文件路径，name值代表是发送后的文件显示的别名，如果没设置name值，则以原文件名作为别名
		// 	"attach" =>["/home/wwwroot/default/swoolefy/score/Test/test.docx"=>"my.docx"],
		// ];

		// $mailer->sendEmail();

	}

	public function asyncStaticCallTask() {
		AsyncTask::registerStaticCallTask(['App/Controller/AsyncTaskController', 'asyncStaticTest']);
	}

	public function stats() {
		$arr = ['34565'=>'kkkkk','7777'=>'kllllllll'];
		$arr1= array();
	}

	public function mysql() {
		$db = Application::$app->db;
		// $fields = $db->getFields('test');
		// dump($fields);
		// dump($db);
		// $num = $db->query('INSERT INTO test(name,sex,phone)  VALUES("cool","1","66666666666") ');
		// dump($num);
		$res = $db->query('select name from test');
		$start = 1;
		$offset =10;
		
		dump($res);
	}

	public function viewtest() {
		dump($this->view->test);
	}


	
}
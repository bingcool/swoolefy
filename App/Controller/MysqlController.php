<?php 
namespace App\Controller;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\ZModel;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\MGeneral;
use Swoolefy\Core\MTime;
use swoole_process;

class MysqlController extends BController {

	public function test() {
		$result = $this->db->table('user')->find();
		$result1 = $this->db->query('select * from user where id=:id',['id'=>8]);
		$data = ['username'=>'huangzengbing','sex'=>1];
		$num = $this->db->table('user')->insert($data);
		$mdata = [
			$data,$data,$data
		];
		$num1 = $this->db->table('user')->insertAll($mdata);
		dump($num);
		dump($num1);
		dump($result);
		dump($result1);
		dump($this->db->getLastSql());
		dump($this->db->table('user')->getTableFields());

		$bookdata = [
			'bookname'=>'细说php',
			'dec' => '入门级php开发'
		];
		$book_num = $this->db->table('book')->insert($bookdata);
		dump($book_num);
		dump($this->db->getLastSql());
		dump(\Think\Db::$executeTimes);
		dump($this->db->table('user')->getSqlLog());
	}


}
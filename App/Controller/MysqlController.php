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

class MysqlController extends BController {

	public function test() {
		$db = Application::$app->db;
		$books = $db->table('books');
		$fields = $books->getFields();

		$sql = 'INSERT INTO  `books` (`id`,`book_name` ,`author`,`description`) VALUES (NULL ,  "php1",  "mmmmm",  "lnmp学习")';
		$id = $db->execute($sql);

		dump($id);
	}

	public function get() {
		$db = Application::$app->db;

		// $data = $db->table('books')
		// ->field('book_name')
		// ->where('id>:id',['id'=>1])
		// ->whereOr('book_name=:php1',['php1'=>'php1'])
		// ->findAll();
		// dump($data);
		
		
		// $data = $db->table('books')
		// ->field('id, book_name AS name')
		// ->where('book_name like :like or id not in (2,3,4)',['like'=>'%php%'])
		// ->findAll();
		// dump($data);
		
		// $data = $db->table('books AS b')
		// ->field('b.id, b.book_name,u.sex')
		// ->join('user_book AS u','b.id=u.book_id')
		// ->findAll();
		
		
		// $sql = 'SELECT b.id,b.book_name FROM books AS b INNER JOIN user_book AS u ON b.id=u.book_id';
		// $data = $db->query($sql);
		// 
		
		$data = $db->table('books AS b')
		->field('b.id, b.book_name,u.sex')
		->join('user_book AS u','b.id=u.book_id')
		->getColumn('id,sex','id');

		dump($db->getLastSql());
		dump($data);
	}



}
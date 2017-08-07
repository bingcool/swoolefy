<?php
namespace Swoolefy\Test;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
class Test {
	public function run() {
		echo "hello swoolefy";
	}

	public function log() {
		$log = new Logger('name');
		$log->pushHandler(new StreamHandler(__DIR__.'/test.log', Logger::WARNING));

		// add records to the log
		$log->warning('Foo');
		$log->error('Bar');
	}

	public function testMail() {
		$transport = (new \Swift_SmtpTransport('smtp.163.com', 25))
		->setUsername('13560491950@163.com')
		->setPassword('aa2437667702');

		$mailer = new \Swift_Mailer($transport);

		$message = (new \Swift_Message())
		 // Give the message a subject
		  ->setSubject('Your subject')

		  // Set the From address with an associative array
		  ->setFrom(['13560491950@163.com' => 'bingbing'])

		  // Set the To addresses with an associative array (setTo/setCc/setBcc)
		  ->setTo(['2437667702@qq.com'=>"bingcool","bingcoolhuang@gmail.com"=>"dabing"])

		  // Give it a body
		  ->setBody('Here is the message itself')

		  // And optionally an alternative body
		  ->addPart('<q>Here is the messaggggggggggggggggge itself</q>', 'text/html')
		  
		  ->attach(\Swift_Attachment::fromPath("/home/wwwroot/default/swoolefy/score/Test/test.docx")->setFilename('my.docx'))

		  ->attach(\Swift_Attachment::fromPath("/home/wwwroot/default/swoolefy/score/Test/test.log")->setFilename('my.log'));
		  
		  try{
		  		$mailer->send($message);
		  }catch(\Swift_IoException $e) {
		  		echo "failed";
		  }
		  
		  
	}
}
<?php
namespace Test\Controller;

use Swoolefy\Core\Application;

class WsController extends \Swoolefy\Core\Controller\BController
{
    /**
     * @return void
     */

    public function test1()
    {
        Application::getApp()->response->write('<!DOCTYPE HTML>
<html>
   <head>
   <meta charset="utf-8">
   <title>菜鸟教程(runoob.com)</title>
    
      <script type="text/javascript">
         function WebSocketTest()
         {
            if ("WebSocket" in window)
            {  
               // 打开一个 web socket
               var ws = new WebSocket("ws://127.0.0.1:9502/");
                
               ws.onopen = function()
               {                  
                  var rand = Math.random() * (100 - 1) + 1;
                  var data = { "name": "bingcool", "sex": "man", "rand": rand};
                  ws.send("Chat::mychat::"+ JSON.stringify(data));
                  alert("数据发送中...");
               };
                
               ws.onmessage = function (evt) 
               { 
                  var received_msg = evt.data;
                  alert("数据已接收，返回的数据:" + received_msg);
               };
                
               ws.onclose = function()
               { 
                  // 关闭 websocket
                  alert("连接已关闭..."); 
               };
            }
            
            else
            {
               // 浏览器不支持 WebSocket
               alert("您的浏览器不支持 WebSocket!");
            }
         }
      </script>
        
   </head>
   <body>
   
      <div id="sse">
         <a href="javascript:WebSocketTest()">运行 WebSocket</a>
      </div>
      
   </body>
</html>');

    }
}
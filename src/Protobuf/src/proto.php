<?php
// 包名, 完全限定命名空间
$package = "App.Module.Book.Protobuf";
// 当前模块的proto文件名称
$proto_name = "book.proto";

// 当前目录
$current_dir = getcwd();
// php命名空间
$name_space = str_replace('.', '/', $package);
// 输出位置
$php_out = substr($current_dir, 0, strpos($current_dir, $name_space));
// protoc 执行命令
$proto_shell = "protoc -I={$current_dir} --php_out={$php_out} {$current_dir}/{$proto_name}";

exec($proto_shell);
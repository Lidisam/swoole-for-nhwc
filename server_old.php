<?php
//连接本地的 Redis 服务
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redis->set("fd", "[]");    //每次第一次执行都需要先清空reids里面的标识

$serv = new Swoole\Websocket\Server("0.0.0.0", 9502);


$serv->on('Open', function ($server, $req) use ($redis) {
    echo "\n connection open: " . $req->fd . "\n";
    $str = json_decode($redis->get("fd"), true);
    if ($str == "") $str = [];
    if (!in_array($req->fd, $str)) {
        array_push($str, $req->fd);
        $str = json_encode($str);
        $redis->set("fd", $str);
        print_r($redis->get("fd"));
    }
});

$serv->on('Message', function ($server, $frame) use ($redis) {
    echo "\n message: " . $frame->data . "\n";

    $str = json_decode($redis->get("fd"), true);
    foreach ($str as $key => $value) {
        // print_r($value);
        if ($frame->fd != $value) {
            print_r($value);
            $server->push($value, "客户{$value}:" . $frame->data);
        }
    }
});

$serv->on('Close', function ($server, $fd) use ($redis) {
    echo "\n connection close: \n" . $fd;
    $str = json_decode($redis->get("fd"), true);
    $point = array_keys($str, $fd, true);  //search key
    array_splice($str, $point['0'], 1);  //delete array
    $redis->set("fd", $str);
});

$serv->start();
<?php

/**
 * Created by PhpStorm.
 * User: lisam
 * Date: 2017/2/27
 * Time: 19:26
 */
class WebSocket
{
    /**
     * 配置信息
     * @var
     */
    private $server;
    private $port;
    private $reids;

    function __construct($port)
    {
        $this->port = $port;
        $this->redis = new Redis();
        $this->init();
    }

    /**
     * 初始化服务
     */
    public function init()
    {
        /**连接本地的 Redis 服务*/
        $this->redis->connect('127.0.0.1', 6379);
        $this->redis->set("fd", "[]");    //每次第一次执行都需要先清空reids里面的标识

        /*********启动服务**********/
        $this->server = $server = new swoole_websocket_server('0.0.0.0', $this->port);
//        $server->set([
//            'task_worker_num' => 2
//        ]);
        $this->server->on('open', [$this, 'open']);
        $this->server->on('message', [$this, 'message']);
        $this->server->on('close', [$this, 'close']);
//        $server->on('task', [$this, 'task']);
//        $server->on('finish', [$this, 'finish']);
        $this->server->start();
    }

    /**
     * 监听连接
     * @param swoole_websocket_server $server
     * @param swoole_http_request $req
     */
    public function open(swoole_websocket_server $server, swoole_http_request $req)
    {
        echo "\n connection open: " . $req->fd . "\n";
        $str = json_decode($this->redis->get("fd"), true);
        if ($str == "") $str = [];
        if (!in_array($req->fd, $str)) {
            array_push($str, $req->fd);
            $str = json_encode($str);
            $this->redis->set("fd", $str);
        }

        $server->tick(1000, function () use ($server) {
//            $counter = 'llllll';
//            echo $counter . "\n";
        });
    }

    /**
     * 监听socket信息
     * @param swoole_websocket_server $server
     * @param swoole_websocket_frame $frame
     */
    public function message(swoole_websocket_server $server, swoole_websocket_frame $frame)
    {
        echo "\n message: " . $frame->data . "\n";
        $str = json_decode($this->redis->get("fd"), true);
        print_r($str);
        foreach ($str as $key => $value) {
            if ($frame->fd != $value) {
                $server->push($value, $frame->data);
            }
        }
    }


    /**
     * 监听连接断开
     * @param swoole_websocket_server $server
     * @param $fd
     */
    public function close(swoole_websocket_server $server, $fd)
    {
        echo "\n connection close: \n" . $fd;
        $str = json_decode($this->redis->get("fd"), true);
        $point = array_keys($str, $fd, true);  //search key
        array_splice($str, $point['0'], 1);  //delete array
        $this->redis->set("fd", $str);
    }

}

new WebSocket(9502);
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
    private $redis;

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
        $this->server->set([
            'daemonize' => true, //是否作为守护进程
        ]);
        $this->server->on('open', [$this, 'open']);
        $this->server->on('message', [$this, 'message']);
        $this->server->on('close', [$this, 'close']);
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
        if (!isset($str[$req->fd])) {
            $str[$req->fd] = [];
            $str = json_encode($str);
            $this->redis->set("fd", $str);
        }

        $server->tick(1000, function () use ($server) {
//            $counter = time();
//            echo substr($counter, -4) . "\t";
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
        /**用户登录处理**/
        $val = json_decode($frame->data, true);
        if ($val['status'] == '3') {
            $msgs = $this->getSign($val, $frame->fd);  //信号信息写入内存
            $frame->data = $msgs;
        }
        /**信号广播**/
        $str = json_decode($this->redis->get("fd"), true);
        //获取当前房间号所有用户信息
        foreach ($str as $key => $value) {
            if ($str[$frame->fd]['roomnum'] != $value['roomnum']) {
                unset($str[$frame->fd]);   //删除非本房间号的信息
            }
        }
        $str['status'] = "5";  //当前房间所有用户信息
        foreach ($str as $key => $value) {
            //房间号相同且不为当前用户
            if ($frame->fd != $key && $str[$frame->fd]['roomnum'] == $value['roomnum']) {
                $server->push($key, $frame->data);
            }
            $server->push($key, json_encode($str));   //广播当前用户房间信息
        }
    }


    /**
     * 监听连接断开
     * @param swoole_websocket_server $server
     * @param $fd
     */
    public function close(swoole_websocket_server $server, $fd)
    {
        echo "\n 连接关闭: \n" . $fd;
        $str = json_decode($this->redis->get("fd"), true);
        unset($str[$fd]);
        $this->redis->set("fd", json_encode($str));
    }

    /**
     * 设置返回信息格式
     * @param $data
     * @param $type
     * @param int $status
     * @return string
     */
    private function buildMsg($data, $type, $status = 200)
    {
        return json_encode([
            'status' => $status,
            'type' => $type,
            'data' => $data
        ]);
    }

    /**
     * 接收信号
     * @param $signMsgs
     * @param $fd
     * @return int
     */
    public function getSign($signMsgs, $fd)
    {
        $sign = $signMsgs['status'];
        switch ($sign) {
            case '3':
                $val = explode("#", $signMsgs['username']);
                $str = json_decode($this->redis->get("fd"), true);
                $str[$fd] = ['username' => $val['0'], 'roomnum' => $val['1']];
                $this->redis->set("fd", json_encode($str));
                return '{"status":"4", "username": "' . $val['0'] . '"}';   //上线信号
                break;
        }
    }

}

new WebSocket(9502);
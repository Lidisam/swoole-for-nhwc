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
        $this->redis->del("fds");
        $this->redis->set("fds", "[]");
        print_r($this->redis->get("fds"));


        /*********启动服务**********/
        $this->server = $server = new swoole_websocket_server('0.0.0.0', $this->port);
        $this->server->set([
//            'worker_num' => 2,
//            'daemonize' => true, //是否作为守护进程
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
        $str = json_decode($this->redis->get("fds"), true);
        if ($str == "") $str = [];
        if (!isset($str[$req->fd])) {
            $str[$req->fd] = [];
            $str = json_encode($str);
            $this->redis->set("fds", $str);
        }
        echo "\n ----------刚计入---------- \n";
        print_r($str);
        echo "\n -------------------- \n";

    }

    /**
     * 监听socket信息
     * @param swoole_websocket_server $server
     * @param swoole_websocket_frame $frame
     */
    public function message(swoole_websocket_server $server, swoole_websocket_frame $frame)
    {
//TODO:密码        ssh root@119.29.37.33
        echo "\n message: " . $frame->data . "\n";
        /**用户登录处理**/
        $val = json_decode($frame->data, true);
        if ($val['status'] == '3') {
            $msgs = $this->getSign($val, $frame->fd);  //信号信息写入内存
            $frame->data = $msgs;
            /**该用户个人状态信息广播**/
            $name = explode("#", $val['username']);
            $str = json_decode($this->redis->get("fds"), true);
            print_r($str);
            print_r($frame->fd);
            $counter = 0;
            foreach ($str as $key => $value) {
                var_dump($str);
                if ($str[$frame->fd]['roomnum'] == $value['roomnum']) {
                    $counter++;
                }
            }
            $server->push($frame->fd, '{"status":"6","username":"' . $name['0'] . '","counter":"' . $counter . '"}');
        }
        /**游戏开始信号**/
        if ($val['status'] == '7') {
            $str = json_decode($this->redis->get("fds"), true);

            //TODO:销毁方法：https://wiki.swoole.com/wiki/page/415.html
            $server->tick(1000, function ($id) use ($server, $str, $frame) {
                //TODO:这是重复部分，应合并~~~~~~~~~~~~~~~~~~~~~~~~~~
                if (!$this->redis->EXISTS("time_" . $frame->fd . "")) $this->redis->set("time_" . $frame->fd . "", (int)0);
                /**清除该定时器以及对应的计数器**/
                if ((int)$this->redis->get("time_" . $frame->fd . "") > 50) {
                    $server->clearTimer($id);
                    $this->redis->del("time_" . $frame->fd . "");
                }
                /**轮到谁玩广播，假设每人60秒-5秒休息时间**/
                foreach ($str as $key => $value) {
                    if ($str[$frame->fd]['roomnum'] != $value['roomnum']) {
                        unset($str[$key]);   //删除非本房间号的信息
                    }
                }
                /**确定当前画的玩家**/
                $counter = 1;   //用于确定当前是哪个在画
                $currentCounter = ceil($this->redis->get("time_" . $frame->fd . "") / 65);  //进一取整
                $curUser = null;  //用于存储当前正在画的玩家信息
                foreach ($str as $key => $value) {
                    if ($key != 'status') {
                        if ($counter == $currentCounter) {  //当前的画的玩家
                            $curUser = $value;
                            break;
                        }
                        $counter++;
                    }
                }
                foreach ($str as $key => $value) {
                    if ($key != 'status') {
                        $server->push((int)$key, '{"status":"8","time":"' . $this->redis->get("time_" . $frame->fd . "")
                            . '","username":"' . $curUser['username'] . '"}');
                        $counter++;
                    }
                }
                $this->redis->INCRBY("time_" . $frame->fd . "", 1);
            });


            $server->push($frame->fd, $frame->data);
        }
        /**游戏返回答案处理**/
        if ($val['status'] == '9') {
            $server->push($frame->fd, $frame->data);  //先给自己发一份
        }

        /**信号广播**/
        $str = json_decode($this->redis->get("fds"), true);
//        print_r($str);
        //获取当前房间号所有用户信息
        foreach ($str as $key => $value) {
            if ($str[$frame->fd]['roomnum'] != $value['roomnum']) {
                unset($str[$key]);   //删除非本房间号的信息
            }
        }
//        print_r($str);
        $str['status'] = "5";  //当前房间所有用户信息
        foreach ($str as $key => $value) {
            if ($key != 'status') {
                //房间号相同且不为当前用户
                if ($frame->fd != $key) {
                    $server->push((int)$key, $frame->data);
                }
                $server->push((int)$key, json_encode($str));   //广播当前用户房间信息
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
        echo "\n 连接关闭: \n" . $fd;
        $str = json_decode($this->redis->get("fds"), true);
        unset($str[$fd]);
        $this->redis->set("fds", json_encode($str));
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
                $str = json_decode($this->redis->get("fds"), true);
                $counter = count($str);
                $str[$fd] = ['username' => $val['0'], 'roomnum' => $val['1']];
                echo "\n ----------改写计入---------- \n";
                print_r($str);
                echo "\n -------------------- \n";
                $this->redis->set("fds", json_encode($str));
                return '{"status":"4", "username": "' . $val['0'] . '","counter":"' . $counter . '"}';   //上线信号
                break;
        }
    }

}

new WebSocket(9502);
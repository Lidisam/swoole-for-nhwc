<?php
include_once("./BaseController.php");

/**
 * Created by PhpStorm.
 * User: lisam
 * Date: 2017/2/27
 * Time: 19:26
 */
class WebSocket extends BaseController
{
    /**
     * 配置信息
     * @var
     */
    private $server;
    private $port;

    function __construct($port)
    {

        parent::__construct();
        $this->port = $port;
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
        /**设置数据库**/
        $sql = "set names utf8";
        mysqli_query($this->mysql, $sql);
        /*********启动服务**********/
        $this->server = $server = new swoole_websocket_server('0.0.0.0', $this->port);
        $this->server->set([
            'worker_num' => 2,
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
        $str = json_decode($this->redis->get("fd"), true);
        if ($str == "") $str = [];
        if (!isset($str[$req->fd])) {
            $str[$req->fd] = [];
            $str = json_encode($str);
            $this->redis->set("fd", $str);
        }

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
            /**该用户个人状态信息广播**/
            $name = explode("#", $val['username']);
            $str = json_decode($this->redis->get("fd"), true);
            $counter = 0;
            foreach ($str as $key => $value) {
                if ($str[$frame->fd]['roomnum'] == $value['roomnum']) {
                    $counter++;
                }
            }
            $server->push($frame->fd, '{"status":"6","username":"' . $name['0'] . '","counter":"' . $counter . '"}');
        }
        /**游戏开始信号**/
        if ($val['status'] == '7') {
            $this->playGame($server, $frame);
        }
        /**游戏返回答案处理**/
        if ($val['status'] == '9') {
            $server->push($frame->fd, $frame->data);  //先给自己发一份
        }
        /**信号广播**/
        $str = json_decode($this->redis->get("fd"), true);
        //获取当前房间号所有用户信息
        foreach ($str as $key => $value) {
            if ($str[$frame->fd]['roomnum'] != $value['roomnum']) {
                unset($str[$key]);   //删除非本房间号的信息
            }
        }
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
        $str = json_decode($this->redis->get("fd"), true);
        unset($str[$fd]);
        $this->redis->set("fd", json_encode($str));
    }


    /**
     * 处理游戏进行时操作
     * @param $server
     * @param $frame
     */
    public function playGame($server, $frame)
    {
        $str = json_decode($this->redis->get("fd"), true);
        //TODO:销毁方法：https://wiki.swoole.com/wiki/page/415.html
        /**获取当前游戏总时间**/
        $counter = 0;
        foreach ($str as $key => $value) {
            if ($str[$frame->fd]['roomnum'] == $value['roomnum']) {
                $counter++;
            }
        }
        $total = (int)($this->singleTime) * $counter;
        $server->tick(1000, function ($id) use ($server, $str, $frame, $total) {
            if (!$this->redis->EXISTS("time_" . $frame->fd . "")) {
                $this->redis->set("time_" . $frame->fd . "", (int)0);
            }
            /**清除该定时器以及对应的计数器**/
            if ((int)$this->redis->get("time_" . $frame->fd . "") > $total) {
                $server->clearTimer($id);
                $this->redis->del("time_" . $frame->fd . "");
                $this->redis->del("tips_" . $frame->fd . "");
            }
            /**轮到谁玩广播，假设每人60秒-5秒休息时间**/
            foreach ($str as $key => $value) {
                if ($str[$frame->fd]['roomnum'] != $value['roomnum']) {
                    unset($str[$key]);   //删除非本房间号的信息
                }
            }
            /**确定当前画的玩家**/
            $counter = 1;   //用于确定当前是哪个在画
            $currentCounter = ceil($this->redis->get("time_" . $frame->fd . "") / $this->singleTime);  //进一取整
            $curUser = null;  //用于存储当前正在画的玩家信息
            $flag = 0;   //用于标识是否取出新的数据
            foreach ($str as $key => $value) {
                if ($key != 'status') {
                    if ($counter == $currentCounter) {  //当前的画的玩家
                        $curUser = $value;
                        //如果是整数则取出一个数据
                        if (is_int($this->redis->get("time_" . $frame->fd . "") / $this->singleTime)) {
                            $flag = 1;
                        } else if ($this->redis->get("time_" . $frame->fd . "") == 1) {
                            $result = mysqli_query($this->mysql, "SELECT * FROM yhwc ORDER BY rand() limit 1");
                            $row = mysqli_fetch_row($result);
                            print_r(json_encode($row));
                            $this->redis->set("tips_" . $frame->fd . "", json_encode($row));  //题目
                        }
                        break;
                    }
                    $counter++;
                }
            }
            foreach ($str as $key => $value) {
                if ($key != 'status') {
                    $row = json_decode($this->redis->get("tips_" . $frame->fd . ""), true);  //题目
                    $server->push((int)$key, '{"status":"8","time":"' . $this->redis->get("time_" . $frame->fd . "")
                        . '","username":"' . $curUser['username'] . '", "name": "' . $row['1'] . '", "tips": "' . $row['2'] . '"}');
                    $counter++;
                }
            }
            /**如果标识为1代表取出新的数据**/
            if ($flag == 1) {
                $result = mysqli_query($this->mysql, "SELECT * FROM yhwc ORDER BY rand() limit 1");
                $row = mysqli_fetch_row($result);
                print_r(($row));
                $this->redis->set("tips_" . $frame->fd . "", json_encode($row));  //题目
            }
            $this->redis->INCRBY("time_" . $frame->fd . "", 1);
        });
        $server->push($frame->fd, $frame->data);
    }


}

new WebSocket(9502);
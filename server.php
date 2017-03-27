<?php
/**
 * Created by PhpStorm.
 * User: lisam
 * Date: 2017/2/27
 * Time: 19:26
 */
include_once("./BaseController.php");
include_once("./Module/PlayGameTrait.php");


class WebSocket extends BaseController
{
    use \Module\PlayGameTrait;

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
        /**用户登录处理**/
        $val = json_decode($frame->data, true);
        if ($val['status'] == '3') {
            $msgs = $this->getSign($val, $frame->fd);  //信号信息写入内存
            $frame->data = $msgs;
            /**该用户个人状态信息广播**/
            $name = explode("#", $val['username']);
            $counter = 0;
            $str = json_decode($this->redis->get("fd"), true);
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
        /**信号广播**/
        //获取当前房间号所有用户信息
        foreach ($str as $key => $value) {
            if ($str[$frame->fd]['roomnum'] != $value['roomnum']) {
                unset($str[$key]);   //删除非本房间号的信息
            }
        }
        /**换人画信号重置，将is_mark重置为0   不能写在这里保存，会把信息弄丢失一部分，或者不要上的面的，直接自己再写一个 **/
        if ($val['status'] == '10') {   //只需重置就好了
            $str2 = json_decode($this->redis->get("fd"), true);
            foreach ($str2 as $k => $v) {
                if ($str2[$frame->fd]['roomnum'] == $v['roomnum']) {
                    $str2[$k]['is_mark'] = 0;  //重置
                }
            }
            $this->redis->set("fd", json_encode($str2));
        }
        /**游戏返回答案处理**/
        $flag = false;
        if ($val['status'] == '9') {
            foreach ($str as $k => $v) {
                if ($this->redis->EXISTS('tips_' . $k)) {
                    $question = json_decode($this->redis->get("tips_" . $k), true);
                    if ($question['1'] == $val['answer']) {
                        $flag = true;
                        break;
                    }
                }
            }
            $this->returnAnswer((int)$frame->fd, $frame, $flag, $server, $val, $str);
        }
        $str['status'] = "5";  //当前房间所有用户信息
        foreach ($str as $key => $value) {
            if ($key != 'status') {
                //房间号相同且不为当前用户
                if ($frame->fd != $key) {
                    $this->returnAnswer((int)$key, $frame, $flag, $server, $val, $str);
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
}

new WebSocket(9502);
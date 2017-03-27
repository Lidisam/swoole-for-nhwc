<?php

/**
 * Created by PhpStorm.
 * User: lisam
 * Date: 2017/3/19
 * Time: 9:26
 */
class BaseController
{

    protected $redis;
    protected $mysql;
    protected $singleTime;   //单个玩家每一轮的时间


    function __construct()
    {
        $this->redis = new Redis();
        $this->mysql = mysqli_connect('localhost', 'root', 'ihat21036ihat', 'yhwc') or die('Unale to connect');
        $this->singleTime = 60;
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
                $counter = count($str);
                $str[$fd] = ['username' => $val['0'], 'roomnum' => $val['1'], 'mark' => 0, 'is_mark' => 0];
                $this->redis->set("fd", json_encode($str));   //is_mark用于记录是否已经回答了
                return '{"status":"4", "username": "' . $val['0'] . '","counter":"' . $counter . '", "mark": 0, "is_mark":0}';   //上线信号
                break;
        }
    }
}
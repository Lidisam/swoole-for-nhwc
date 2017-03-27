<?php
namespace Module;
/**
 * Created by PhpStorm.
 * User: lisam
 * Date: 2017/3/25
 * Time: 16:41
 */
trait PlayGameTrait
{
    /**
     * 处理游戏进行时操作
     * @param $server
     * @param $frame
     */
    public function playGame($server, $frame)
    {
        $str = json_decode($this->redis->get("fd"), true);
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
//                print_r(($row));
                $this->redis->set("tips_" . $frame->fd . "", json_encode($row));  //题目
            }
            $this->redis->INCRBY("time_" . $frame->fd . "", 1);
        });
        $server->push($frame->fd, $frame->data);
    }

    /**
     * 返回答案
     * @param $key
     * @param $frame
     * @param $flag
     * @param $server
     * @param $val
     */
    public function returnAnswer($key, $frame, $flag, $server, $val, $str)
    {
        $mark = 1;
        if (!$flag) {
            $server->push((int)$key, $frame->data);
        } else {
            $str = json_decode($this->redis->get("fd"), true);
            if ($str[$frame->fd]['is_mark'] == 0) {
                echo "\n 这里是返回答案：\n";
                var_dump($str);
                echo "\n 这里是返回答案：\n";
                $str[$frame->fd]['mark'] += $mark;
                $str[$frame->fd]['is_mark'] = 1;
                $this->redis->set("fd", json_encode($str));
                foreach ($str as $k => $v) {
                    $server->push($k, '{"status":"9", "is_true":1, "username":"' . $val['username'] . '",
                        "mark":"1"}');  //先给自己发一份
                }
            }
        }
    }
}
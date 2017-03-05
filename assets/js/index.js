/**
 * Created by lisam on 2017/3/2.
 */

(function () {


    var isTouchPad = (/hp-tablet/gi).test(navigator.appVersion);
    var hasTouch = 'ontouchstart' in window && !isTouchPad;
    var touchStart = hasTouch ? 'touchstart' : 'mousedown';
    var touchMove = hasTouch ? 'touchmove' : 'mousemove';
    var touchEnd = hasTouch ? 'touchend' : 'mouseup';
    var startX = 0;
    var startY = 0;
    var penWidth = 1;
    var penColor = "#000";


    //通用函数
    var obj = function (id) {
        return document.getElementById(id)
    }

    var addClass = function (ele, className) {
        if (!ele || !className || (ele.className && ele.className.search(new RegExp("\\b" + className + "\\b")) != -1)) return;
        ele.className += (ele.className ? " " : "") + className;
    }

    var removeClass = function (ele, className) {
        if (!ele || !className || (ele.className && ele.className.search(new RegExp("\\b" + className + "\\b")) == -1)) return;
        ele.className = ele.className.replace(new RegExp("\\s*\\b" + className + "\\b", "g"), "");
    }


    //创建canvas
    canvT = obj("content").offsetTop;
    canvL = obj("content").offsetLeft;

    var canvas = obj("canvas");
    canvas.width = document.body.clientWidth;
    canvas.height = document.body.clientHeight - canvT;


    //绘画
    var draw = function (x, y) {
        context.lineTo(x - canvL, y - canvT);
        // context.lineTo(x, y);
        context.lineWidth = penWidth;
        context.strokeStyle = penColor;
        context.lineCap = "round";
        context.stroke();
    }

    //圆点
    var circlePoint = function (x, y) {
        context.beginPath();
        context.arc(x - canvL, y - canvT, penWidth / 2, 0, Math.PI * 2, true);
        context.closePath();
        context.fillStyle = penColor;
        context.fill();
    }

    //pen事件
    var pens = obj("pen").getElementsByTagName("li");
    for (var i = 0; i < pens.length; i++) {
        (function () {
            pens[i].addEventListener('click', function () {
                penWidth = this.innerText;
                for (var j = 0; j < pens.length; j++) {
                    removeClass(pens[j], 'cur')
                }
                addClass(this, 'cur');

            });
        })()
    }

    //color事件
    var color = obj("color");
    var curColor = obj("curColor");
    var selectColor = obj("selectColor");
    var colors = obj("color").getElementsByTagName("li");
    for (var i = 0; i < colors.length; i++) {
        colors[i].style.backgroundColor = colors[i].innerText;
        (function () {
            colors[i].addEventListener('click', function () {
                penColor = curColor.style.backgroundColor = this.innerText;
                selectColor.style.display = "none";
            });
        })()
    }
    curColor.addEventListener('click', function () {
        selectColor.style.display = "block";
    })


    //reset事件
    obj("reset").addEventListener('click', function () {
        //清屏信号
        socket.send('{"status":"2"}');
        context.clearRect(0, 0, document.body.clientWidth, canvas.height);
    });


    //触摸事件
    var start = function (e) {
        var point = hasTouch ? e.touches[0] : e;
        startX = point.pageX;
        startY = point.pageY;
        /**触碰按下事件信号**/
        socket.send('{"status":"1","first":' + startX + ',"second":' + startY + '}');

        context.beginPath();

        //添加“触摸移动”事件监听
        canvas.addEventListener(touchMove, move, false);
        //添加“触摸结束”事件监听
        canvas.addEventListener(touchEnd, end, false);
    }

    var move = function (e) {
        var point = hasTouch ? e.touches[0] : e;
        e.preventDefault();
        socket.send('{"status":"-1",:"first":' + point.pageX + ',"second":' + point.pageY + ',' +
            '"penWidth":' + penWidth + ',"penColor":"' + penColor + '"}');
        draw(point.pageX, point.pageY);

    }

    /**
     * 在离开屏幕时发送划线一已松开信号
     * @param e
     */
    var end = function (e) {
        /**松开信号处理**/
        socket.send('{"status":"0","msg":"鼠标松开"}');    //重要!!客户端返回服务器

        var point = hasTouch ? e.touches[0] : e;
        if (point.pageX == startX && point.pageY == startY) {
            circlePoint(startX, startY)
        }

        canvas.removeEventListener(touchStart, end, false);
        canvas.removeEventListener(touchMove, move, false);
        canvas.removeEventListener(touchEnd, end, false);
    }

    //添加“触摸开始”事件监听
    canvas.addEventListener(touchStart, start, false);


})();

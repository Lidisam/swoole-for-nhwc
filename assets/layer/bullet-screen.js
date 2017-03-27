/**
 * Created by lisam on 2017/3/9.
 */
/**发送答案**/
document.getElementById("sendQuestion").onclick = function () {
    socket.send('{"status":"9","is_true": 0,"username":"' + username + '","answer":"' + document.getElementById("input").value + '"}');
};

/**显示弹幕信息**/
var mainContent = document.getElementById("returnQuestion");
function randomText(val) {
    var text = val;
    var length = text.length;
    var p = document.createElement('span');
    p.style.color = randomColor();
    var random = 1;
    p.style.fontSize = random + 'rem';
    var randomHeight = 0;
    p.style.marginTop = randomHeight + 'px';
    p.innerText = text;
    mainContent.appendChild(p);
    var i = 0.9;
    var timer = setInterval(function () {
        p.style.left = i * document.body.clientWidth + 'px';
        i -= 0.003;
        if (p.offsetLeft < -length * random * 16) {
            clearInterval(timer);
            mainContent.removeChild(p);
        }
    }, 30);
}
function randomColor() {
    return '#000000';
    // return '#' + ('00000' + (Math.random() * 0x1000000 << 0).toString(16)).slice(-6);
}


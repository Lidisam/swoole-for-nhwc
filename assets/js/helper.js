/**
 * Created by lisam on 2017/3/4.
 */

/**
 * 闭合画布粘连
 * 如果没有闭合就会笔画相连或清屏失败
 */
function closePaint() {
    context.beginPath();
    e.preventDefault();
    context.lineTo(1, 1);
    context.stroke();
}
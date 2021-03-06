<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 订单表
 * Class Order
 * @package App\Models
 */
class Order extends Model
{
    protected $table = "xmt_pygj_order";
    protected $guarded = ['id'];

    /**
     * 1:推客位申请订单
     */
    const ORDER_APPLY = 1;

    /**
     * 2:续费订单
     */
    const ORDER_RENEWFEE = 2;

    /**
     * 3:升级终码订单
     */
    const ORDER_UPVIP = 3;

    /**
     * 4:提现订单
     */
    const ORDER_EXTRACT = 4;

    /**
     * 5:转码订单
     */
    const ORDER_TRANSFER = 5;

    /**
     * 6:邀请订单
     */
    const ORDER_INVITE = 6;

    /**
     * Redis Key
     */
    const REDIS_QUEUE= "manager:queue:complate_order_info";

    /**
     * 订单类别
     */
    static $order_type = [
        1 => '推客位申请',
        2 => '续费订单',
        3 => '升级终码订单',
        4 => '提现订单',
        5 => '转码订单',
        6 => '邀请订单',
    ];

    /**
     * 订单子类别
     */
    static $order_subtype = [
        11 => '月付',
        12 => '季付',
        13 => '年付',
        14 => '终码',
        15 => '试用',
        16 => '半货半款',
        21 => '月续月',
        22 => '月续季',
        23 => '月续年',
        24 => '季续月',
        25 => '季续季',
        26 => '季续年',
        27 => '年续月',
        28 => '年续季',
        29 => '年续年',
        301 => '试用升月',
        302 => '试用升季',
        303 => '试用升年',
        304 => '试用升终码',
        305 => '月升月',
        306 => '月升季',
        307 => '月升年',
        308 => '月升终码',
        309 => '季升月',
        310 => '季升季',
        311 => '季升年',
        312 => '季升终码',
        313 => '年升月',
        314 => '年升季',
        315 => '年升年',
        316 => '年升终码',
        61 => '月码',
        62 => '季码',
        63 => '年码',
        64 => '终码',
        66 => '半货半款',
    ];

    /**
     * 订单状态
     */
    static $order_status = [
        -1 => '已驳回',
        1 => '待审核',
        98 => '审核中',
        99 => '已审核未收货',
        100 => '完成',
    ];

    /**
     * 订单状态
     */
    static $code_type = [
        5 => '试用',
        30 => '月付',
        90 => '季付',
        360 => '年付',
        -1 => '终码',
        1 => '半货半款',
    ];

}

<?php
/**
 * Created by PhpStorm.
 * User: yangtao
 * Date: 2017/10/18
 * Time: 15:51
 */
namespace App\Services;

use App\Helpers\CacheHelper;
use App\Helpers\QueryHelper;
use App\Models\InviteCode;
use App\Models\PayInfo;
use App\Models\User;
use App\Models\UserInfo;
use App\Models\UserLevelConfig;
use App\Models\FriendRemark;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\OrderProcess;
use App\Models\UserIncome;
use App\Models\UserGrade;
use Carbon\Carbon;

class UserService{
    /**
     * 获取用户等级信息
     * @param $userId
     * @return array
     * @throws \Exception
     */
    public function getUserLevel($userId){
        if($data = CacheHelper::getCache()){
            return $data;
        }

        $UserLevelConfig = new UserLevelConfig();

        $userGrade = UserGrade::where("user_id", $userId)->first();
        $user = User::where("id", $userId)->with('UserInfo')->with('UserGrade')->first(['id', 'phone', 'grade', 'expiry_time'])->toArray();

        // 账户等级.
        $grade = $user['grade'] ? : 1;
        $gradeConfig = $UserLevelConfig->where("grade_id", $grade)->first();
        if(!$gradeConfig){
            Log::error("用户等级{$grade}未配置");
            throw new \Exception("系统错误");
        }
        // 当前等级.
        $gradeDesc = $gradeConfig['name'];
        $gradePic = 'http://'.config('domains.pygj_domains').$gradeConfig['grade_pic'];
        $isTop = $gradeConfig['is_top'];
        // 升级需要数量.
        $upgradeNeedNum = ($grade !== 3) ? ($userGrade['upgrade_invitecode_num'] ? : 10) : 0;

        // 升级等级.
        $upgradeGrade = ($grade + 1) <= 3 ? ($grade + 1) : 3;
        $upgrade = $UserLevelConfig->where("grade_id", $upgradeGrade)->first();

        $data = [
            'grade' => $gradeDesc,
            'upgrade_need_num' => $upgradeNeedNum,
            'upgrade_to_grade' => $upgrade['name'],
            'grade_pic' => $gradePic,
            'is_top' => $isTop
        ];

        CacheHelper::setCache($data, 1);
        return $data;
    }

    /**
     * 获取我的推客列表
     * @param $userId
     * @param int $page
     * @return array
     */
    public function getMyMember($userId, $page = 1){
        $User = new User();
        $InviteCode = new InviteCode();
        $UserInfo = new UserInfo();
        $remarks = new FriendRemark();

        $me = $User->where('id', $userId)->first(['id', 'phone', 'invite_code', 'grade', 'path'])->toArray();
        if ($me['grade'] === 3) {
            $query = $User->where([
                ['path', 'like', "$userId:%"],
                ['grade', '<>', 3]
            ])->with('UserInfo');
        } else {
            $query = $User->where('path', 'like', $me['path']."$userId:%")->with('UserInfo');
        }

        // 我的推客.
        $users = (new QueryHelper())->pagination($query)->get(['id', 'phone', 'reg_time', 'invite_code'])->toArray();

        // 更改数据格式,获取备注,父级信息.
        foreach ($users as $k=>$v){
            $users[$k]['actual_name'] = $v['user_info']['actual_name'];
            $users[$k]['wechat_id'] = $v['user_info']['wechat_id'];
            $users[$k]['taobao_id'] = $v['user_info']['taobao_id'];
            $users[$k]['type'] = 1;
            $users[$k]['type_desc'] = "推客";
            $users[$k]['remark'] = $remarks->where(['user_id' => $userId, 'friend_user_id' => $v['id']])->pluck('remark')->first();
            $users[$k]['master'] = $InviteCode->from($InviteCode->getTable()." as invite")
                ->where('invite.invite_code', $v['invite_code'])
                ->leftjoin($User->getTable()." as user", "user.id", '=', "invite.user_id")
                ->leftjoin($UserInfo->getTable()." as userinfo", "userinfo.user_id", '=', "user.id")->first(['user.id', 'user.phone', 'userinfo.actual_name']);
            unset($users[$k]['user_info']);
        }

        if($page == 1){
            //我使用的邀请码
            $inviteCode = $me['invite_code'];
            if($inviteCode){
                //师傅的用户id
                $masterUserId = $InviteCode->where('invite_code', $inviteCode)->pluck('user_id')->first();
                if($masterUserId != -1){
                    $masterUser = $User->where('id', $masterUserId)->with('UserInfo')->first(['id', 'phone', 'reg_time', 'invite_code'])->toArray();
                    if($masterUser){
                        $masterUser['actual_name'] = $masterUser['user_info']['actual_name'];
                        $masterUser['wechat_id'] = $masterUser['user_info']['wechat_id'];
                        $masterUser['taobao_id'] = $masterUser['user_info']['taobao_id'];
                        unset($masterUser['user_info']);
                        $masterUser['type'] = 2;
                        $masterUser['type_desc'] = "师傅";
                        $masterUser['remark'] = $remarks->where(['user_id' => $userId, 'friend_user_id' => $masterUserId])->pluck('remark')->first();
                        $masterUser['master'] = $InviteCode->from($InviteCode->getTable()." as invite")
                            ->where('invite.invite_code', $masterUser['invite_code'])
                            ->leftjoin($User->getTable()." as user", "user.id", '=', "invite.user_id")
                            ->leftjoin($UserInfo->getTable()." as userinfo", "userinfo.user_id", '=', "user.id")->first(['user.id', 'user.phone', 'userinfo.actual_name']);
                        array_push($users, $masterUser);
                    }
                }
            }
        }

        // 更改数据格式.
        foreach ($users as $k=>$v) {
            $users[$k]['master_id'] = $v['master']['id'];
            $users[$k]['master_phone'] = $v['master']['phone'];
            $users[$k]['master_actual_name'] = $v['master']['actual_name'];
            unset($users[$k]['master']);
        }

        return $users;
    }

    /**
     * 完善资料
     * @param $userId
     * @param $actual_name
     * @param $wechat_id
     * @param $taobao_id
     * @param $idCard
     * @param $alipay_id
     * @throws \Exception
     */
    public function setUserInfo($userId, $actual_name, $wechat_id, $taobao_id, $idCard, $alipay_id){
        try{
            $data = UserInfo::where('user_id', $userId)->first();

            if($data !== NULL){
                $res = $data->update([
                    'actual_name' => $actual_name,
                    'wechat_id' => $wechat_id,
                    'taobao_id' => $taobao_id,
                    'id_card' => $idCard,
                    'alipay_id' => $alipay_id,
                ]);
            } else {
                $res = UserInfo::create([
                    'user_id' => $userId,
                    'actual_name' => $actual_name,
                    'wechat_id' => $wechat_id,
                    'taobao_id' => $taobao_id,
                    'id_card' => $idCard,
                    'alipay_id' => $alipay_id,
                ]);
            }
            if(!$res){
                throw new \LogicException('信息存储失败');
            }
        }catch (\Exception $e){
            $error = $e instanceof \LogicException ? $e->getMessage() : '信息存储失败';
            throw new \Exception($error);
        }
    }

    /**
     * 获取用户资料
     * @param $userId
     * @return array
     * @throws \Exception
     */
    public function getUserInfo($userId){
        $User = new User();

        // 查询用户.
        $user = $User->where("id", $userId)->with('UserInfo')->with('UserGrade')->first(['id', 'phone', 'grade', 'expiry_time', 'path'])->toArray();
        if(!$user){
            throw new \LogicException("用户不存在");
        }
        $userGrade = $user['grade'] ? : 1;

        if ($userGrade == 3) {
            // 获取公司支付信息.
            $pay = PayInfo::where('character', 'admin')->pluck('pay')->first();
        } else {
            // 获取父级支付信息.
            $masterId = explode(':', $user['path'])[0];
            if ($masterId == -1) {
                $pay = 0;
            } else {
                $masterUser = $User->where("id", $masterId)->with('UserInfo')->first(['id', 'phone', 'grade']);
                if(!$masterUser){
                    throw new \LogicException("用户资料有误,请联系管理员");
                }
                $masterUser = $masterUser->toArray();
                $pay = $masterUser['user_info']['alipay_id'] ? : $masterUser['phone'];
            }
        }

        $user['upgrade'] = $user['user_grade']['upgrade_invitecode_num'];
        $user['actual_name'] = $user['user_info']['actual_name'];
        $user['wechat_id'] = $user['user_info']['wechat_id'];
        $user['taobao_id'] = $user['user_info']['taobao_id'];
        $user['id_card'] = $user['user_info']['id_card'];
        $user['alipay_id'] = $user['user_info']['alipay_id'];
        $user['pay'] = $pay;
        unset($user['user_grade']);
        unset($user['user_info']);

        // 是否过期.
        if ($user['expiry_time'] && (strtotime($user['expiry_time']) - time()) < 0) {
            $user['is_expiry'] = 1;
        } else {
            $user['is_expiry'] = 0;
        }

        return $user;
    }

    /**
     * 密码验证
     * @param $userId
     * @param $password
     * @throws \Exception
     */
    public function pwdValida($userId, $password){
        try{
            // 查询用户.
            $user = User::find($userId);
            if(!$user){
                throw new \LogicException("用户不存在");
            }

            // 验证密码.
            $sqlPassword = $user->password;
            if (!Hash::check($password, $sqlPassword)) {
                throw new \LogicException("密码不正确!");
            }
        }catch (\Exception $e){
            $error = $e instanceof \LogicException ? $e->getMessage() : '密码验证失败';
            throw new \Exception($error);
        }
    }

    /**
     * 朋友搜索
     * @param $userId
     * @param $keyword
     * @return array
     * @throws \Exception
     */
    public function querFriend($userId, $keyword){
        $User = new User();
        $InviteCode = new InviteCode();
        $UserInfo = new UserInfo();
        $remarks = new FriendRemark();

        // 查找我的推客.
        $me = $User->where('id', $userId)->first(['id', 'phone', 'invite_code', 'grade', 'path'])->toArray();
        if ($me['grade'] === 3) {
            $query = $User->from($User->getTable()." as user")->where([
                ['path', 'like', "$userId:%"],
                ['grade', '<>', 3]
            ]);
        } else {
            $query = $User->from($User->getTable()." as user")->where('path', 'like', $me['path']."$userId:%");
        }
        $query->leftjoin($UserInfo->getTable()." as userinfo", "userinfo.user_id", '=', "user.id");
        $query->select(["user.id", "user.phone", "user.reg_time", "invite_code", "userinfo.actual_name", "userinfo.wechat_id", "userinfo.taobao_id"]);

        // 查找关键字.
        $query->where(function($query) use($keyword){
            $query->where('user.phone', 'like', "%$keyword%")
                ->orwhere('userinfo.wechat_id', 'like', "%$keyword%");
        });

        // 符合条件的推客.
        $users = $query->get()->toArray();

        foreach ($users as &$user){
            $user['type'] = 1;
            $user['type_desc'] = "推客";
            $user['remark'] = $remarks->where(['user_id' => $userId, 'friend_user_id' => $user['id']])->pluck('remark')->first();
            $user['master'] = $InviteCode->from($InviteCode->getTable()." as invite")
                ->where('invite.invite_code', $user['invite_code'])
                ->leftjoin($User->getTable()." as user", "user.id", '=', "invite.user_id")
                ->leftjoin($UserInfo->getTable()." as userinfo", "userinfo.user_id", '=', "user.id")->first(['user.id', 'user.phone', 'userinfo.actual_name']);
        }

        //我使用的邀请码
        $inviteCode = $me['invite_code'];
        if($inviteCode){
            //师傅的用户id
            $masterUserId = $InviteCode->where('invite_code', $inviteCode)->pluck('user_id')->first();
            if($masterUserId != -1){
                // 匹配师傅关键字.
                $masterUser = $User->from($User->getTable()." as user")->where("user.id", $masterUserId)
                    ->leftjoin($UserInfo->getTable()." as userinfo", "userinfo.user_id", '=', "user.id")
                    ->select(["user.id", "user.phone", "user.reg_time", "invite_code", "userinfo.actual_name", "userinfo.wechat_id", "userinfo.taobao_id"])
                    ->where(function($query) use($keyword){
                        $query->where('user.phone', 'like', "%$keyword%")
                            ->orwhere('userinfo.wechat_id', 'like', "%$keyword%");
                    })->first();
                if($masterUser){
                    $masterUser['type'] = 2;
                    $masterUser['type_desc'] = "师傅";
                    $masterUser['remark'] = $remarks->where(['user_id' => $userId, 'friend_user_id' => $masterUserId])->pluck('remark')->first();
                    $masterUser['master'] = $InviteCode->from($InviteCode->getTable()." as invite")
                        ->where('invite.invite_code', $masterUser['invite_code'])
                        ->leftjoin($User->getTable()." as user", "user.id", '=', "invite.user_id")
                        ->leftjoin($UserInfo->getTable()." as userinfo", "userinfo.user_id", '=', "user.id")->first(['user.id', 'user.phone', 'userinfo.actual_name']);
                    array_push($users, $masterUser);
                }
            }
        }

        // 更改数据格式.
        foreach ($users as $k=>$v) {
            $users[$k]['master_id'] = $v['master']['id'];
            $users[$k]['master_phone'] = $v['master']['phone'];
            $users[$k]['master_actual_name'] = $v['master']['actual_name'];
            unset($users[$k]['master']);
        }

        return $users;
    }

    /**
     * 获取推客位申请记录
     * @param $userId
     * @param $startTime
     * @param $endTime
     * @return mixed
     * @throws \Exception
     */
    public function applyList($userId, $startTime = '', $endTime = ''){
        if($startTime && $endTime){
            $startTime = $startTime.' 00:00:00';
            $endTime = $endTime.' 23:59:59';
            // 查询时间范围订单.
            $data = Order::where([
                ['type', '<=', 2],
                ['target_user_id', $userId],
                ['created_at', '>=', $startTime],
                ['created_at', '<=', $endTime]
            ])->select(['id', 'subtype', 'number', 'status', 'created_at', 'remark'])->orderBy('created_at', 'desc');
        }else if(!$startTime && !$endTime) {
            // 查询订单.
            $data = Order::where([
                ['type', '<=', 2],
                ['target_user_id', $userId]
            ])->select(['id', 'subtype', 'number', 'status', 'created_at', 'remark'])->orderBy('created_at', 'desc');
        }else{
            $endTime = $startTime.' 23:59:59';
            $startTime = $startTime.' 00:00:00';
            // 查询单天订单.
            $data = Order::where([
                ['type', '<=', 2],
                ['target_user_id', $userId],
                ['created_at', '>=', $startTime],
                ['created_at', '<=', $endTime]
            ])->select(['id', 'subtype', 'number', 'status', 'created_at', 'remark'])->orderBy('created_at', 'desc');
        }

        // 分页.
        $data = (new QueryHelper())->pagination($data)->get();

        $OrderProcess = new OrderProcess();

        foreach ($data as $k => $v) {
            if($v['status'] == -1){
                $data[$k]['remark'] = $OrderProcess->where('order_id', $v['id'])->orderBy('created_at', 'desc')->pluck('remark')->first();
            }
            $data[$k]['subtype'] = Order::$order_subtype[$v['subtype']];
//            $data[$k]['status'] = isset(Order::$order_status[$v['status']]) ? Order::$order_status[$v['status']] : Order::$order_status[98];
            $data[$k]['date'] = explode(' ', $v['created_at'])[0];
            $data[$k]['time'] = explode(' ', $v['created_at'])[1];
        }

        // 按日期分组.
        $result = [];
        foreach($data as $k=>$v){
            $result[$v['date']][] = $v;
        }

        // 分组计数,合计.
        $num = 0;
        $numbers = 0;
        foreach ($result as $k => $v){
            foreach ($v as $key => $vel){
                $num += $vel['number'];
                $numbers += $vel['number'];
            }
            $result[$k]['num'] = $num;
            $num = 0;
        }
        $result['numbers'] = $numbers;
        $result['servertime'] = time();

        return $result;
    }

    /**
     * 获取推客招募记录
     * @param $userId
     * @param int $page
     * @param string $startTime
     * @param string $endTime
     * @return mixed
     */
    public function recruit($userId, $page = 1, $startTime = '', $endTime = ''){
        $User = new User();
        $InviteCode = new InviteCode();

        $me = $User->where('id', $userId)->with('UserInfo')->first(['id', 'phone', 'grade', 'path'])->toArray();
        if ($me['grade'] === 3) {
            $query = $User->where([
                ['path', 'like', "$userId:%"],
                ['grade', '<>', 3]
            ])->with('UserInfo');
        } else {
            $query = $User->where('path', 'like', $me['path']."$userId:%")->with('UserInfo');
        }

        // 我的推客.
        $users = $query->get(['id', 'phone'])->toArray();

        // 我的推客ID.
        $usersId = [];
        foreach($users as $k=>$v){
            $usersId[] = $v['id'];
        }

        if($startTime && $endTime){
            // 初始化时间.
            $startTime = $startTime.' 00:00:00';
            $endTime = $endTime.' 23:59:59';
            // 时间段推客招募.
            $member = $InviteCode->whereIn('user_id', $usersId)->where([
                'status' => InviteCode::STATUS_USED,
                ['update_time', '>=', $startTime],
                ['update_time', '<=', $endTime]
            ])->select(DB::raw('count(user_id) as number, user_id, effective_days, code_type, date'))->groupBy(['user_id', 'effective_days', 'code_type', 'date'])->orderBy('date', 'desc');
        }else if(!$startTime && !$endTime) {
            // 推客招募.
            $member = $InviteCode->whereIn('user_id', $usersId)->where([
                'status' => InviteCode::STATUS_USED
            ])->select(DB::raw('count(user_id) as number, user_id, effective_days, code_type, date'))->groupBy(['user_id', 'effective_days', 'code_type', 'date'])->orderBy('date', 'desc');
        }else{
            // 初始化时间.
            $endTime = $startTime.' 23:59:59';
            $startTime = $startTime.' 00:00:00';
            // 单天推客招募.
            $member = $InviteCode->whereIn('user_id', $usersId)->where([
                'status' => InviteCode::STATUS_USED,
                ['update_time', '>=', $startTime],
                ['update_time', '<=', $endTime]
            ])->select(DB::raw('count(user_id) as number, user_id, effective_days, code_type, date'))->groupBy(['user_id', 'effective_days', 'code_type', 'date'])->orderBy('date', 'desc');
        }
        // 分页.
        $member = (new QueryHelper())->pagination($member)->get()->toArray();

        // 邀请码类型.
        foreach ($member as $k => $v) {
            if  ($v['code_type'] !== 1) {
                $member[$k]['code_type'] = Order::$code_type[$v['effective_days']];
            } else {
                $member[$k]['code_type'] = Order::$code_type[$v['code_type']];
            }
        }

        // 我的招募.
        if ($page == 1) {
            // 我的用户信息.
            array_unshift($users, $me);

            if($startTime && $endTime){
                // 初始化时间.
                $startTime = $startTime.' 00:00:00';
                $endTime = $endTime.' 23:59:59';
                // 时间段推客招募.
                $data = $InviteCode->where([
                    'user_id' => $userId,
                    'status' => InviteCode::STATUS_USED,
                    ['update_time', '>=', $startTime],
                    ['update_time', '<=', $endTime]
                ])->select(DB::raw('count(user_id) as number, user_id, effective_days, code_type, date'))->groupBy(['user_id', 'effective_days', 'code_type', 'date'])->orderBy('date', 'asc');
            }else if(!$startTime && !$endTime) {
                // 推客招募.
                $data = $InviteCode->where([
                    'user_id' => $userId,
                    'status' => InviteCode::STATUS_USED
                ])->select(DB::raw('count(user_id) as number, user_id, effective_days, code_type, date'))->groupBy(['user_id', 'effective_days', 'code_type', 'date'])->orderBy('date', 'asc');
            }else{
                // 初始化时间.
                $endTime = $startTime.' 23:59:59';
                $startTime = $startTime.' 00:00:00';
                // 单天推客招募.
                $data = $InviteCode->where([
                    'user_id' => $userId,
                    'status' => InviteCode::STATUS_USED,
                    ['update_time', '>=', $startTime],
                    ['update_time', '<=', $endTime]
                ])->select(DB::raw('count(user_id) as number, user_id, effective_days, code_type, date'))->groupBy(['user_id', 'effective_days', 'code_type', 'date'])->orderBy('date', 'asc');
            }
            // 分页.
            $data = (new QueryHelper())->pagination($data)->get()->toArray();

            // 邀请码类型.
            foreach ($data as $k => $v) {
                if  ($v['code_type'] != 1) {
                    $data[$k]['code_type'] = Order::$code_type[$v['effective_days']];
                } else {
                    $data[$k]['code_type'] = Order::$code_type[$v['code_type']];
                }
            }

            foreach ($data as $k => $v){
                array_unshift($member, $v);
            };
        }

        // 数据分组.
        $result = [];
        foreach($member as $k=>$v){
            $result[$v['date']][$v['user_id']][] = $v;
        }

        // 用户信息.
        $info = [];
        foreach ($users as $k => $v) {
            $info[$v['id']]['phone'] = $v['phone'];
            $info[$v['id']]['wechat_id'] = $v['user_info']['wechat_id'];
        }

        // 分组计数,合计.
        $num = 0;
        $numbers = 0;
        foreach ($result as $k => $v){
            foreach ($v as $ke => $ve){
                foreach ($ve as $key => $vel){
                    $num += $vel['number'];
                    $numbers += $vel['number'];
                }
                $result[$k][$ke]['num'] = $num;
                $num = 0;
                $result[$k][$ke]['wechat_id'] = $info[$vel['user_id']]['wechat_id'];
                $result[$k][$ke]['phone'] = $info[$vel['user_id']]['phone'];
            }
        }
        $result['numbers'] = $numbers;
        $result['user_id'] = $userId;
        $result['servertime'] = time();

        return $result;
    }

    /**
     * 今日新增招募
     * @param $userId
     * @return mixed
     */
    public function nowAdded($userId){
        // 初始化时间.
        $startTime = (new Carbon())->startOfDay()->toDateTimeString();
        $endTime = (new Carbon())->endOfDay()->toDateTimeString();

        $data = InviteCode::where([
            'user_id' => $userId,
            'status' => InviteCode::STATUS_USED,
            ['update_time', '>=', $startTime],
            ['update_time', '<=', $endTime]
        ])->count();

        return ['number' => $data];
    }

    /**
     * 今日收益
     * @param $userId
     * @return array
     * @throws \Exception
     */
    public function income($userId){
        if($data = CacheHelper::getCache()){
            return $data;
        }

        $UserIncome = new UserIncome();
        $Carbon = new Carbon();

        $startTime = $Carbon->startOfDay()->toDateTimeString();
        $endTime = $Carbon->endOfDay()->toDateTimeString();

        // 当天收益.
        $day = $UserIncome->where([
            ['user_id', $userId],
            ['created_at', '>=', $startTime],
            ['created_at', '<=', $endTime]
        ])->sum('income_num');

        $startTime = $Carbon->startOfMonth()->toDateTimeString();
        $endTime = $Carbon->endOfMonth()->toDateTimeString();

        // 当月收益.
        $month = $UserIncome->where([
            ['user_id', $userId],
            ['created_at', '>=', $startTime],
            ['created_at', '<=', $endTime]
        ])->sum('income_num');

        // 总计收益.
        $total = $UserIncome->where('user_id', $userId)->sum('income_num');

        $data = [
            'day' => $day,
            'month' => $month,
            'total' => $total
        ];

        CacheHelper::setCache($data, 1);
        return $data;
    }

    /**
     * 收益列表
     * @param $userId
     * @param $type
     * @param $startTime
     * @param $endTime
     * @return array
     * @throws \Exception
     */
    public function incomeList($userId, $type, $startTime = '', $endTime = ''){
        $Carbon = new Carbon();

        if($type == 1){
            // 今日收益.
            $startTime = $Carbon->startOfDay()->toDateTimeString();
            $endTime = $Carbon->endOfDay()->toDateTimeString();
        }else if($type == 2){
            // 本月收益.
            $startTime = $Carbon->startOfMonth()->toDateTimeString();
            $endTime = $Carbon->endOfMonth()->toDateTimeString();
        }else{
            if($startTime && $endTime){
                $startTime = $startTime.' 00:00:00';
                $endTime = $endTime.' 23:59:59';
            }else{
                $endTime = $startTime.' 23:59:59';
                $startTime = $startTime.' 00:00:00';
            }
        }

        // type为0,时间段收益.
        $income = UserIncome::where([
            ['user_id', $userId],
            ['created_at', '>=', $startTime],
            ['created_at', '<=', $endTime]
        ])->select(['type', 'income_num', 'remark', 'created_at'])->orderBy('created_at', 'desc');
        $data = (new QueryHelper())->pagination($income)->get()->toArray();

        $numbers = 0;
        foreach($data as $k => $v){
            $data[$k]['date'] = explode(' ', $v['created_at'])[0];
            $data[$k]['time'] = explode(' ', $v['created_at'])[1];
            $numbers +=$v['income_num'];
        }

        $data['numbers'] = $numbers;

        return $data;
    }

    /**
     * 收益备注
     * @param $userId
     * @param $incomeId
     * @param $remark
     * @return mixed
     * @throws \Exception
     */
    public function incomeRemark($userId, $incomeId, $remark){
        try{
            $res = UserIncome::where(['id' => $incomeId, 'user_id' => $userId])->first();

            if(!$res){
                throw new \LogicException('记录不存在');
            }

            $res->remark = $remark;
            return $res->save();
        }catch (\Exception $e){
            $error = $e instanceof \LogicException ? $e->getMessage() : '备注失败';
            throw new \Exception($error);
        }
    }

    /**
     * 提现记录
     * @param $userId
     * @return mixed
     * @throws \Exception
     */
    public function withdrawalRecords($userId){
        $data = Order::where(['type' => 4, 'target_user_id' => $userId]);
        $withdrawalRecords = (new QueryHelper())->pagination($data)->get()->toArray();
        return $withdrawalRecords;
    }

    /**
     * 生成邀请链接
     * @param $userId
     * @return mixed
     * @throws \Exception
     */
    public function inviteLink($userId){
        // 我的用户信息.
        $user = User::where("id", $userId)->first(['id', 'phone'])->toArray();
        $url = 'http://'.config('domains.pygj_domains').'/invite/'.$user['phone'];
        return $url;
    }

}

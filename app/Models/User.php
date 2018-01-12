<?php

namespace App\Models;

use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Passport\HasApiTokens;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * 用户表
 * Class User
 * @package App\Models
 */
class User extends Authenticatable
{
    protected $table = "xmt_user";
    public $timestamps = false;

    use Notifiable, HasApiTokens;

    protected $guarded = ['id'];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token',
    ];

    /**
     * passport查找用户
     * @param $login
     * @return mixed
     */
    public function findForPassport($login){
        $user = $this->where('phone', $login)->first();
        if(!$user){
            throw  new OAuthServerException("用户未注册", 0, 'unregister_user');
        }
        if($user['is_forbid']){
            throw  new OAuthServerException("用户已禁用", 0, 'forbidden_user');
        }
        return $user;
    }

    /**
     * 获得与用户关联的信息.
     */
    public function UserInfo()
    {
        return $this->hasOne('App\Models\UserInfo', 'user_id', 'id')->select('id', 'user_id', 'upgrade', 'actual_name', 'wechat_id', 'taobao_id', 'alipay_id');
    }

}

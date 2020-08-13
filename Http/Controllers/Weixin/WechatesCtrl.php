<?php
namespace App\Http\Controllers\Weixin;
use App\Http\Controllers\Controller;
use App\Model\FactoryModel\Factory;
use App\Model\WechatModel\WechatUser;
use App\Model\WechatModel\Wxmenu;
use DateTime;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Session;
use Illuminate\Http\Request;
 
class WechatesCtrl extends Controller
{
	private $appId = '';
    private $appSecret = '';
    private $apptoken = '';
    private $encodingAESKey = '';
    private $factoryname = '';
    private $factoryid = '';

    //初始化appId,appSecret,encodingAESKey
    public function __construct(){
    }

    /**
     * 填写参数
     * @param string $appId
     * @param string $appSecret
     * @param string $encodingAESKey
     * @param string $apptoken
     * @param string $factoryname
     * @param string $factoryid
     */
    private function fillParam($appId = '', $appSecret = '', $encodingAESKey = '', $apptoken = '', $factoryname = '', $factoryid = '') {
        $this->appId = $appId;
        $this->appSecret = $appSecret;
        $this->apptoken = $apptoken;
        $this->encodingAESKey = $encodingAESKey;
        $this->factoryname = $factoryname;
        $this->factoryid = $factoryid;
    }

    /**
     * 用参数初始化
     * @param string $appId
     * @param string $appSecret
     * @param string $encodingAESKey
     * @param string $apptoken
     * @param string $factoryname
     * @param string $factoryid
     * @return WechatesCtrl
     */
    public static function withParam($appId = '', $appSecret = '', $encodingAESKey = '', $apptoken = '', $factoryname = '', $factoryid = '') {
        $instance = new self();
        $instance->fillParam($appId, $appSecret, $encodingAESKey, $apptoken, $factoryname, $factoryid);

        return $instance;
    }

    /**
     * 用奶厂对象初始化
     * @param $factory
     * @return WechatesCtrl
     */
    public static function withFactory($factory) {
        $instance = new self();
        $instance->fillParam($factory->app_id,
            $factory->app_secret,
            $factory->app_encoding_key,
            $factory->app_token,
            $factory->name,
            $factory->id);

        return $instance;
    }

	//认证token
    public function valid()
    {
        $echoStr = $_GET["echostr"];
        if($this->checkSignature()){
            echo $echoStr;
            exit;
        }
    }
	
    private function checkSignature()
    {
        $signature = $_GET["signature"];
        $timestamp = $_GET["timestamp"];
        $nonce = $_GET["nonce"];
        $tmpArr = array($this->apptoken, $timestamp, $nonce);
        sort($tmpArr);
        $tmpStr = implode($tmpArr);
        $tmpStr = sha1($tmpStr);

        if($tmpStr == $signature){
            return true;
        }else{
            return false;
        }
    }

    public function responseMsg(Request $request)
    {
        $postStr = $GLOBALS["HTTP_RAW_POST_DATA"];
		
		file_put_contents("WeixinLog.txt","[ Weixin ] postStr ".$postStr."\n", FILE_APPEND);

		
        if (!empty($postStr)){
            $postObj = simplexml_load_string($postStr, 'SimpleXMLElement', LIBXML_NOCDATA);
            $RX_TYPE = trim($postObj->MsgType);

            switch ($RX_TYPE)
            {
                case "event":
                    $result = $this->receiveEvent($postObj);
                    break;
            }
            echo $result;
			
			file_put_contents("WeixinLog.txt","[ Weixin ] result ".$result."\n", FILE_APPEND);	
			
        }else {
            echo "";
            exit;
        }

    }
    //关注/取消/点击事件
    private function receiveEvent($object)
    {
        $content = "";
        $result = "";

        switch ($object->Event)
        {
            case "subscribe":   //关注事件
				$this->WechatUsers($object->FromUserName, $object->EventKey);
                $content = "您好，欢迎关注".$this->factoryname;
                break;
            case "unsubscribe": //取消关注事件
                $content = "";
                break;
        }

        if (!empty($content)) {
            $result = $this->transmitText($object, $content);
        }

        return $result;
    }

	//微信用户添加(修改)数据库
	private function WechatUsers($FromUserName, $eventKey) {
		$accessToken = $this->accessToken();
		$jsoninfo    = $this->getFanInfo($accessToken,$FromUserName);

		$wxusers = WechatUser::where('openid', $jsoninfo['openid'])
            ->where('factory_id',$this->factoryid)
            ->first();

		if (empty($wxusers)) {
            $wxusers = new WechatUser;
        }

        // 获取parent
        $strPrefix = "qrscene_";
        $strContent = substr($eventKey, 0, strlen($strPrefix));
        if (strcmp($strPrefix, $strContent) == 0) {
            $strOpenId = substr($eventKey, strlen($strPrefix));
            $wxParent = WechatUser::where('openid', $strOpenId)->first();
            $wxusers->parent = $wxParent->id;
        }

        // 去掉emoji
        $strNickname = preg_replace('/\xEE[\x80-\xBF][\x80-\xBF]|\xEF[\x81-\x83][\x80-\xBF]/', '', $jsoninfo['nickname']);

		$wxusers->openid     = $jsoninfo['openid'];
		$wxusers->name       = $strNickname;
		$wxusers->area       = $jsoninfo["province"]." ".$jsoninfo["city"];
		$wxusers->image_url  = $jsoninfo['headimgurl'];
		$wxusers->factory_id  = $this->factoryid;

		$wxusers->save();
	}

	//消息模板
    private function transmitText($object, $content)
    {
        $textTpl = "<xml>
					<ToUserName><![CDATA[%s]]></ToUserName>
					<FromUserName><![CDATA[%s]]></FromUserName>
					<CreateTime>%s</CreateTime>
					<MsgType><![CDATA[text]]></MsgType>
					<Content><![CDATA[%s]]></Content>
					</xml>";
        $result = sprintf($textTpl, $object->FromUserName, $object->ToUserName, time(), $content);
        return $result;
    }

	//获取accessToken
	public function accessToken()
	{
        $strAccessToken = "";
	    $ft = Factory::find($this->factoryid);

	    $dateCurrent = new DateTime("now");
	    $dateExpire = new DateTime($ft->access_token_expire_at);

	    // 从数据库获取
	    if (!empty($ft->access_token) && ($dateExpire > $dateCurrent)) {
            return $ft->access_token;
        }

	    $client = new Client();

		$url = "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid="
            . $this->appId . "&secret=" . $this->appSecret;

        $accessTokenJson = $client->request('GET', $url)->getBody();

        if (!empty($accessTokenJson)) {
            $accessTokenArr = json_decode($accessTokenJson, true);
            if (empty($accessTokenArr['errcode']) AND !empty($accessTokenArr['access_token'])){
                $strAccessToken = $accessTokenArr['access_token'];

                // 保存到数据库
                $ft->access_token = $strAccessToken;
                $dateCurrent->add(new \DateInterval("PT7200S"));
                $ft->access_token_expire_at = $dateCurrent;

                $ft->save();
            }
        }

        return $strAccessToken;
	}

	//获取用户openid
	public function codes($code){
		$url = "https://api.weixin.qq.com/sns/oauth2/access_token?appid=".$this->appId."&secret=".$this->appSecret."&code=".$code."&grant_type=authorization_code";			
        $usercodes = $this->sendGetRequest($url);
        if($usercodes != ''){
            $usercodess = json_decode($usercodes, true);
            if(!empty($usercodess['errcode'])){
                return array();
            }
        }else $usercodess = array();
        return $usercodess;
	}
    //获取用户的详细信息
    public function getFanInfo($accessToken, $openId)
    {
		$url = "https://api.weixin.qq.com/cgi-bin/user/info?access_token=$accessToken&openid=$openId&lang=zh_CN";	
        $userMesJson = $this->sendGetRequest($url);
        if($userMesJson != ''){
            $userMesArr = json_decode($userMesJson, true);
            if(!empty($userMesArr['errcode'])){
                return array();
            }
        }else $userMesArr = array();
        return $userMesArr;
    }

	//自定义菜单
	public function createMenu($url)
	{
//		$wxmenu = Wxmenu::where('factoryid',$this->factoryid)->get();
//		$jsonmenu = '{ "button":[ ';
//		foreach($wxmenu as $wxvalue){
//			$jsonmenu .='{ "name":"'.$wxvalue->name.'", "type":"'.$wxvalue->type.'", "url":"https://open.weixin.qq.com/connect/oauth2/authorize?appid='.$this->appId.'&redirect_uri=http://niu.vfushun.com/milk'.$wxvalue->url.'&response_type=code&scope=snsapi_userinfo&state='.$this->factoryid.'#wechat_redirect" } ,';
//		}
//		//.$wxvalue->type.'", "url":"https://open.weixin.qq.com/connect/oauth2/authorize?appid='.$this->appId.'&redirect_uri=http://niu.vfushun.com/milk'.$wxvalue->url.'&response_type=code&scope=snsapi_userinfo&state=yuying#wechat_redirect
//		$jsonmenu = substr($jsonmenu,0,-1);
//		$jsonmenu .= ']}';

        $strUrl = $url . "weixin";

		$jsonmenu = '{
		    "button":[
		    {
		        "name": "鲜奶配送",
		        "type": "view",
		        "url": "https://open.weixin.qq.com/connect/oauth2/authorize?appid=' . $this->appId . '&redirect_uri=' . $strUrl . '/qianye&response_type=code&scope=snsapi_userinfo&state='.$this->factoryid.'#wechat_redirect"
            },
            {
                "name":"获取红包",
                "sub_button":[{
                    "name": "分享",
                    "type": "view",
                    "url": "https://open.weixin.qq.com/connect/oauth2/authorize?appid=' . $this->appId . '&redirect_uri=' . $strUrl . '/share&response_type=code&scope=snsapi_userinfo&state='.$this->factoryid.'#wechat_redirect"
                },
                {
                    "name": "活动详情",
                    "type": "view",
                    "url": "https://open.weixin.qq.com/connect/oauth2/authorize?appid=' . $this->appId . '&redirect_uri=' . $strUrl . '/activity&response_type=code&scope=snsapi_userinfo&state='.$this->factoryid.'#wechat_redirect"
                }]
            },
            {
		        "name": "个人中心",
                "sub_button":[{
                    "name": "个人中心",
                    "type": "view",
                    "url": "https://open.weixin.qq.com/connect/oauth2/authorize?appid=' . $this->appId . '&redirect_uri=' . $strUrl . '/gerenzhongxin&response_type=code&scope=snsapi_userinfo&state='.$this->factoryid.'#wechat_redirect"
                },
                {
                "name": "帮助",
                "type": "view",
                "url": "https://open.weixin.qq.com/connect/oauth2/authorize?appid=' . $this->appId . '&redirect_uri=' . $strUrl . '/bangzhu&response_type=code&scope=snsapi_userinfo&state='.$this->factoryid.'#wechat_redirect"
                }]
            }
            ]
		 }';

		$accessToken = $this->accessToken();
		$url = "https://api.weixin.qq.com/cgi-bin/menu/create?access_token=".$accessToken;
		$result = $this->sendGetRequest($url, $jsonmenu);
		return $result;
		//var_dump($result);
	}
	// 发送get请求
    private function sendGetRequest($url, $data=null){
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,$url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);//不输出内容
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		if (!empty($data)){
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		}
        $result =  curl_exec($ch);
		if (curl_errno($ch)) {return 'ERROR '.curl_error($ch);}
		curl_close($ch);
		return $result;
    }
	//打印对象
	public function var_dump_ret($mixed = null) {
		ob_start();
		var_dump($mixed);
		$content = ob_get_contents();
		ob_end_clean();
		return $content;
	}
	
	
	
	
	
	
}

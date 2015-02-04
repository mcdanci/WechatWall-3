<?php
/**
 * FileName: wechat.class.php
 * Discription: 利用culr构造HTTP POST请求包，实现微信公众平台的模拟登陆与用户信息获取
 * ModifyHistory:
 * 1. 2015-02-02    20:33    Dreamshield
 * 创建源文件
 */
require_once('/var/www/project/WechatWall/bsm/inc/main.inc.php'); // 包含文件

class wechat {
	private $username;
	private $password;
	private $cookie;
	private $token;
	private $count = 1000; // 微信公众账号的消息管理页面中单页显示消息数量
	private $userMsg; // 用户消息

	/**
	 * [__construct: 获取公众账号用户名和密码]
	 * @param [string] $username 微信公众账号用户名
	 * @param [string] $password  微信公众账号密码
	 * @param [无返回值]
	 */
	public function __construct($username, $password) {
		$this->username = $username;
		$this->password = $password;
		$this->login();
	}

	/**
	 * [login: 实现模拟登陆]
	 * @return [无返回值]
	 */
	public function login() {
		// 构造POST报文体
		$postData = array(
			"username"=>$this->username,
			"pwd" =>md5($this->password),
			"f"=>"json"
		);

		// 初始化并发送POST请求包
		$url = "https://mp.weixin.qq.com/cgi-bin/login";
		$httpReqst = new httpRequest;
		$result = $httpReqst->postHttp($url, $postData, $this->cookie);
		$data = explode("\n",$result);
		foreach ($data as $key => $value) {
		    	if(preg_match("/redirect_url/i", $value)){ // 获取并存储coken
		       	$this->token = substr($value,strrpos($value,"=")+1,-2);
		       }
		    	if(preg_match("/^Set-Cookie\:\s([^=]+)\=([^;]+)/i", $value,$match)) { //获取并存储cookie
		        	$this->cookie .= $match[1].'='.$match[2].'; '; // 注意这里cookie的存储格式
		     	}
		}
	}

	/**
	 * [getUserMsg: 获取微信公众账号的消息管理页面]
	 * @return [array] $userMsg 用户消息信息
	 */
	public function getUserMsg() {
		$url = 'https://mp.weixin.qq.com/cgi-bin/message?t=message/list&count='.$this->count.'&day=7&token='.$this->token.'&lang=zh_CN';
		$httpReqst = new httpRequest;
		$msgPage = $httpReqst->getHttp($url, $this->cookie); // 获取消息管理页面

		// 用户消息预处理
		$pattern = "/\{\"msg_item.*msg_item/";
		if (preg_match($pattern, $msgPage, $matchs)) {
			$preData = $matchs[0];
		} else {
			echo "用户消息预处理失败\n";
		}

		// 提取msgid,fackid,nickname,msg,time
		$pattern = '/"id":(\d*?),"type":\d,"fakeid":"(\d*?)","nick_name":"(.*?)","date_time":(\d*),"((content)|(source))":"(.*?)"/';
		if (preg_match_all($pattern, $preData, $matchs)) {
			$userMsg = array('msgid'=>$matchs[1], 'fackid'=>$matchs[2], 'nickname'=>$matchs[3], 'time'=>$matchs[4], 'content'=>$matchs[8]);
		} else {
			echo "提取msgid,fakeid,nickname,msg,time失败\n";
		}
		return $userMsg;
	}

	public function storeUserMsg($userMsg, $localhost, $username, $password, $database) {
		// 对userMsg数组信息合并为一个二维数组,该数组以数字键值,每个一维数组的键值分别是:
		//  msgid  fakeid  nickname  time  content  audit
		foreach ($userMsg as $value) { // 为了使用list()函数将userMsg数组改为以数字为索引
			$tmp[]=$value;
		}
		list($msgid, $fakeid, $nickname, $time, $content)  = $tmp; // 将tmp拆成5个一维数组
		$len = count($msgid); // 计算每个一维数组长度,即消息数量
		for ($i=0; $i < $len; $i++) { // 将消息数组合成便于数据库插入的格式
			$userMsgDB[] = array(
				'msgid'=>$msgid[$i],
				'fakeid'=>$fakeid[$i],
				'nickname'=>$nickname[$i],
				'content'=>$content[$i],
				'time'=>$time[$i],
				'audit'=>0
			);
		}

		// debug-start
		// echo "<pre>";
		// print_r($userMsgDB);
		// echo "</pre>";
		// debug-stop

		// 用户消息插入数据库
		$db = new mysqlDB($localhost, $username, $password, $database);
		foreach ($userMsgDB as $value) {
			$query = "SELECT msgid FROM userMsg WHERE msgid = '". $value['msgid']. "'";
			$findFlag = $db->find($query); // 查询当前消息是否已经存在
			if ('' == $findFlag) { // 当前消息不存在,返回空执行数据插入操作
				$db->insert('userMsg', $value);
			}
		}
	}
}

?>

<?php 

/**
 * TSStatus: Teamspeak 3 viewer for php5
 * @author Eska <eskasteam@gmail.com>
 * 
 **/

class TSStatus
{
	private $_host;
	private $_qport;
	private $_sid;
	private $_socket;
	private $_updated;
	private $_serverDatas;
	private $_channelDatas;
	private $_userDatas;
	private $_serverGroupFlags;
	private $_channelGroupFlags;
	
	public $imagePath;
	public $decodeUTF8;
	public $showNicknameBox;
	public $timeout;
	private $sscape;
	
	public function TSStatus($host, $queryPort, $serverId)
	{
		$this->_host = $host;
		$this->_qport = $queryPort;
		$this->_sid = $serverId;
		
		$this->_socket = null;	
		$this->_updated = false;
		$this->_serverDatas = array();
		$this->_channelDatas = array();
		$this->_userDatas = array();
		$this->_serverGroupFlags = array();
		$this->_channelGroupFlags = array();
		
		$this->imagePath = "img/";
		$this->decodeUTF8 = false;
		$this->showNicknameBox = true;
		$this->timeout = 2;
		
		$this->setServerGroupFlag(6, 'servergroup_300.png');
		$this->setChannelGroupFlag(5, 'changroup_100.png');
		$this->setChannelGroupFlag(6, 'changroup_200.png');
	}
	
	private function unescape($str)
	{
		$find = array('\\\\', 	"\/", 		"\s", 		"\p", 		"\a", 	"\b", 	"\f", 		"\n", 		"\r", 	"\t", 	"\v");
		$rplc = array(chr(92),	chr(47),	chr(32),	chr(124),	chr(7),	chr(8),	chr(12),	chr(10),	chr(3),	chr(9),	chr(11));
		
		return str_replace($find, $rplc, $str);
	}
	
	private function parseLine($rawLine)
	{
		$datas = array();
		$rawItems = explode("|", $rawLine);
		foreach ($rawItems as $rawItem)
		{
			$rawDatas = explode(" ", $rawItem);
			$tempDatas = array();
			foreach($rawDatas as $rawData)
			{
				$ar = explode("=", $rawData, 2);
				$tempDatas[$ar[0]] = isset($ar[1]) ? $this->unescape($ar[1]) : "";
			}
			$datas[] = $tempDatas;
		}
		return $datas;
	}
	
	private function sendCommand($cmd)
	{
		fputs($this->_socket, "$cmd\n");
		$response = "";
		do
		{
			$response .= fread($this->_socket, 8096);
		}while(strpos($response, 'error id=') === false);
		
		if(strpos($response, "error id=0") === false)
		{
			throw new Exception("TS3 Server returned the following error: " . $this->unescape(trim($response)));
		}
		
		return $response;
	}
	
	private function queryServer()
	{
		$this->_socket = @fsockopen($this->_host, $this->_qport, $errno, $errstr, $this->timeout);
		if($this->_socket)
		{
			@socket_set_timeout($this->_socket, $this->timeout);
			$isTs3 = trim(fgets($this->_socket)) == "TS3";
			if(!$isTs3) throw new Exception("Not a Teamspeak 3 server/bad query port");

			$response = "";  
			$response .= $this->sendCommand("use sid=" . $this->_sid);
			$this->sendCommand("clientupdate client_nickname=HLSTATSX:CE-TS3-Viewer");
			$response .= $this->sendCommand("serverinfo");
			$response .= $this->sendCommand("channellist -topic -flags -voice -limits");
			$response .= $this->sendCommand("clientlist -uid -away -voice -groups");

			$this->disconnect();
			
			if($this->decodeUTF8) $response = utf8_decode($response);
			return $response;
			
		}
		else throw new Exception("Socket error: $errstr [$errno]");
	}
	
	private function disconnect()
	{
		@fputs($this->_socket, "quit\n");
		@fclose($this->_socket);
	}
	
	private function sortUsers($a, $b)
	{
		return strcasecmp($a["client_nickname"], $b["client_nickname"]);
	}
	
	private function update()
	{
		$response = $this->queryServer();
		
		$lines = explode("error id=0 msg=ok\n\r", $response);
		if(count($lines) == 5)
		{
			$this->_serverDatas = $this->parseLine($lines[1]);
			$this->_serverDatas = $this->_serverDatas[0];

			$this->_channelDatas = $this->parseLine($lines[2]);
			$this->_userDatas = $this->parseLine($lines[3]);
			usort($this->_userDatas, array($this, "sortUsers"));

			$this->_updated = true;
		}
		else $this->error = "Invalid server response";
		
	}
	
	private function renderFlags($flags)
	{
		$out = "";
		foreach ($flags as $flag) $out .= '<img src="' . $this->imagePath . $flag . '" />';
		return $out;
	}

	private function renderUsers($parentId)
	{
		$out = "";
		foreach($this->_userDatas as $user)
		{
			if($user["client_type"] == 0 && $user["cid"] == $parentId)
			{
				$icon = "16x16_player_off.png";
				if($user["client_away"] == 1) $icon = "16x16_away.png";
				else if($user["client_flag_talking"] == 1) $icon = "16x16_player_on.png";
				else if($user["client_output_hardware"] == 0) $icon = "16x16_hardware_output_muted.png";
				else if($user["client_output_muted"] == 1) $icon = "16x16_output_muted.png";
				else if($user["client_input_hardware"] == 0) $icon = "16x16_hardware_input_muted.png";
				else if($user["client_input_muted"] == 1) $icon = "16x16_input_muted.png";
				
				$flags = array();
				if(isset($this->_channelGroupFlags[$user["client_channel_group_id"]])) $flags[] = $this->_channelGroupFlags[$user["client_channel_group_id"]];
				$serverGroups = explode(",", $user["client_servergroups"]);
				foreach ($serverGroups as $serverGroup) if(isset($this->_serverGroupFlags[$serverGroup])) $flags[] = $this->_serverGroupFlags[$serverGroup];
				
				$out .= '
				<div class="tsstatusItem">
					<div class="tsstatusLabel">
						<img src="' . $this->imagePath . $icon . '" />' . $user["client_nickname"] . '
					</div>
					<div class="tsstatusFlags">
						' . $this->renderFlags($flags) . '
					</div>
				</div>';
			}
		}
		return $out;
	}
	private function renderChannels($parentId)
	{
		$out = "";
	

		foreach ($this->_channelDatas as $channel)
		{
			if($channel["pid"] == $parentId)
			{
				$icon = "16x16_channel_green.png";
				if( $channel["channel_maxclients"] > -1 && ($channel["total_clients"] >= $channel["channel_maxclients"])) $icon = "16x16_channel_red.png";
				else if( $channel["channel_maxfamilyclients"] > -1 && ($channel["total_clients_family"] >= $channel["channel_maxfamilyclients"])) $icon = "16x16_channel_red.png";
				else if($channel["channel_flag_password"] == 1) $icon = "16x16_channel_yellow.png";
				
				$flags = array();
				if($channel["channel_flag_default"] == 1) $flags[] = '16x16_default.png';
				if($channel["channel_needed_talk_power"] > 0) $flags[] = '16x16_moderated.png';
				if($channel["channel_flag_password"] == 1) $flags[] = '16x16_register.png';

				$link = "javascript:tsstatusconnect('" . $this->_host . "','" . $this->_serverDatas["virtualserver_port"] . "','" . htmlentities($channel["channel_name"]) . "')";
				$link;
				$nametest = substr( $channel["channel_name"] ,0,7);
        if( $nametest == "[spacer" || $nametest == "[cspace" || $nametest == "[lspace" || $nametest == "[rspace" || $nametest == "[*space" )
				  {
				  $searchpatterns = array ("#\[spacer.]#siU","#\[spacer]#siU","#\[Spacer.]#siU","#\[Spacer]#siU","#\[.spacer.]#siU","#\[.spacer]#siU","#\[.Spacer.]#siU","#\[.Spacer]#siU");
          $channel_name = preg_replace ($searchpatterns, null, $channel["channel_name"]);
          $label = 'tsstatusLabell';
          if ($nametest == "[lspace") $label = 'tsstatusLabell';
          if ($nametest == "[rspace") $label = 'tsstatusLabelr';
          if ($nametest == "[cspace") $label = 'tsstatusLabel" align="center';
          
          $out .= '
				<div class="tsstatusItem">
        <div class="'.$label.'">
        <a href="#link">' . $channel_name . '
						</a>
					</div>
					<div class="tsstatusFlags">
						' . $this->renderFlags($flags) . '
					</div>
					' . (count($this->_userDatas) > 0 ? $this->renderUsers($channel["cid"]) : '') . $this->renderChannels($channel["cid"]) . '
				</div>';
          }
				else				
					{ 
				$out .= '
				<div class="tsstatusItem">
					<div class="tsstatusLabel">
						<a href="#link">
							<img src="' . $this->imagePath . $icon . '" />' . $channel["channel_name"] . '
						</a>
					</div>
					<div class="tsstatusFlags">
						' . $this->renderFlags($flags) . '
					</div>
					' . (count($this->_userDatas) > 0 ? $this->renderUsers($channel["cid"]) : '') . $this->renderChannels($channel["cid"]) . '
				</div>';
        	}
			}
		}
		return $out;
	}
	
	public function clearServerGroupFlags()
	{
		$this->_serverGroupFlags = array();
	}
	
	public function setServerGroupFlag($serverGroupId, $image)
	{
		$this->_serverGroupFlags[$serverGroupId] = $image;
	}
	
	public function clearChannelGroupFlags()
	{
		$this->_channelGroupFlags = array();
	}
	
	public function setChannelGroupFlag($channelGroupId, $image)
	{
		$this->_channelGroupFlags[$channelGroupId] = $image;
	}
	
	public function ts3name()
	{
	$this->update();
	return $this->_serverDatas["virtualserver_name"];
	}
	
	public function ts3_port()
	{
  return $this->_serverDatas["virtualserver_port"];
  }
  
  public function channelcount()
  {
  return count($this->_channelDatas);
  }
  
  public function usage_chann()
  {      
  return ($this->_serverDatas["virtualserver_clientsonline"] - 1) ." / ".$this->_serverDatas["virtualserver_maxclients"];
  }
  
  public function serverdata()
  {
  return $this->_serverDatas;
  }
  public function info($uip)
  {
  $ts3_data = $this->_serverDatas; 		
  $html = "<tr class=\"bg1\"><td id=\"contentMainFirst\" style=\"border:0\" class=\"fHeading\">Server:</td></tr>\n";
	$html .= "<tr class=\"bg1\"><td id=\"contentMainFirst\" style=\"border:0\">".$ts3_data['virtualserver_name']."<br /><br /></td></tr>\n";
	$html .= "<tr class=\"bg1\"><td id=\"contentMainFirst\" style=\"border:0\" class=\"fHeading\">Server IP:</td></tr>\n";
	$html .= "<tr class=\"bg1\"><td id=\"contentMainFirst\" style=\"border:0\">$uip:".$ts3_data['virtualserver_port']."<br /><br /></td></tr>\n";
	$html .= "<tr class=\"bg1\"><td id=\"contentMainFirst\" style=\"border:0\" class=\"fHeading\">Version:</td></tr>\n";
	$html .= "<tr class=\"bg1\"><td id=\"contentMainFirst\" style=\"border:0\">".$ts3_data['virtualserver_version']."<br /><br /></td></tr>\n";
	$html .= "<tr class=\"bg1\"><td id=\"contentMainFirst\" style=\"border:0\" class=\"fHeading\">Plattform:</td></tr>\n";
	$html .= "<tr class=\"bg1\"><td id=\"contentMainFirst\" style=\"border:0\">".$ts3_data['virtualserver_platform']."<br /><br /></td></tr>\n";
	$html .= "<tr class=\"bg1\"><td id=\"contentMainFirst\" style=\"border:0\" class=\"fHeading\">Welcome Message:</td></tr>\n";
	$html .= "<tr class=\"bg1\"><td id=\"contentMainFirst\" style=\"border:0\">".$ts3_data['virtualserver_welcomemessage']."<br /><br /></td></tr>";
  return $html;
  }
    
  public function userstats()
  {
  $udata = $this->_userDatas;
  usort($udata, array($this, "sortUsers"));
      for($i=0;$i<=(count($udata)-1);$i++)
    {
         
      $class = ($color % 2) ? "bg2" : "bg1"; $color++;
      $cdata = $this->_channelDatas;
      $cid = $udata[$i]['cid'];
    foreach ( $cdata as $channel)
		{
		if( $channel["cid"] == $cid )
		{
		$searchpatterns = array ("#\[spacer.]#siU","#\[spacer]#siU","#\[Spacer.]#siU","#\[Spacer]#siU","#\[.spacer.]#siU","#\[.spacer]#siU","#\[.Spacer.]#siU","#\[.Spacer]#siU");
    $channeld = preg_replace ($searchpatterns, null, $channel['channel_name']);
		}
		}
				$icon = "16x16_player_off.png";
				if($udata[$i]["client_away"] == 1) $icon = "16x16_away.png";
				else if($udata[$i]["client_flag_talking"] == 1) $icon = "16x16_player_on.png";
				else if($udata[$i]["client_output_hardware"] == 0) $icon = "16x16_hardware_output_muted.png";
				else if($udata[$i]["client_output_muted"] == 1) $icon = "16x16_output_muted.png";
				else if($udata[$i]["client_input_hardware"] == 0) $icon = "16x16_hardware_input_muted.png";
				else if($udata[$i]["client_input_muted"] == 1) $icon = "16x16_input_muted.png";
		$player = '<img src="' . $this->imagePath . $icon . '" /> '. $udata[$i]['client_nickname'];

    if ( $udata[$i]['client_nickname'] != 'HLSTATSX:CE-TS3-Viewer' )
    { 
      $userstats .= show("/userstats3", array("player" => $player,
                                              "channel" => $channeld,
                                              "misc1" => $udata[$i]['cid'],
                                              "class" => $class,
                                              "misc2" => '',
                                              "misc3" => time_convert(),
                                              "misc4" => time_convert()  ));
    }
	  }
    //echo "<pre>";
		//print_r($udata);
		//echo "</pre>";
    return $userstats;
  }
  
	public function render()
	{
	$this->update();
	$data = $this->_channelDatas;
		try
		{
			$out = '<div class="tsstatus">' . "\n";

		
			if ($this->showNicknameBox) $out .= $this->renderNickNameBox();
			$out .= '<div class="tsstatusServerName"><a href="ts3server://'. $this->_host .'?port='.$this->_serverDatas["virtualserver_port"].'&nickname=WebGuest"><img src="' . $this->imagePath . '16x16_server_green.png" />' . $this->_serverDatas["virtualserver_name"] . "</a></div>\n";
			if(count($this->_channelDatas) > 0) $out .= $this->renderChannels(0);
			$out .= "</div>\n";
		}
		catch (Exception $ex)
		{
			$this->disconnect();
			$out = '<div class="tsstatuserror">' . $ex->getMessage() . '</div>';
		}
		return $out;		
	}
	  public function disconn()
	{
		@fputs($this->_socket, "quit\n");
		@fclose($this->_socket);
	}
}

?>
<?php 

class GaTracker
{
    /**
     * Constructor of the GaTracker Class  
     * 
     * Main class that handles the Google Analytics calls
     */  
    function __construct($gaAccount, $isExternal, $cookieNumber, $debugMode=false) {
        // input validation
        if ($gaAccount == "")
        {
            die("No GA-Account configured");
        }
        $this->GaAccount = $gaAccount;
        $this->Debug = $debugMode;
        if ($this->Debug)
        {
            header("DebugMode: On");
        }
                
        $this->IsExternalUrl = $isExternal;
        $this->RequestedUrl = $_SERVER["REQUEST_URI"];
        $this->RequestedFullUrl = "http://".$_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"];
        $this->CookieNumber = $cookieNumber;
        
        $this->UtmCc = "";
        $this->IsEvent = false;
        $this->EventData = "";
        $this->PageTitle = "(not set)";
        
        $this->ClientIp = $_SERVER["REMOTE_ADDR"];
        $this->ServerName = $this->GetServerName();
        $this->Referer = $this->GetValueOrEmptyTag($_SERVER['HTTP_REFERER']);
    }
    
    /**
     * Sets the metadata for an event tracking. If this method is called, the 
     * it will cause this call to GA to be of type Event and not page view.
     *       
     * @param string $eventCategory
     * @param string $eventAction
     * @param string $eventLabel
     * @param string $eventValue
     */
    function SetEventData($eventCategory, $eventAction = "", $eventLabel = "", $eventValue = "") {
        if ($eventCategory == "")
        {
            return;
        }

        $this->IsEvent = true;
        
        $this->EventCategory = rawurlencode($eventCategory);
        $this->EventAction = rawurlencode($eventAction);
        $this->EventLabel = rawurlencode($eventLabel);
        $this->EventValue = rawurlencode($eventValue);        
        
        $eventString = "5(" . $this->EventCategory . "*" . $this->EventAction;
        if ($this->EventLabel != "")
            $eventString .= "*" . $this->EventLabel . ")";
        else
            $eventString .= ")";

        if ($this->EventValue != "")
            $eventString .= "(" . $this->EventValue . ")";
        $this->EventData = $eventString;
    }
    
    /**
     * Sets any campaign data that needs to be tracked. 
     * 
     * @param string $campaignName   : optional defaults to "(direct)"
     * @param string $campaignSource : optional defaults to "(direct)'
     */
    function SetCampaignData($campaignName = "(direct)", $campaignSource = "(direct)") {
        // double check the values for the campaign and source
        if ($campaignName == "") {
            $campaignName = "(direct)";
        }    
        if ($campaignSource == "") {
            $campaignSource = "(direct)";
        }
        $this->CampaignName= $campaignName;
        $this->CampaignSource= $campaignSource;
        
        // now update the cookie
        $this->UtmCc = $this->GetCookie();
    }
    
    function SetPageTitle($pageTitle) {
        $this->PageTitle = $pageTitle;
    }
    
    /**
     * Stores this pageview/event in a MySQL database
     * 
     * @param string $server
     * @param string $user
     * @param string $password
     * @param string $database
     * @param string $table
     * @param string $realPath
     */    
    function StoreInDatabase($server, $user, $password, $database, $table, $realPath) {
        /* Saves info in a MySQL database: 
        CREATE TABLE IF NOT EXISTS `<tableName>` (
          `date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          `ip` varchar(15) CHARACTER SET utf8 NOT NULL,
          `host` tinytext NOT NULL,
          `uri_from` tinytext NOT NULL,
          `uri_to` tinytext NOT NULL,
          KEY `ip` (`ip`)
        ) ENGINE=MyISAM DEFAULT CHARSET=latin1; 
        */
        
        if ($server == "")
        {
            //die("No SQL Configured"); // Return if no DB is configured.
            return;
        }
        
        if ($this->Debug)
        {
            header("RealPath: ".$realPath);
            return;
        }
               
        // Let's use Prepared SQL statements
        // create the connection and SQL statement
        $connectionString = "mysql:host=localhost;dbname=$database";
        $db = new PDO($connectionString, $user, $password);
        $sql = "INSERT INTO $table SET uri_to=:uri_to, uri_from=:uri_from, ip=:ip, host=:host";
        $preparedStatement = $db->prepare($sql);
        
        // now we set the variables to use
        $sqlParameters = array(':uri_to' => $realPath
                , ':uri_from' => $this->RequestedFullUrl
                , ':ip' => $this->GetAnonIp($this->ClientIp)
                , ':host' => gethostbyaddr($this->ClientIp)
                );
        // execute it

        $preparedStatement->execute($sqlParameters);
        header("Saved: yes");
    }
    
    /**
     * Actually notifies the GA Tracker over HTTP
     * 
     * @param string $useHttps (defaults to false) 
     */
    function NotifyGoogle($useHttps = false) {
        // Make a tracking request to Google Analytics from this server.
        // Copies the headers from the original request to the new one.
        // If request containg utmdebug parameter, exceptions encountered
        // communicating with Google Analytics are thown.
                   
        // get the url
        $utmUrl = $this->GetGoogleUrl($useHttps);
        if ($this->Debug)
        {
            header("utmUrl: ".$utmUrl);            
        }
        
        $options = array(
          ($useHttps ? "https" : "http") => array(
              "method" => "GET"
              , "user_agent" => $_SERVER["HTTP_USER_AGENT"]
              , "header" => ("Accepts-Language: " . $_SERVER["HTTP_ACCEPT_LANGUAGE"])
              #, "proxy" => "tcp://localhost:8888"
            )
        );
        # the @ returns the errors
        #$data = @file_get_contents($utmUrl, false, stream_context_create($options));
        $data = file_get_contents($utmUrl, false, stream_context_create($options));
        header("GoogleAnalysticsTracker: ".$this->GaAccount." -> ".strlen($data));
        return $data;
    }
    
    /**
     * Gets an anonymized IP address
     * 
     * param string $remoteIpAddress
     */
    private function GetAnonIp($remoteIpAddress) {
        if (empty($remoteIpAddress)) {
            return "";
        }
        return $remoteIpAddress;
        
        // Capture the first three octects of the IP address and replace the forth
        // with 0, e.g. 124.455.3.123 becomes 124.455.3.0
        $regex = "/^([^.]+\.[^.]+\.[^.]+\.).*/";
        if (preg_match($regex, $remoteIpAddress, $matches)) {
          return $matches[1] . "0";
        } else {
          return "";
        }
    }    
    
    /**
     * Gets the current Servername
     *  
     * @return string
     */
    private function GetServerName() {
        return $_SERVER["SERVER_NAME"];
    }
   
    /**
     * Gets a string value, or "-" if the string was empty
     * 
     * @param string $value
     * @return string
     */
    private function GetValueOrEmptyTag($value) {
        // return the value if filled, else "-"
        
        if (empty($value))
            return "-";
        else
            return $value;
    }
    
    /**
     * Creates an unique visitors ID
     * 
     * @return string
     */
    private function CreateVisitorId() {
        // Generate a visitor id for this hit.
        $number = rand(1000000000, 0x7fffffff);
        $message = uniqid($number, true);
        $md5String = md5($message);
        return "0x" . substr($md5String, 0, 16);
    }
    
    /**
     * Create an GA __utm.gif url
     * 
     * @param bool $useHttps
     * @return string
     */
    private function GetGoogleUrl($useHttps) {
        #utmwv    Tracking code version     utmwv=1
        #utmn        Unique ID generated for each GIF request to prevent caching of the GIF image.     utmn=1142651215
        #utmhn        Host Name, which is a URL-encoded string.     utmhn=x343.gmodules.com
        #utmr        Referral, complete URL.     utmr=http://www.example.com/aboutUs/index.php?var=selected
        #utmp        Page request of the current page.     utmp=/testDirectory/myPage.html
        #utme       Event data
        #utmac      Account String. Appears on all requests.      utmac=UA-2202604-2
        #utmvid     =0x58ec4a05942947b8 Visitor ID
        #utmip      ="        . getIP($_SERVER["REMOTE_ADDR"]);
        #utmcc        Cookie values. This request parameter sends all the cookies requested from the page. utmcc=__utma%3D117243.1695285.22%3B%2B __utmz%3D117945243.1202416366.21.10. utmcsr%3Db%7C utmccn%3D(referral)%7C utmcmd%3Dreferral%7C utmcct%3D%252Fissue%3B%2B
        #utmcs        Language encoding for the browser. Some browsers don't set this, in which case it is set to "-"        utmcs=ISO-8859-1
        #utmul        Browser language.     utmul=pt-br
        
        if ($this->IsEvent) {
            $googleInfo = array(
                "utmwv"     => "4.4sh" 
                , "utmn"    => rand(1000000000,9999999999)
                , "utmhn"   => $this->ServerName
                , "utmr"    => $this->Referer
                , "utmp"    => $this->RequestedUrl
                , "utmdt"   => $this->PageTitle
                , "utme"    => $this->EventData
                , "utmt"    => "event"
                , "utmac"   => $this->GaAccount
                #, "utmvid" => CreateVisitorId()
                , "utmip"   => $this->ClientIp
                ,"utmcc"    => $this->UtmCc
                );
        }
        else {
            $googleInfo = array(
                "utmwv"     => "4.4sh" 
                , "utmn"    => rand(1000000000,9999999999)
                , "utmhn"   => $this->ServerName
                , "utmr"    => $this->Referer
                , "utmp"    => $this->RequestedUrl
                , "utmdt"   => $this->PageTitle
                , "utmac"   => $this->GaAccount
                #, "utmvid" => CreateVisitorId()
                , "utmip"   => $this->ClientIp
                ,"utmcc"    => $this->UtmCc
                );
        }
        
        // encode
        foreach($googleInfo as $k => $v)
        {
            $paramsURI[] =     (strcmp($k,'utmcc') == 0 || strcmp($k,'utme') == 0) ? urlencode($k).'='.$v : urlencode($k).'='.urlencode($v);
        }
        $paramsURI = implode('&',$paramsURI);
        if ($useHttps) {
        	$googleUrl = 'https://www.google-analytics.com/__utm.gif?'.$paramsURI;
        } else {
        	$googleUrl = 'http://www.google-analytics.com/__utm.gif?'.$paramsURI;
        }
        
        return $googleUrl;
    }
    
    /**
     * Gets the UTMCC cookie value
     * 
     * @return string
     */
    private function GetCookie() {
        // creates a Google compatible cookie
        if ($this->CookieNumber == "")
        {
            die("No cookieNumber defined in.");
        }
        
        // set some values
        $randomNumber = rand(1000000000, 0x7fffffff);
        $timeNow = time();
        
        /* UTMZ cookie contains Campaign data:     
            utmcsr  = campaign source
            utmcmd  = campaign medium
            utmctr  = campaign term (keyword)
            utmcct  = campaign content (used for A/B testing)
            utmccn  = campaign name
            utmgclid = unique identifier used when AdWords auto tagging is enabled    */
        
        $cookieUTMZ = array(
          'utmccn' => $this->CampaignName,
          'utmcsr' => $this->CampaignSource,
          'utmcmd' => '(none)'
        );
        
        foreach($cookieUTMZ as $k => $v) {
            $cookieUTMZstr[] = urlencode($k.'=').$v;
        }
        
        $cookieUTMZstr = implode(urlencode('|'), $cookieUTMZstr);
        
        #__utma=<domain hash>.<unique visitor id>.<timstamp of firstvisit>.<timestamp of previous (most recent) visit>.<timestamp ofcurrent visit>.<visit count>
        $cookieSettings = array(
          '__utma' => $this->CookieNumber.'.'.$randomNumber.'.'.$timeNow.'.'.$timeNow.'.'.$timeNow.'.2;',
          #'__utmb' => $cookieNumber.';',
          #'__utmc' => $cookieNumber.';',
          '__utmz' => $this->CookieNumber.'.'.$timeNow.'.1.2.'.$cookieUTMZstr.urlencode(';'),
          #'__utmv' => $cookieNumber.'.'.$usrVars.';' DISABLED. Set $userVars to variables you want to track.
        );
        
        foreach($cookieSettings as $k => $v)
        {
            $cookieURIstr[] = (strcmp($k,'__utmz') == 0) ?  urlencode($k.'=').$v : urlencode($k.'='.$v);
        }
        $cookie = implode(urlencode('+'), $cookieURIstr);
        return $cookie;
    }
}

?>
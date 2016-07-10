<?php

require_once dirname(__FILE__).'/lib/redbean/rb.php';

use RedBeanPHP\RedBean_Instance as RedBean_instance;


/*
 *	c_eMQ_Mailer -- Default mailer class for c_eMQ --- use c_eMQ's set_mailer() method to use your own!
 */
class c_eMQ_Mailer
{
    public function SendMail($to,$from,$subject,$text)
    {
	$header="FROM: ".$from."\r\nReply-To: ".$from."\r\nX-Mailer: eMQ-Mail-v0.1";

	if(DEVELOPING||TESTING)c_eMQ::__MQ_logFile("mail(".$to.",".$subject.",".$text.",".$header.");");

	if(DEVELOPING)// don't send e-mails in dev!
	    return "mail(".$to.",".$subject.",".$text.",".$header.");";
	else
	    return @mail($to,$subject,$text,$header);
    }
}

/*
 *	c_eMQ -- class to queue or send emails from hosts with time-based email quotas (i.e. a limit of 200 emails / hour etc.)
 *
 *	to use: instantiate and use the addMail() method to send or queue mails
 *		run cron_script.php as a cron job every minute to keep sending mails when possible
 */
class c_eMQ
{
    private $RB;

    private $DB_SETUP_SQLITE_FILE_SSTRING;
    private $DB_SETUP_STRING_SQLITE;
    private $DB_MQ="0_EMAILQUEUE";
    private $DB_MQ_SETUP_STRING;

    private $SENDLIMIT_PER_HOUR; // How many e-mails are we allowed to send per hour?
    private $SENDLIMIT_PER_TEN_MINS;

    private $SENDER_ADDRESS;

    private $MAILER;

    public function __construct($limit=180,$sender="do-not-reply@eMQ.nothing",$defaultMailer=true)
    {
	$this->set_sendLimit($limit);

	$this->DB_SETUP_SQLITE_FILE_SSTRING=realpath(dirname(__FILE__))."/SQLite/%s_DB.db";
	$this->DB_SETUP_STRING_SQLITE="sqlite:".$this->DB_SETUP_SQLITE_FILE_SSTRING;
	$this->DB_MQ_SETUP_STRING=sprintf($this->DB_SETUP_STRING_SQLITE,$this->DB_MQ);

	if($defaultMailer)
	    $this->set_mailer(new c_eMQ_Mailer());

	$this->set_sender($sender);

	$this->RB=new RedBean_Instance();
	$this->RB->setup($this->DB_MQ_SETUP_STRING,"eMQ","eMQ");
    }

    public function set_sendLimit($limit=180)
    {
	$this->SENDLIMIT_PER_HOUR=$limit;
	$this->SENDLIMIT_PER_TEN_MINS=$limit/6;
    }

    public function get_sendLimit()
    {
	return $this->SENDLIMIT_PER_HOUR;
    }

    public function set_mailer($mailer)
    {
	$this->MAILER=$mailer;
    }

    public function set_sender($sender)
    {
	$this->SENDER_ADDRES=$sender;
    }

    public function get_sender()
    {
	return $this->SENDER_ADDRESS;
    }

/*
 *	you should really only need to use addMail() and queueMail() directly
 */


    /*
     *		@importance<=1 will be sent immediately, if below quota -- otherwise queue
     *		@importance>1 will be queued; upon send, order is determined by this value (the smaller, the higher up in the queue)
     */
    public function addMail($email,$subject,$message,$importance)
    {
	$sent=false;
	if($importance<=1) // try sending immediately if it's important
	{
	    $this->__MQ_logFile("MQ_addMail: Sending message directly! (".$email.")");
	    $sent=$this->sendMail($email,$subject,$message,$importance);
	}

	if(!$sent) // otherwise, queue it
	{
	    $this->__MQ_logFile("MQ_addMail: Adding message to queue! (".$email.")");
	    $this->queueMail($email,$subject,$message,$importance);
	}
    }

    /*
     *		add mail to the queue
     */
    public function queueMail($email,$subject,$message,$importance)
    {
	$bean=$this->RB->dispense('queuedmail');
	$bean->recipient=$email;
	$bean->subject=$subject;
	$bean->message=$message;
	$bean->importance=$importance;
	$bean->timequeued=time();

	$this->RB->begin();
	try{
	    $this->RB->store($bean);
	    $this->RB->commit();
	}
	catch(Exception $e)
	{
	    $this->RB->rollback();
	    $this->__MQ_logFile("Error R::store() in MQ_queMail! :");
	    $this->__MQ_logFile($bean);
	}

	return $bean;
    }

    /*
     *		Send e-mail right away, and update hourly count info etc. - unless we're at quota, then queue the mail and return false
     */
    public function sendMail($email,$subject,$message,$importance)
    {
	$status=$this->RB->findOrCreate('status');

	$curtime=time();

	$ret=false;
	$hourtotal=$status->hourTotal;
	if($hourtotal<$this->SENDLIMIT_PER_HOUR)
	{
	    $ret=$this->__SendMail($email,$subject,$message);
	    if($ret==true)
	    {
		$bean=$this->RB->dispense('sentmail');
		$bean->recipient=$email;
		$bean->subject=$subject;
		$bean->message=$message;
		$bean->importance=$importance;
		$bean->timequeued=$curtime;
		$bean->timesent=$curtime;
		$this->RB->begin();
		try{
		    $this->RB->store($bean);
		    $this->RB->commit();
		}
		catch(Exception $e)
		{
		    $this->RB->rollback();
		    $this->__MQ_logFile("Error R::store() in MQ_sendMail! :");
		    $this->__MQ_logFile($bean);
		}
	    }
	    else
	    {
		$bean=$this->queueMail($email,$subject,$message,$importance);
		$bean->failed=1;
		$this->RB->store($bean);
		$ret=false;
		$status->hourTotalFailed++;
	    }
	    $status->hourTotal++;
	    $this->RB->store($status);

    	    $logbean=$this->RB->dispense('log');
    	    $logbean->sendTime=$curtime;
    	    $logbean->sendNumberTotal=1;
    	    $logbean->sendNumberFailed=($ret==true?0:1);
    	    $this->RB->store($logbean);
	}

	return $ret;
    }

    /*
     *		run this regularly via cron etc. to send out queued mails
     */
    public function sendBatch()
    {
        $status=$this->RB->findOrCreate('status');

        $curtime=time();
	$lasttime=(int)$status->lastSendTime;
        $lasthour=(int)$status->lastFullHour;

	if(!$lasthour || (($lasthour+(60+10)*60-1) < $curtime)) // runs hourly... actually every 70 minutes
	{
	    if($status->hourTotal>0) // only keep a record if anything was sent!
	    {
		$logbean=$this->RB->dispense('log');
	        $logbean->hourStart=$status->lastFullHour;
		$logbean->hourEnd=$curtime;
		$logbean->hourSent=$status->hourTotal;
		$logbean->hourFailed=$status->hourTotalFailed;
		$this->RB->store($logbean);
	    }
	    $status->lastFullHour=$curtime;
	    $status->hourTotal=0;
	    $status->hourTotalFailed=0;
	    $this->RB->store($status);
	}

	if(!$lasttime || (($lasttime+60*10-1)<$curtime)) // runs every 10 mins
	{
	    $remaining=$this->SENDLIMIT_PER_HOUR-$status->hourTotal;
	    if($remaining > $this->SENDLIMIT_PER_TEN_MINS)
		$max=$this->SENDLIMIT_PER_TEN_MINS;
	    else
		$max=$remaining;

	    $max=floor($max);

	    if($max<=0)
	    {
		$beans=array(); // simulate empty result if we're over the hourly quota!
	    }
	    else
		$beans=$this->RB->findAll('queuedmail',' ORDER BY importance, timequeued LIMIT ? ',[$max]);

	    $total=0;
	    $failed=0;
	    $success=0;
	    foreach($beans as $bean)
	    {
		if($this->__SendMail($bean["recipient"],$bean["subject"],$bean["message"]))
		{
		    $oldbean=$this->RB->dispense('sentmail');
		    $oldbean->recipient=$bean->recipient;
		    $oldbean->subject=$bean->subject;
		    $oldbean->message=$bean->message;
		    $oldbean->importance=$bean->importance;
		    $oldbean->timequeued=$bean->timequeued;
		    $oldbean->timesent=time();
		    $this->RB->store($oldbean);
		    $this->RB->trash($bean);
		    $success++;
		}
		else
		{
		    $bean->failed=$bean->failed+1;
		    $this->RB->store($bean);
		    $failed++;
		}
		$total++;
	    }

	    if($total>0)
	    {
    		$status->lastSendTime=$curtime;
    		$status->lastSendNumberTotal=$total;
    		$status->lastSendNumberFailed=$failed;
    		$status->hourTotal+=$total;
    		$status->hourTotalFailed+=$failed;
		$this->RB->store($status);
		$logbean=$this->RB->dispense('log');
		$logbean->sendTime=$curtime;
		$logbean->sendNumberTotal=$total;
		$logbean->sendNumberFailed=$failed;
		$this->RB->store($logbean);
		return "Sent: ".$total." (".$failed." failed)";
	    }
	    else
	    {
		return"No e-Mails sent";
	    }
	}
	else
	    return"No e-Mails sent (too early)";
    }

    public static function __MQ_logFile($str)
    {
	$info = substr($_SERVER["SCRIPT_NAME"],strlen(dirname($_SERVER["SCRIPT_NAME"])."/"))."[".date('Y-m-d H:i:s')."] ";
	$f=fopen(realpath(dirname(__FILE__))."/log/log.log","a");
	if(is_array($str))
	    $str=json_encode($str);
	fwrite($f,$info.$str."\n");
	fclose($f);
    }

    /*
     *		send out email via default or custom mailer
     */
    private function __SendMail($to,$subject,$text)
    {
	if(is_object($this->MAILER))
	{
    	    $from=$this->SENDER_ADDRESS;
	    return $this->MAILER->SendMail($to,$from,$subject,$text);
	}
	else
	{
	    $this->__MQ_logFile("No Mailer set!");
	    return false;
	}
    }

}

/*
if(!defined("DEVELOPING"))
{
    c_eMQ::__MQ_logFile("'DEVELOPING' NOT DEFINED -- setting to 'true' - Note: no e-mails will be sent if using default mailer!!!");
    define("DEVELOPING",true);
}
*/
?>
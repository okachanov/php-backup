<?php

	$aDbconfig		= include(dirname(__FILE__).'/config/db.config.php');
	$aMailConfig	= include(dirname(__FILE__).'/config/mail.config.php');

	$sHost			= $aDbconfig['DatabaseHost'];
	$sDatbase		= $aDbconfig['DatabaseName'];
	$sUser			= $aDbconfig['DatabaseUser'];
	$sPassword		= $aDbconfig['DatabasePassword'];
	
	$sDestanation	= "/";

	include('Backup.class.php');
	$oBackupJob	= new BackupJob($sHost, $sDatbase, $sUser, $sPassword);
	$oBackupJob->SetMailParams($aMailConfig);
	$oBackupJob->sBackupMethod = "mail";
	
	$bResult	= $oBackupJob->PerformBackup();
	
	if($bResult){
		print "ok";
	}else{
		$aErrors = $oBackupJob->GetErrors();
		print "<pre>";
		print_r($aErrors);
		print "<pre>";
	}

?>